"""
Lógica do endpoint /generate.

Arquitetura:
  - Chamada de Texto Puro ao Gemini com Prompt de Formato JSON Literal.
  - Parser robusto via RegEx (_extract_json_dilema) e tratamento nativo com json.loads.
  - Integração com RAG Estocástico com exclusão dinâmica de fontes_usadas via filtro $nin no ChromaDB.
  - Respeito absoluto ao nível selecionado pelo usuário no painel do WordPress (100% puro).
"""
from __future__ import annotations
import json
import logging
import math
import re
import time
from functools import lru_cache
from typing import Generator

from langchain_core.messages import SystemMessage, HumanMessage
from langchain_google_genai import ChatGoogleGenerativeAI

from app.config import get_settings
from app.models import GenerateRequest, DilemaNg
from app.rag import get_collection, mmr_search

logger = logging.getLogger(__name__)


# ── Prompts do sistema (constantes) ──────────────────────

SYSTEM_PROMPT = """\
Você é um game designer editorial especializado em transformar \
textos jornalísticos e de crítica cultural em dilemas interativos \
no formato JDA (Jogo da Amazônia).

Dado um texto jornalístico ou de crítica cultural, e considerando \
estritamente a 'Diretriz de Identidade' (Descrição do Acervo) \
definida pelo usuário em {theme_prompt}, gere UM dilema no formato \
JDA obedecendo cegamente a estas 6 leis:

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
REGRA 1 — ELEMENTO PERMANENTE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Identifique o elemento permanente do texto: o que é eterno e não \
morre com a data, temporada ou contexto geográfico/financeiro \
específico.

O plano perecível (datas, locais, valores de ingressos, temporadas \
encerradas) deve ser BANIDO da 'situacao' e das 'alternativas', \
servindo apenas como âncora factual no veredito.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
REGRA 2 — EIXO TEMÁTICO POR NÍVEL
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Escolha o eixo temático e a pergunta geradora conforme o nível \
recebido em {nivel}:

- LÚDICO: eixo narrativo/curiosidade.
  Pergunta geradora: O que acontece na história?
  O leitor quer descobrir um fato pop ou anedota marcante.
  Os nomes próprios PODEM aparecer — eles são a isca.

- PEDAGÓGICO: eixo moral/identificação.
  Pergunta geradora: E se fosse com você?
  O leitor se projeta na situação.
  O enunciado DEVE ocultar os nomes próprios da matéria \
para universalizar a escolha.

- COMPLEXO: eixo crítico/estético.
  Pergunta geradora: Que problema isso coloca para a arte \
ou a sociedade?
  O jogador enfrenta um paradoxo ou trade-off sem solução fácil.
  Os nomes próprios PODEM aparecer como referência crítica.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
REGRA 3 — ARQUITETURA DAS ALTERNATIVAS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Monte as três alternativas (A, B, C) seguindo esta arquitetura:

- A: a escolha mais intuitiva, que o senso comum faria \
(a resposta fácil).
- B: a escolha que o texto-fonte revela como a real \
ou a mais sofisticada.
- C: a escolha mais complexa, negociada ou contraditória \
— que abre uma terceira via.

ATENÇÃO: No nível LÚDICO, a posição da alternativa correta \
pode ser rotacionada entre A, B e C a cada geração. \
O veredito deve sempre identificá-la claramente.

⚠️ CAMPO OBRIGATÓRIO — alternativa_correta:
O campo alternativa_correta NUNCA deve ser nulo, vazio ou \
conter texto como "não aplicável". Ele deve conter \
obrigatoriamente a string "A", "B" ou "C", indicando qual \
das opções representa a escolha real/sofisticada baseada \
no texto-fonte (conforme esta Regra 3). \
Isso vale para TODOS os níveis: LÚDICO, PEDAGÓGICO e COMPLEXO.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
REGRA 4 — CONSEQUÊNCIAS COMO PROJEÇÃO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Escreva cada consequência como uma extensão realista do dilema \
(projeção direta), nunca como punição ou recompensa óbvia.

A consequência deve:
- Ter o mesmo tamanho emocional da alternativa
- Apenas deslocar o problema, não resolvê-lo
- Deixar o jogador pensando: "mas mesmo assim eu ainda \
escolheria essa opção"

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
REGRA 5 — ESTRUTURA DO VEREDITO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Monte o veredito em três partes obrigatórias e distintas:

- DESFECHO (campo desfecho_real): o fato verificável e real extraído do texto-fonte.
- LIÇÃO (campo licao): frase de alcance universal e abstrato, \
válida fora do contexto da matéria.
- SÍNTESE (campo info_contextual.sintese): retorno ao texto específico, conectando \
o universal ao particular.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
REGRA 6 — CONTROLE DO RISCO DE FICÇÃO (nível lúdico)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Para o nível LÚDICO:
- As alternativas falsas são hipotéticas plausíveis \
("o que poderia ter acontecido")
- Use verbos no condicional implícito, nunca afirmações \
sobre fatos reais que não ocorreram
- O veredito SEMPRE corrige e revela o fato verdadeiro

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚠️ AVISO CRÍTICO: DIRETRIZ DE ABSTRAÇÃO DE EXEMPLOS (FEW-SHOT)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
<few_shot_instructions>
Os exemplos few-shot abaixo servem EXCLUSIVAMENTE para ilustrar:
- O FORMATO JSON esperado
- O RITMO LITERÁRIO e a estrutura dramática
- O tamanho máximo de cada campo
- A aplicação lógica das regras

Você está TERMINANTEMENTE PROIBIDO de utilizar o conteúdo, o tema ("teatro", "Othon Bastos", "Nelson Rodrigues") ou personagens dos exemplos em sua geração real.
A geração real de dilemas DEVE SER 100% ALIMENTADA pelo texto RAG fornecido no USER_PROMPT (matérias da Amazônia Real). Nenhuma variável de controle ou lógica deve recorrer a esses dados fixos.
</few_shot_instructions>

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
LIMITES OBRIGATÓRIOS DE CAMPOS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- titulo:        máx. 3 palavras, todas em minúsculas
- situacao:      máx. 130 caracteres, termina obrigatoriamente com ':'
- alternativas:  máx. 90 caracteres cada (sem a consequência)
- consequencias: mesmo tamanho emocional da alternativa

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
EXEMPLOS FEW-SHOT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
<few_shot_examples>

>>> EXEMPLO 1 — NÍVEL LÚDICO

DILEMA 002  |  ARTISTA  |  Nível: Lúdico  |  Status: pending
ID banco: 133
TÍTULO [máx. 3 palavras, minúsculas]:
quase foi dentista
SITUAÇÃO [máx. 130 chars — deve terminar com ':']:
Othon Bastos quase não virou ator. Quando jovem, uma professora o aconselhou firmemente a seguir outro caminho:
ALTERNATIVAS [texto máx. 90 chars | verbo no presente do indicativo]:
[A] Torna-se dentista, como a professora recomendou, e abandona os palcos para sempre.
Consequência: O Brasil ganha um dentista e perde Corisco, Deus e o Diabo na Terra do Sol e 74 anos de carreira.
[B] Ignora o conselho e estreia no teatro amador de Salvador ainda adolescente.
Consequência: Descobre que o palco é seu lugar — e nunca mais larga.
[C] Vai para o Rio tentar a sorte no rádio, que era a mídia do momento nos anos 1950.
Consequência: O caminho é tortuoso, mas o talento acaba encontrando a porta certa.
VEREDITO:
Desfecho: A professora Eliete recomendou que o jovem baiano fizesse qualquer coisa menos arte. Ele ignorou — e construiu uma carreira de 74 anos, aos 92 de idade ainda em cartaz.
Lição: Conselhos bem-intencionados nem sempre apontam para o caminho certo.
Síntese: Othon Bastos estreou ignorando quem duvidou dele. Não me entrego, não! é também isso: a história de alguém que teimou em existir no palco.
FONTE: http://localhost/jda/othon-bastos-nao-me-entrego-nao/
alternativa_correta: B


>>> EXEMPLO 2 — NÍVEL PEDAGÓGICO

DILEMA 012  |  TEATRO  |  Nível: Pedagógico  |  Status: pending
ID banco: 135
TÍTULO [máx. 3 palavras, minúsculas]:
honrar ou revelar
SITUAÇÃO [máx. 130 chars — deve terminar com ':']:
Você descobre, após a morte de alguém próximo, um segredo que mudaria completamente a imagem que todos têm dessa pessoa:
ALTERNATIVAS [texto máx. 90 chars | verbo no presente do indicativo]:
[A] Guarda o segredo, preservando a memória e poupando quem ficou da dor.
Consequência: A paz é mantida, mas você carrega sozinho um peso que pode nunca desaparecer.
[B] Revela tudo, porque a verdade — por mais dura — é sempre mais justa que a mentira.
Consequência: A imagem se desfaz, mas quem ficou pode reconstruir a própria vida sobre bases reais.
[C] Usa o segredo como moeda, decidindo o momento e a forma de revelá-lo conforme a situação.
Consequência: Ganha controle temporário — mas em Nelson Rodrigues, quem manipula a verdade raramente sai ileso.
VEREDITO:
Desfecho: Tuninho descobre o relacionamento secreto de Zulmira com Pimentel e escolhe a vingança. Nelson Rodrigues escreveu A Falecida em 1953 — mas a pergunta sobre o que fazer com uma verdade inconveniente não envelheceu um dia.
Lição: Guardar ou revelar um segredo diz menos sobre a verdade em si do que sobre quem você decide ser diante dela.
Síntese: A Falecida mostra que a hipocrisia não está só nos outros — está na escolha que cada um faz quando a verdade bate à porta.
FONTE: http://localhost/jda/a-falecida-sesc-santo-amaro/
alternativa_correta: B


>>> EXEMPLO 3 — NÍVEL COMPLEXO

DILEMA 014  |  ARTISTA  |  Nível: Complexo  |  Status: pending
ID banco: 137
TÍTULO [máx. 3 palavras, minúsculas]:
memória como dramaturgia
SITUAÇÃO [máx. 130 chars — deve terminar com ':']:
Um ator com 74 anos de carreira transforma suas memórias em espetáculo. Mas memória pessoal pode virar arte sem virar autoindulgência:
ALTERNATIVAS [texto máx. 90 chars | verbo no presente do indicativo]:
[A] Organiza a narrativa cronologicamente, deixando a trajetória falar por si mesma.
Consequência: Garante clareza e reconhecimento, mas o formato linear pode reduzir uma vida complexa a um currículo encenado.
[B] Fragmenta a narrativa em blocos temáticos, intercalando humor, política e afeto.
Consequência: Cria uma dramaturgia mais rica e menos previsível — o caminho escolhido por Flávio Marinho em Não me entrego, não!
[C] Convoca uma personagem externa — a própria Memória — para questionar e contrapor o relato do ator.
Consequência: Introduz distância crítica e impede a egolatria, mas exige que o artista aceite ser confrontado em cena por si mesmo.
VEREDITO:
Desfecho: Flávio Marinho combinou B e C: organizou as 600 páginas de escritos de Othon em blocos temáticos e criou a personagem da Memória, interpretada por Juliana Medella, que serve de contraponto crítico ao ator em cena — impedindo que o espetáculo vire hagiografia.
Lição: O espetáculo autobiográfico mais honesto não é o que mais revela — é o que encontra uma forma capaz de questionar o próprio narrador.
Síntese: Não me entrego, não! chegou a 40 mil espectadores e mais de 100 apresentações não por ser um tributo, mas por ser uma pergunta: o que uma vida inteira de ofício tem a dizer sobre o que significa persistir?
FONTE: http://localhost/jda/othon-bastos-nao-me-entrego-nao/
alternativa_correta: B


>>> EXEMPLO 4 — NÍVEL LÚDICO (tema alternativo)

DILEMA 011  |  TEATRO  |  Nível: Lúdico  |  Status: pending
ID banco: 134
TÍTULO [máx. 3 palavras, minúsculas]:
enterro dos sonhos
SITUAÇÃO [máx. 130 chars — deve terminar com ':']:
Zulmira está tuberculosa e tem um único desejo: ser enterrada com luxo e pompa. Mas quem vai pagar essa conta?
ALTERNATIVAS [texto máx. 90 chars | verbo no presente do indicativo]:
[A] O marido Tuninho, humilde e devotado, corre atrás do dinheiro sozinho.
Consequência: Descobre, no caminho, um segredo que vai mudar tudo — e não para melhor.
[B] A família se reúne e faz uma vaquinha para honrar o último desejo da moribunda.
Consequência: A solidariedade familiar resolve o problema, mas Nelson Rodrigues nunca foi tão gentil com a família.
[C] Zulmira convence um rico empresário chamado Pimentel a bancar o funeral.
Consequência: O dinheiro aparece — mas junto com ele vem uma verdade que o marido não estava preparado para saber.
VEREDITO:
Desfecho: Zulmira convence Tuninho a procurar Pimentel. Ao descobrir que a esposa tinha um relacionamento secreto com o patrocinador do funeral, Tuninho decide se vingar da maneira mais pérfida possível.
Lição: Em Nelson Rodrigues, ninguém faz um favor de graça — e todo segredo tem prazo de validade.
Síntese: A Falecida estreou em 1953 e segue atual: a hipocrisia e a falsa moralidade que Nelson radiografou continuam abundantes no Brasil contemporâneo.
FONTE: http://localhost/jda/a-falecida-sesc-santo-amaro/
alternativa_correta: C


>>> EXEMPLO 5 — NÍVEL PEDAGÓGICO (tema alternativo)

DILEMA 012  |  TEATRO  |  Nível: Pedagógico  |  Status: pending
ID banco: 135
TÍTULO [máx. 3 palavras, minúsculas]:
honrar ou revelar
SITUAÇÃO [máx. 130 chars — deve terminar com ':']:
Você descobre, após a morte de alguém próximo, um segredo que mudaria completamente a imagem que todos têm dessa pessoa:
ALTERNATIVAS [texto máx. 90 chars | verbo no presente do indicativo]:
[A] Guarda o segredo, preservando a memória e poupando quem ficou da dor.
Consequência: A paz é mantida, mas você carrega sozinho um peso que pode nunca desaparecer.
[B] Revela tudo, porque a verdade — por mais dura — é sempre mais justa que a mentira.
Consequência: A imagem se desfaz, mas quem ficou pode reconstruir a própria vida sobre bases reais.
[C] Usa o segredo como moeda, decidindo o momento e a forma de revelá-lo conforme a situação.
Consequência: Ganha controle temporário — mas em Nelson Rodrigues, quem manipula a verdade raramente sai ileso.
VEREDITO:
Desfecho: Tuninho descobre o relacionamento secreto de Zulmira com Pimentel e escolhe a vingança. Nelson Rodrigues escreveu A Falecida em 1953 — mas a pergunta sobre o que fazer com uma verdade inconveniente não envelheceu um dia.
Lição: Guardar ou revelar um segredo diz menos sobre a verdade em si do que sobre quem você decide ser diante dela.
Síntese: A Falecida mostra que a hipocrisia não está só nos outros — está na escolha que cada um faz quando a verdade bate à porta.
FONTE: http://localhost/jda/a-falecida-sesc-santo-amaro/
alternativa_correta: B


>>> EXEMPLO 6 — NÍVEL COMPLEXO (tema alternativo)

DILEMA 013  |  TEATRO  |  Nível: Complexo  |  Status: pending
ID banco: 136
TÍTULO [máx. 3 palavras, minúsculas]:
rodrigues contra todos
SITUAÇÃO [máx. 130 chars — deve terminar com ':']:
Uma obra escrita em 1953 é remontada 70 anos depois. O diretor precisa decidir até onde atualizar sem trair o autor:
ALTERNATIVAS [texto máx. 90 chars | verbo no presente do indicativo]:
[A] Mantém fidelidade absoluta ao texto, apostando que Rodrigues já era contemporâneo antes do tempo.
Consequência: Preserva a integridade da obra, mas corre o risco de parecer museu — reverência que sufoca em vez de iluminar.
[B] Atualiza o contexto cenográfico e visual, mantendo o texto intocado.
Consequência: Cria um diálogo produtivo entre épocas, como fez Sergio Módena ao colocar um mausoléu como espaço único da ação.
[C] Reescreve passagens, adaptando linguagem e referências para o público de hoje.
Consequência: Ganha acessibilidade, mas abre uma ferida ética: quem tem autoridade para reescrever Nelson Rodrigues?
VEREDITO:
Desfecho: A montagem de Sergio Módena no Sesc Santo Amaro optou pelo caminho B — cenário de mausoléu como espaço simbólico único, texto preservado. Já a primeira montagem, em 1953 no TBC, foi dirigida por Zé Celso Martinez Corrêa, que trouxe elementos inovadores para retratar o subúrbio carioca, com Fernanda Montenegro no papel de Zulmira.
Lição: Toda remontagem é também um manifesto — sobre o que o presente enxerga no passado e o que decide preservar, transformar ou apagar.
Síntese: A Falecida foi encenada pela primeira vez em 1953 e segue provocando escolhas difíceis a cada nova montagem. Nelson Rodrigues não envelhece — mas cada geração precisa decidir como encará-lo.
FONTE: http://localhost/jda/a-falecida-sesc-santo-amaro/
alternativa_correta: B
</few_shot_examples>

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚠️ FORMATO DA RESPOSTA — REGRA ABSOLUTA
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Sua saída DEVE ser apenas um objeto JSON puro.
NENHUM caractere antes do { inicial ou depois do } final.
TERMINANTEMENTE PROIBIDO: aspas triplas (```json, ```), blocos de \
código markdown ou qualquer texto explicativo fora do objeto JSON.
"""

USER_PROMPT = """\
Texto-fonte:
{texto}

Nível solicitado: {nivel}
Identidade do acervo: {theme_prompt}
URL de origem: {url_fonte}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
SCHEMA DE SAÍDA OBRIGATÓRIO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Responda APENAS com um bloco JSON puro contendo a estrutura exata abaixo (substitua os valores pelo conteúdo gerado, mantendo rigorosamente as chaves).

```json
{
  "titulo": "exemplo titulo curto",
  "situacao": "Texto descritivo terminando em dois pontos:",
  "alternativas": [
    {
      "letra": "A",
      "texto": "Texto começando com verbo no presente (máx 90 chars)",
      "consequencia": "Projeção da consequência realista",
      "impacto": {"popularidade": 0, "recursos": 0}
    },
    {
      "letra": "B",
      "texto": "Texto começando com verbo no presente (máx 90 chars)",
      "consequencia": "Projeção da consequência realista",
      "impacto": {"popularidade": 0, "recursos": 0}
    },
    {
      "letra": "C",
      "texto": "Texto começando com verbo no presente (máx 90 chars)",
      "consequencia": "Projeção da consequência realista",
      "impacto": {"popularidade": 0, "recursos": 0}
    }
  ],
  "alternativa_correta": "B",
  "desfecho_real": "Fato verificável extraído da reportagem.",
  "licao": "Lição de alcance universal e abstrato.",
  "info_contextual": {
    "sintese": "Resumo jornalístico real.",
    "link_original": "{url_fonte}"
  }
}
```

O campo "alternativa_correta" é OBRIGATÓRIO. Deve conter "A", "B" ou "C".
NUNCA retorne qualquer texto antes ou depois do bloco JSON.
"""


# ── Helpers de prompt ─────────────────────────────────────

def _resolve_prompt(template: str, **kwargs) -> str:
    """Substitui {chave} no template por str(valor). Seguro com JSON inside strings."""
    for key, value in kwargs.items():
        template = template.replace(f"{{{key}}}", str(value))
    return template


def _nivel_nome(nivel: int, modo_geral: bool, personagem_tipo: str) -> str:
    if nivel == 2:
        return "COMPLEXO"
    if modo_geral or personagem_tipo == "geral":
        return "PEDAGÓGICO"
    return "LÚDICO"


# ── Validação de dilema pós-Gemini ────────────────────────

def _extract_json_dilema(text: str) -> dict:
    """Extrai e parseia o JSON bruto ignorando blocos de markdown e lixo em volta."""
    text = text.strip()
    # Primeiro tenta remover blocos markdown (com ou sem 'json')
    match = re.search(r'```(?:json)?\s*([\s\S]*?)\s*```', text)
    if match:
        text = match.group(1).strip()
    
    # Busca a primeira ocorrência segura de um objeto JSON global
    match2 = re.search(r'(\{.*\})', text, re.DOTALL)
    if match2:
        text = match2.group(1)
        
    return json.loads(text)

_LETRAS_VALIDAS = {"A", "B", "C"}


def _is_valid_dilema(d: DilemaNg, num_options: int) -> bool:
    """Descarta dilemas com campos críticos vazios ou alternativas insuficientes.
    alternativa_correta NÃO é critério de rejeição: o fallback 'B' já foi
    aplicado antes desta chamada sempre que o campo vier vazio ou inválido."""
    if not d.titulo.strip() or not d.situacao.strip():
        return False
    if len(d.alternativas) < num_options:
        return False
    if not all(a.texto.strip() for a in d.alternativas[:num_options]):
        return False
    return True


def _aplicar_fallback_correta(dilema: DilemaNg, nivel_nome: str) -> None:
    """Garante que alternativa_correta seja sempre A, B ou C.
    Fallback 'B' porque a Regra 3 define B como a escolha real/sofisticada."""
    if dilema.alternativa_correta.strip().upper() not in _LETRAS_VALIDAS:
        logger.warning(
            "[FALLBACK] alternativa_correta=%r inválida (nivel=%s) → aplicando 'B'",
            dilema.alternativa_correta, nivel_nome,
        )
        dilema.alternativa_correta = "B"


# ── Cliente do LLM — ponto único de construção do backend ──────────
#
# Fase 0 do H1: era um singleton global (`_llm`) que lia `cfg.gemini_api_key`
# e servia a MESMA chave a todas as chamadas. Isso é incompatível com chave por
# requisição — a primeira chamada fixava a chave do `.env` e o header de todas
# as seguintes era ignorado, em silêncio.
#
# Agora a chave é PARÂMETRO e o cache é POR CHAVE. Este é o único construtor de
# cliente do backend: `main.py` importa esta função (antes tinha um
# `_make_llm()` próprio, sem cache) e `regenerator.py` também. Um caminho só.
#
# ── POR QUE CACHE, E NÃO CONSTRUIR POR CHAMADA ─────────────────────
# Medido em 2026-09-04, com chave falsa (falha de autenticação, custo zero):
#   · construir o cliente custa  ~1,3 ms  — irrelevante
#   · MAS o TRANSPORTE é reaproveitado dentro do mesmo cliente: chamada em
#     cliente reusado = mediana 152,7 ms; em cliente novo = 218,9 ms.
#     São ~66 ms por chamada, com a forma de handshake TLS (seis amostras de
#     cliente novo entre 217 e 225 ms — não é jitter).
# Sem cache, o laço de `/generate-one` (até 15 slots) pagaria ~1 s extra por
# lote, e as três rotas de cascata — que hoje constroem por chamada — seguiriam
# pagando handshake em toda análise e toda pesagem. O cache corrige as duas.
#
# ── AS TRÊS RESSALVAS DO `lru_cache`, declaradas ───────────────────
#  1. a chave de API entra na chave de cache e fica no processo. Já ficava:
#     o cliente antigo guardava a chave dentro de si desde sempre.
#  2. entrada obsoleta após rotação de chave NÃO expira. É inofensiva —
#     simplesmente deixa de ser usada, e a chave nova cria a sua.
#  3. duas requisições concorrentes com a mesma chave nova podem construir
#     dois clientes. Inofensivo: um vence o slot, os dois funcionam.
#
# ⚠️ A CHAVE DE CACHE É SÓ `api_key`. `llm_model` e `llm_temperature` são
# lidos de dentro e NÃO fazem parte dela — hoje isso é seguro porque
# `get_settings()` é cacheado e não muda em runtime. Se algum dia o modelo
# passar a variar por requisição (é o caminho natural do H1 completo), ele
# TEM de entrar na assinatura, senão o cache serve o modelo errado.
#
# `maxsize=4` porque no uso real é uma entrada; o teto existe para o caso de
# várias instâncias WordPress com chaves distintas apontando para o mesmo
# backend.
@lru_cache(maxsize=4)
def _get_llm(api_key: str) -> ChatGoogleGenerativeAI:
    # ⚠️ GUARDA CONTRA CHAVE VAZIA — só alcançável por BUG DE CHAMADOR, e o
    # passo 4 da fase 0 é o que a tornou possível: com `gemini_api_key`
    # opcional em config.py, existe agora instalação sem chave nenhuma no .env.
    #
    # As seis rotas resolvem antes de chegar aqui, e `_resolve_gemini_key()`
    # levanta 401 quando os três degraus estão vazios. Então quem chegar aqui
    # com "" é chamador NOVO que esqueceu de resolver.
    #
    # Sem esta guarda o sintoma seria "400 API key not valid" devolvido pelo
    # Google depois de uma ida e volta de rede — mensagem que aponta para a
    # chave, e não para a resolução que faltou. Mesma família de defeito que o
    # C13/C14/C15: falhar apontando para o lugar errado. Exceção não é
    # armazenada pelo lru_cache, então isto não envenena o cache.
    if not api_key:
        raise ValueError(
            "_get_llm() recebeu chave vazia: a rota chamadora não passou por "
            "_resolve_gemini_key(). Ver fase 0 do H1."
        )

    cfg = get_settings()
    return ChatGoogleGenerativeAI(
        model=cfg.llm_model,
        temperature=cfg.llm_temperature,
        google_api_key=api_key,
        max_retries=2,
    )


# ── Distribuição de lotes ─────────────────────────────────

def _build_batches(req: GenerateRequest) -> list[dict]:
    """
    Distribui req.density de personagens ou gera um lote único.
    """
    n_chars = len(req.characters)

    if n_chars == 0:
        return [{
            "nivel":                1,
            "nivel_nome":           "PEDAGÓGICO",
            "personagem_tipo":      "geral",
            "personagem_label":     "Geral",
            "personagem_descricao": None,
            "quantidade":           req.density,
            "queries":              [req.theme_prompt, f"perspectiva ampla sobre {req.theme_prompt}"],
            "modo_geral":           True,
        }]

    char_density = math.floor(req.density * 0.8)
    per_char     = max(5, math.ceil(char_density / n_chars))
    geral_qty    = req.density - (per_char * n_chars)
    nivel_geral  = 2 if req.enable_levels else 1
    nome_geral   = _nivel_nome(nivel_geral, False, "geral")

    batches = []
    for char in req.characters:
        queries = [
            f"{char.description} {req.theme_prompt}",
            f"{char.label} {req.theme_prompt}",
            char.description,
            req.theme_prompt,
        ]
        batches.append({
            "nivel":                1,
            "nivel_nome":           "LÚDICO",
            "personagem_tipo":      char.slug,
            "personagem_label":     char.label,
            "personagem_descricao": char.description,
            "quantidade":           per_char,
            "queries":              queries,
            "modo_geral":           False,
        })

    if geral_qty > 0:
        batches.append({
            "nivel":                nivel_geral,
            "nivel_nome":           nome_geral,
            "personagem_tipo":      "geral",
            "personagem_label":     "Geral",
            "personagem_descricao": None,
            "quantidade":           geral_qty,
            "queries":              [req.theme_prompt, f"perspectiva ampla sobre {req.theme_prompt}"],
            "modo_geral":           False,
        })

    return batches


# ── Reforço por nível: injeta o eixo temático explicitamente no user_msg ──
#
# O SYSTEM_PROMPT define as 6 leis globais. Este bloco repete o eixo do
# nível escolhido logo antes da geração, para que o Gemini não precise
# inferir qual regra da REGRA 2 aplicar — ele lê diretamente no user_msg.

_NIVEL_INSTRUCAO: dict[str, str] = {
    "LÚDICO": (
        "⚠️ NÍVEL LÚDICO ATIVO — Eixo narrativo/curiosidade.\n"
        "Pergunta geradora: 'O que aconteceu de verdade?'\n"
        "- Os nomes próprios DEVEM aparecer: eles são a isca dramática.\n"
        "- As alternativas falsas são cenários hipotéticos plausíveis "
        "(use condicional implícito, nunca afirme algo que não ocorreu).\n"
        "- O veredito SEMPRE revela o fato verdadeiro e corrige as falsas.\n"
        "- A posição da alternativa correta PODE rodar entre A, B e C."
    ),
    "PEDAGÓGICO": (
        "⚠️ NÍVEL PEDAGÓGICO ATIVO — Eixo moral/identificação.\n"
        "Pergunta geradora: 'E se fosse com você?'\n"
        "- OBRIGATÓRIO: oculte TODOS os nomes próprios da matéria no enunciado "
        "e nas alternativas. O leitor deve se projetar na situação sem âncora factual.\n"
        "- A situacao deve ser escrita na 2ª ou 3ª pessoa universal "
        "(ex: 'Você descobre…', 'Um gestor precisa decidir…').\n"
        "- As três alternativas representam escolhas morais, não fatos históricos."
    ),
    "COMPLEXO": (
        "⚠️ NÍVEL COMPLEXO ATIVO — Eixo crítico/estético.\n"
        "Pergunta geradora: 'Que problema estrutural isso revela?'\n"
        "- Exponha o paradoxo ou trade-off sistêmico sem solução fácil.\n"
        "- Nomes próprios PODEM aparecer como referência crítica e analítica.\n"
        "- As três alternativas representam posições estéticas, políticas ou "
        "epistemológicas distintas — sem binário certo/errado óbvio.\n"
        "- O veredito deve conectar o caso particular a uma lei mais ampla."
    ),
}


def _build_prompt(
    theme_prompt: str,
    contexto_rag: str,
    nivel_nome: str,
    url_fonte: str,
    num_options: int = 3,
    personagem_label: str | None = None,
    personagem_descricao: str | None = None,
    axis_matrix: dict | None = None,
) -> tuple[str, str]:
    """Retorna (system_msg, user_msg) prontos para [SystemMessage, HumanMessage]."""
    system = _resolve_prompt(SYSTEM_PROMPT, theme_prompt=theme_prompt, nivel=nivel_nome)

    user = _resolve_prompt(
        USER_PROMPT,
        texto=contexto_rag,
        nivel=nivel_nome,
        theme_prompt=theme_prompt,
        url_fonte=url_fonte,
    )

    # Persona (quando houver personagens definidos na instância)
    if personagem_label:
        user += f"\nPersonagem: {personagem_label}"
        if personagem_descricao:
            user += f"\nPerspectiva: {personagem_descricao}"

    # Reforço explícito do eixo temático do nível selecionado no painel WP
    instrucao_nivel = _NIVEL_INSTRUCAO.get(nivel_nome, _NIVEL_INSTRUCAO["PEDAGÓGICO"])
    user += f"\n\n{instrucao_nivel}"

    # Eixos de tensão da instância (quando disponíveis via /analyze-domain)
    if axis_matrix:
        eixos = axis_matrix.get("eixos_principais", {})
        linhas = []
        for k in ("eixo1", "eixo2", "eixo3"):
            e = eixos.get(k)
            if e:
                linhas.append(
                    f"- {e['nome']}: {e['descricao']} "
                    f"(polo positivo: {e['polo_positivo']} | "
                    f"polo negativo: {e['polo_negativo']})"
                )
        if linhas:
            user += (
                "\n\nEIXOS DE TENSÃO DESTA INSTÂNCIA:\n"
                + "\n".join(linhas)
                + "\n\nUse esses eixos para calibrar as alternativas — "
                "cada escolha deve posicionar implicitamente o jogador "
                "em direção a um dos polos. NÃO mencione os nomes dos "
                "eixos no texto da situacao nem das alternativas."
            )

    if num_options == 2:
        user += "\n\nATENÇÃO: Gere apenas 2 alternativas (A e B). Omita a alternativa C."

    return system, user


# ── Geração síncrona unitária (1 dilema por chamada) ─────

_NIVEL_MAP: dict[str, tuple[int, str]] = {
    "ludico":     (1, "LÚDICO"),
    "pedagogico": (1, "PEDAGÓGICO"),
    "complexo":   (2, "COMPLEXO"),
}


def generate_one_dilema(req: GenerateRequest, api_key: str) -> DilemaNg | None:
    """
    Gera exatamente 1 dilema de forma síncrona.
    O nível narrativo é determinado 100% por req.nivel_padrao (escolha do painel WP).
    req.slot_index é usado apenas para rotação de personagens nas queries RAG.

    `api_key` chega JÁ RESOLVIDO pela rota (header > body > env). A resolução
    não pode acontecer aqui: o header não existe dentro do Request model.
    """
    cfg = get_settings()
    db  = get_collection(req.site_id)
    llm = _get_llm(api_key)

    # Nível obedece cegamente ao campo enviado pelo WordPress
    nivel, nivel_nome = _NIVEL_MAP.get(req.nivel_padrao, (1, "PEDAGÓGICO"))

    # Personagem: rotação por slot para diversificar as queries RAG
    n_chars = len(req.characters)
    if n_chars > 0:
        char            = req.characters[req.slot_index % n_chars]
        personagem_tipo = char.slug
        char_label      = char.label
        char_desc       = char.description
        queries         = [
            f"{char.description} {req.theme_prompt}",
            f"{char.label} {req.theme_prompt}",
            char.description,
            req.theme_prompt,
        ]
    else:
        personagem_tipo = "geral"
        char_label      = None
        char_desc       = None
        queries         = [req.theme_prompt, f"perspectiva ampla sobre {req.theme_prompt}"]

    contexto_rag = mmr_search(
        db,
        queries,
        fetch_k=cfg.rag_fetch_k,
        k=cfg.rag_k,
        fontes_usadas=req.fontes_usadas
    )
    if not contexto_rag.strip():
        return None

    urls      = list(dict.fromkeys(re.findall(r'SOURCE_URL:\s*(https?://\S+)', contexto_rag)))
    url_fonte = urls[req.slot_index % len(urls)] if urls else ""

    system, user = _build_prompt(
        theme_prompt=req.theme_prompt,
        contexto_rag=contexto_rag,
        nivel_nome=nivel_nome,
        url_fonte=url_fonte,
        num_options=req.num_options,
        personagem_label=char_label,
        personagem_descricao=char_desc,
        axis_matrix=req.axis_matrix,
    )

    for tentativa in range(1, cfg.max_retries_batch + 1):
        try:
            res = llm.invoke([SystemMessage(system), HumanMessage(user)])

            # --- ADICIONE ESTAS LINHAS DE LIMPEZA LOGO APÓS RECEBER O TEXTO DA IA ---
            response_text = res.content # (Esta linha já existe no seu código)
            
            # Remove aspas triplas de markdown se o Gemini teimar em enviar
            response_text = response_text.replace("```json", "").replace("```", "").strip()
            # ------------------------------------------------------------------------
            
            data_dict = _extract_json_dilema(response_text)
            dilema = DilemaNg(**data_dict)
            _aplicar_fallback_correta(dilema, nivel_nome)
            if req.num_options == 2 and len(dilema.alternativas) > 2:
                dilema.alternativas = dilema.alternativas[:2]
            if not _is_valid_dilema(dilema, req.num_options):
                logger.warning(
                    "[generator] REPROVADO slot=%s tentativa=%s titulo_ok=%s situacao_ok=%s len_alts=%s>=%s",
                    req.slot_index, tentativa,
                    bool(dilema.titulo.strip()), bool(dilema.situacao.strip()),
                    len(dilema.alternativas), req.num_options,
                )
                continue
            dilema.nivel           = nivel
            dilema.nivel_nome      = nivel_nome
            dilema.personagem_tipo = personagem_tipo
            dilema.id_unico        = f"{nivel}-{personagem_tipo}-{req.slot_index + 1:02d}"
            return dilema
        except Exception as exc:
            err = str(exc).lower()
            # "prepayment credits are depleted" — erro fatal, não adianta retry
            if "prepayment" in err or "credits are depleted" in err or "billing" in err:
                raise
            is_rate = any(k in err for k in ["429", "resource_exhausted", "resourceexhausted", "quota", "rate_limit"])
            logger.warning(
                "[generator] generate_one tentativa=%s slot=%s is_rate=%s erro=%r",
                tentativa, req.slot_index, is_rate, exc,
            )
            if is_rate and tentativa < cfg.max_retries_batch:
                time.sleep(cfg.rate_limit_sleep)
            else:
                break

    return None


# ── Stream SSE ────────────────────────────────────────────

def generate_stream(req: GenerateRequest, api_key: str) -> Generator[str, None, None]:
    """
    Gerador SSE — emite eventos à medida que dilemas são gerados.
    1 chamada LLM por dilema; itera N vezes por lote.

    Formato de cada evento:
      data: {"type": "dilema", "current": N, "total": M, "dilema": {...}}\\n\\n

    `api_key` chega JÁ RESOLVIDO pela rota — ver a nota em generate_one_dilema().
    """
    cfg            = get_settings()
    db             = get_collection(req.site_id)
    llm            = _get_llm(api_key)
    batches        = _build_batches(req)
    total_plan     = sum(b["quantidade"] for b in batches)
    generated      = 0
    position       = 0

    yield f"data: {json.dumps({'type': 'start', 'total': total_plan})}\n\n"

    for batch_idx, batch in enumerate(batches):
        nivel            = batch["nivel"]
        nivel_nome       = batch["nivel_nome"]
        personagem_tipo  = batch["personagem_tipo"]
        personagem_label = batch["personagem_label"]
        personagem_desc  = batch["personagem_descricao"]
        quantidade       = batch["quantidade"]
        queries          = batch["queries"]

        contexto_rag = mmr_search(
            db, queries,
            fetch_k=cfg.rag_fetch_k,
            k=cfg.rag_k,
            fontes_usadas=req.fontes_usadas
        )

        if not contexto_rag.strip():
            yield f"data: {json.dumps({'type': 'warning', 'msg': f'Sem contexto RAG para {personagem_tipo}. Acervo pode estar vazio.'})}\n\n"
            continue

        # Extrai e deduplica URLs do acervo para rotação entre dilemas do lote
        urls_disponiveis = list(dict.fromkeys(
            re.findall(r'SOURCE_URL:\s*(https?://\S+)', contexto_rag)
        ))

        char_label = personagem_label if personagem_tipo != "geral" else None
        char_desc  = personagem_desc  if personagem_tipo != "geral" else None

        for i in range(quantidade):
            url_fonte = urls_disponiveis[i % len(urls_disponiveis)] if urls_disponiveis else ""

            system, user = _build_prompt(
                theme_prompt=req.theme_prompt,
                contexto_rag=contexto_rag,
                nivel_nome=nivel_nome,
                url_fonte=url_fonte,
                num_options=req.num_options,
                personagem_label=char_label,
                personagem_descricao=char_desc,
                axis_matrix=req.axis_matrix,
            )

            dilema: DilemaNg | None = None
            for tentativa in range(1, cfg.max_retries_batch + 1):
                try:
                    res = llm.invoke(
                        [SystemMessage(system), HumanMessage(user)]
                    )
                    
                    # --- ADICIONE ESTAS LINHAS DE LIMPEZA LOGO APÓS RECEBER O TEXTO DA IA ---
                    response_text = res.content # (Esta linha já existe no seu código)
                    
                    # Remove aspas triplas de markdown se o Gemini teimar em enviar
                    response_text = response_text.replace("```json", "").replace("```", "").strip()
                    # ------------------------------------------------------------------------
                    
                    data_dict = _extract_json_dilema(response_text)
                    dilema = DilemaNg(**data_dict)
                    break
                except Exception as exc:
                    err    = str(exc).lower()
                    is_rate = any(k in err for k in ["429", "resource_exhausted", "quota", "rate_limit"])
                    logger.warning(
                        "[generator] tentativa=%s batch=%s i=%s personagem=%s erro=%r",
                        tentativa, batch_idx, i, personagem_tipo, exc,
                    )
                    if is_rate and tentativa < cfg.max_retries_batch:
                        yield f"data: {json.dumps({'type': 'rate_limit', 'sleep': cfg.rate_limit_sleep, 'tentativa': tentativa})}\n\n"
                        time.sleep(cfg.rate_limit_sleep)
                    else:
                        yield f"data: {json.dumps({'type': 'error', 'batch': batch_idx, 'personagem': personagem_tipo, 'msg': str(exc)})}\n\n"
                        break

            if dilema is None:
                continue

            # Trunca alternativas extras se o modelo ignorou a instrução de 2 opções
            if req.num_options == 2 and len(dilema.alternativas) > 2:
                dilema.alternativas = dilema.alternativas[:2]

            if not _is_valid_dilema(dilema, req.num_options):
                continue

            # Preenche campos de contexto que o LLM não conhece
            dilema.nivel          = nivel
            dilema.nivel_nome     = nivel_nome
            dilema.personagem_tipo = personagem_tipo
            dilema.id_unico       = f"{nivel}-{personagem_tipo}-{position + 1:02d}"

            position  += 1
            generated += 1
            payload = dilema.model_dump()
            payload["instance_id"] = req.instance_id
            payload["position"]    = position
            yield (
                f"data: {json.dumps({'type': 'dilema', 'current': generated, 'total': total_plan, 'dilema': payload}, ensure_ascii=False)}\n\n"
            )

            if i < quantidade - 1:
                time.sleep(cfg.dilema_sleep)

        if batch_idx < len(batches) - 1:
            time.sleep(cfg.batch_sleep)

    yield f"data: {json.dumps({'type': 'done', 'total_generated': generated})}\n\n"
