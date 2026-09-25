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

from langchain_core.language_models import BaseChatModel
from langchain_core.messages import SystemMessage, HumanMessage
from langchain_anthropic import ChatAnthropic
from langchain_deepseek import ChatDeepSeek
from langchain_google_genai import ChatGoogleGenerativeAI
from langchain_openai import ChatOpenAI

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

# ⚠️ OS VALORES DE `impacto` NO SCHEMA ABAIXO SÃO EXEMPLOS DIFERENCIADOS, E ISSO
# É DELIBERADO. Eles eram `{"popularidade": 0, "recursos": 0}` nas três
# alternativas, e o efeito foi medido em 2026-09-04: **24 de 24** dilemas no banco
# — todos gerados pelo Gemini — saíram com os quatro valores em ZERO. O modelo
# copiava o exemplo literalmente.
#
# A faixa −10/+10 existia apenas como `description` de campo Pydantic
# (`models.py:67,71`), e description NÃO CHEGA AO MODELO: o projeto extrai JSON de
# texto e não usa structured output (é a FLAG 001). Ou seja, a única declaração da
# faixa vivia num lugar que o LLM nunca lê.
#
# A instrução em texto logo depois do schema é o conserto principal; trocar os
# zeros por valores diferenciados é o que impede que a cópia literal volte a
# zerar. Validado com Gemini e DeepSeek — ver o commit desta mudança.
#
# ⚠️ Se algum dia estes exemplos voltarem a ser iguais entre si, ou uniformes, o
# defeito volta: o modelo copia o que vê.
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
      "impacto": {"popularidade": -3, "recursos": 5}
    },
    {
      "letra": "B",
      "texto": "Texto começando com verbo no presente (máx 90 chars)",
      "consequencia": "Projeção da consequência realista",
      "impacto": {"popularidade": 7, "recursos": -4}
    },
    {
      "letra": "C",
      "texto": "Texto começando com verbo no presente (máx 90 chars)",
      "consequencia": "Projeção da consequência realista",
      "impacto": {"popularidade": 2, "recursos": 1}
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

FAIXA NUMÉRICA OBRIGATÓRIA — "popularidade" e "recursos":
Os valores de "popularidade" e "recursos" em cada "impacto" DEVEM ser números inteiros estritamente entre -10 e 10. NUNCA use escala de 0 a 100, porcentagem, nem qualquer valor fora dessa faixa.
Os números do schema acima são exemplos de FORMA — não os copie. Cada alternativa recebe valores próprios, positivos ou negativos, coerentes com o trade-off que ela representa: a escolha que ganha audiência e custa dinheiro tem "popularidade" positiva e "recursos" negativa, e vice-versa. Zerar os dois valores só se justifica quando a alternativa é de fato neutra nas duas dimensões.

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


# ── Classificação de erro de provedor — compartilhada ──────────────
#
# Mora aqui, e não em main.py, porque TRÊS módulos precisam dela: as rotas de
# cascata (main), a geração (este arquivo) e a regeneração (regenerator). O
# `main` já importa `generator`, então não há ciclo de import. No passo 5a isto
# nasceu dentro de main.py e serviu a um consumidor só; o 5b o tornou
# compartilhado, e cópia em dois lugares é a família do C13.
#
# ⚠️ ESTES MARCADORES SÃO A RESERVA, NÃO O CAMINHO PRINCIPAL — desde
# 2026-09-04. O caminho principal é o código HTTP (ver `_status_http()` abaixo);
# a substring só entra quando o provedor NÃO expõe status, que hoje é o caso de
# um só: o Gemini.
#
# A previsão escrita aqui na fase 0 foi medida e confirmada com o DeepSeek:
#   · DeepSeek, chave inválida  -> OpenAIAuthenticationError, status_code=401,
#     "Authentication Fails, Your api key: ****xxxx is invalid"
#   · DeepSeek, sem saldo       -> openai.APIStatusError, status_code=402,
#     "Insufficient Balance"
# NENHUMA das duas casava com os marcadores de texto — a ordem das palavras do
# DeepSeek ("api key: … is invalid") não é a do Gemini ("api key not valid"), e
# "Insufficient Balance" não tem nada de "prepayment"/"credits are depleted".
# Por isso a classificação passou a ser por status: 401 e 402 são HTTP padrão e
# valem para qualquer provedor, enquanto o texto é idiossincrasia de cada um.
#
# Sem "401"/"403" na lista de substrings: são números curtos demais e
# apareceriam por coincidência em mensagens que não são de autenticação. Quem
# tem status usa o status; quem não tem, usa as frases.
MARCADORES_CHAVE = (
    "api key not valid",
    "api_key_invalid",
    "invalid api key",
    "incorrect api key",
    "invalid authentication",
    "unauthenticated",
    "permission denied",
    "permission_denied",
)

# Crédito esgotado. Já era tratado como fatal no laço de geração (bare `raise`),
# mas o `raise` cru virava 500 "Erro interno" na rota — agora vira 502 com causa.
MARCADORES_CREDITO = ("prepayment", "credits are depleted", "billing")


# Códigos HTTP que significam "retentar não resolve". São padrão e não dependem
# de provedor: 401 credencial recusada, 403 credencial sem permissão, 402
# pagamento exigido (saldo/crédito), 404 recurso inexistente — na prática o
# modelo configurado, que não existe ou foi retirado do ar (FLAG 016, caso 2).
STATUS_CHAVE = (401, 403)
STATUS_CREDITO = (402,)
STATUS_CONFIG = (404,)


def _status_http(exc: Exception) -> int | None:
    """
    Código HTTP quando o SDK do provedor o expõe NO PRÓPRIO objeto de exceção.

    Devolve None para provedores que não expõem — hoje só o Gemini, e é o que
    manda a classificação cair nos marcadores de texto.

    ⚠️ NÃO PERCORRE `__cause__`, DE PROPÓSITO. O SDK do Gemini embrulha um
    `InvalidArgument` do gRPC cujo `.code()` é **400** — e 400 não distingue
    "chave inválida" de "payload malformado". Descer no encadeamento traria um
    número inútil e tiraria o Gemini do caminho de texto, que é o único que
    funciona para ele. Medido em 2026-09-04.

    ⚠️ `.code` NÃO é consultado: nas exceções da família OpenAI ele é string
    ("invalid_request_error") e no gRPC é método. Só `status_code`/`http_status`
    inteiros, mais o `response.status_code` que o httpx aninha.
    """
    for attr in ("status_code", "http_status"):
        valor = getattr(exc, attr, None)
        if isinstance(valor, int):
            return valor

    resposta = getattr(exc, "response", None)
    valor = getattr(resposta, "status_code", None)
    return valor if isinstance(valor, int) else None


def _status_por_code(exc: Exception) -> int | None:
    """
    Código HTTP lido de `.code` quando ele é INTEIRO — o caso das exceções do
    `google.api_core` que o wrapper do Gemini deixa passar sem embrulhar
    (`PermissionDenied.code` = 403, `NotFound.code` = 404, como `HTTPStatus`,
    que é `IntEnum`). Medido em 2026-09-25.

    O `isinstance(int)` é o que torna seguro ler `.code` sem importar SDK: na
    família OpenAI ele é string ("invalid_api_key") e no gRPC é método — os
    dois são recusados aqui, que era o motivo de `_status_http()` não o ler.

    ⚠️ USO RESTRITO A CASOS POSITIVOS. Diferente de `_status_http()`, este
    código NÃO decide sozinho: só acrescenta "é fatal" quando cai num código
    fatal (401/402/403/404 — na prática o Gemini só produz 401, 403 e 404 por
    aqui), e em qualquer outro valor a classificação segue para os marcadores de
    texto como antes. Se decidisse, um 429 do Gemini com "prepayment" na
    mensagem — crédito esgotado, hoje fatal pelo texto — passaria a ser
    retentado como rate limit.
    """
    valor = getattr(exc, "code", None)
    return int(valor) if isinstance(valor, int) else None


class ErroDeProvedor(Exception):
    """
    O provedor RECUSOU a chamada — chave rejeitada, crédito esgotado ou
    recurso inexistente (404, em geral o modelo configurado).

    Existe para separar duas coisas que os laços de retentativa tratavam como
    uma só: "não consegui gerar um dilema válido" (que é falha de geração, e
    merece o 422 e o retry) e "o provedor não me atendeu" (que não melhora com
    retry e não é culpa do dilema).

    Carrega `detail` pronto para virar corpo de resposta HTTP. Quem traduz para
    502 são as rotas em main.py; este módulo não conhece HTTP.
    """

    def __init__(self, detail: str):
        self.detail = detail
        super().__init__(detail)


def erro_de_chave(exc: Exception) -> bool:
    """
    True quando o provedor recusou a CREDENCIAL.

    Status primeiro (401/403), texto como reserva. Quando o provedor informa
    status, ele DECIDE: um 429 não vira erro de chave só porque a mensagem
    menciona "api key".
    """
    status = _status_http(exc)
    if status is not None:
        return status in STATUS_CHAVE

    # Gemini: 403 PERMISSION_DENIED (ex.: API_KEY_SERVICE_BLOCKED) chega com o
    # status em `.code` e sem marcador de texto que case — FLAG 016, caso 1.
    if _status_por_code(exc) in STATUS_CHAVE:
        return True

    texto = str(exc).lower()
    return any(m in texto for m in MARCADORES_CHAVE)


def erro_fatal_de_provedor(exc: Exception) -> bool:
    """
    True quando retentar é inútil: credencial recusada ou crédito esgotado.

    Status primeiro (401/403/402), texto como reserva.

    NÃO inclui rate limit (429/quota), que continua no caminho de sleep+retry,
    nem erro de rede transitório — este último segue caindo no `break` que
    devolve None e faz a rota responder 422, porque num lote de 15 slots pular
    um slot é melhor que abortar o lote inteiro. Escopo estreito de propósito:
    trata "o provedor recusou", e não constrói taxonomia de erro.

    ⚠️ Consequência de o status ter precedência: num provedor que informa
    status, uma mensagem que mencione "billing" com status 500 deixa de ser
    fatal. É o preço de não decidir por substring quando existe informação
    melhor — e resolve o falso positivo do marcador "billing", largo demais.
    """
    status = _status_http(exc)
    if status is not None:
        return status in STATUS_CHAVE + STATUS_CREDITO + STATUS_CONFIG

    if _status_por_code(exc) in STATUS_CHAVE + STATUS_CREDITO + STATUS_CONFIG:
        return True

    texto = str(exc).lower()
    return erro_de_chave(exc) or any(m in texto for m in MARCADORES_CREDITO)


def detail_de_provedor(exc: Exception, provedor: str) -> str:
    """
    Mensagem para o editor. Nunca traceback — a decisão do C2/FLAG 003.

    ⚠️ `provedor` É OBRIGATÓRIO E NÃO TEM DEFAULT, de propósito. Com um
    `provedor=PROVEDOR_DEFAULT`, um chamador futuro que esquecesse de passá-lo
    diria "GEMINI_API_KEY" **em silêncio** — que é exatamente o defeito que este
    parâmetro veio corrigir (a mensagem citava a variável do Gemini mesmo quando
    a chamada tinha ido para o DeepSeek). Obrigatório, esquecer dá `TypeError`
    visível. Mesmo critério de `normalizar_provedor()`, que recusa cair para
    default em vez de escolher por conta.

    `PROVEDORES` é definido mais abaixo neste módulo; a referência resolve em
    tempo de chamada, não de definição.
    """
    if erro_de_chave(exc):
        # `.get()` e não `PROVEDORES[...]`: esta função roda DENTRO de um
        # `except`, e um KeyError aqui substituiria a causa real por um erro de
        # programação. Slug desconhecido só chega por bug de chamador — e nesse
        # caso não citamos variável NENHUMA, em vez de citar a errada. Citar a
        # errada foi o que abriu este item.
        meta = PROVEDORES.get(provedor)
        if meta is None:
            return (
                "O provedor de IA recusou a chave de API. Verifique a chave em "
                "News-Game › Configurações."
            )
        return (
            "O provedor %s recusou a chave de API. Verifique a chave dele em "
            "News-Game › Configurações — ou, se o backend usa a própria, a "
            "variável %s do servidor." % (meta["rotulo"], meta["env_chave"])
        )

    status = _status_http(exc)
    texto = str(exc).lower()

    if status in STATUS_CONFIG or (status is None and _status_por_code(exc) in STATUS_CONFIG):
        # Não é falha de geração nem de credencial: é configuração. O texto do
        # provedor vai junto porque costuma nomear o modelo e o substituto.
        meta = PROVEDORES.get(provedor)
        onde = (
            " — confira a variável %s do servidor" % meta["campo_modelo"].upper()
            if meta else ""
        )
        return (
            "O provedor de IA não encontrou o recurso pedido (HTTP 404) — em geral "
            "o modelo configurado não existe ou foi retirado do ar%s. Nenhuma "
            "retentativa resolve isso. Resposta do provedor: %s"
            % (onde, str(exc)[:200])
        )

    if status in STATUS_CREDITO or (status is None and any(m in texto for m in MARCADORES_CREDITO)):
        return (
            "O provedor de IA recusou a chamada por falta de crédito na conta "
            "da chave de API. Nenhuma retentativa resolve isso."
        )

    # Quota estourada, timeout e indisponibilidade caem aqui: mensagem truncada,
    # não traceback, porque o editor precisa de pista.
    return (
        f"Falha na chamada ao provedor de IA ({type(exc).__name__}): "
        f"{str(exc)[:200]}"
    )


# ── Registro de provedores de LLM — LUGAR ÚNICO da lista fechada ────
#
# Quem valida "este provedor existe?" (main.py, na borda da requisição) e quem
# constrói o cliente (o _get_llm, abaixo) leem esta MESMA tabela. É a promessa
# escrita no comentário de `llm_provider` em config.py.
#
# ⚠️ POR QUE AQUI, E NÃO EM config.py — a pergunta é legítima, porque as duas
# primeiras colunas de cada linha são NOMES DE CAMPO do Settings e pareceriam
# pertencer a ele. O motivo é o que a tabela ganha no passo 4: cada provedor
# tem especificidade de CONSTRUTOR DE SDK (`google_api_key=` no Gemini contra
# `api_key=` no DeepSeek, mais o `extra_body` que desliga o modo thinking).
# Isso é conhecimento de SDK, e generator.py é o ÚNICO módulo do backend que
# importa SDK de provedor — config.py é folha e não deve passar a importar.
# Partir a tabela em duas, os nomes de campo lá e o construtor aqui, é
# exatamente o que criaria as duas cópias que divergem em silêncio quando um
# provedor entra. Uma tabela, no arquivo que pode conhecer as duas coisas.
#
# ACRESCENTAR PROVEDOR — a lista abaixo foi CORRIGIDA no B8 (2026-09-14), ao
# ser conferida contra a realidade da execução. A versão anterior listava só os
# três primeiros itens, que são de fato os que quebram FUNCIONALMENTE ("o
# provedor fica selecionável e não funciona"), mas omitia quatro que a
# integração exige de todo jeito. São SETE:
#
#   BACKEND
#   1. uma linha nesta tabela, a coluna `construtor` inclusive;
#   2. o parâmetro de header nas 6 rotas de main.py, MAIS a linha no mapa
#      `headers_de_chave` de `_resolve_api_key()` — sem ela o `raise` 500 de lá
#      denuncia, o que é proposital;
#   3. os dois campos de Settings em config.py (`<slug>_api_key` e
#      `llm_model_<slug>`). ⚠️ O campo da chave é PRÉ-REQUISITO de pôr a env
#      var no .env: o pydantic-settings usa extra='forbid' e o servidor RECUSA
#      SUBIR com chave órfã no arquivo — ver a nota daquele campo;
#   3b. as linhas correspondentes no `.env.example`. ACRESCENTADO NO B9
#      (2026-09-15) porque a omissão foi encontrada na prática: o B8 seguiu
#      esta lista e deixou o arquivo defasado — sem `OPENAI_API_KEY` e sem
#      `LLM_MODEL_OPENAI` —, e o B9 teve de fechar os dois de passagem, já que
#      acrescentar só o quarto provedor criaria a falsidade NOVA de sugerir que
#      o terceiro não existe. Não vira item 8: é a mesma unidade de trabalho do
#      item 3, e separar faria a lista crescer sem ganho;
#   4. o campo `<slug>_api_key` nos 5 Requests de models.py;
#   5. o pin do pacote do SDK em requirements.txt, se for novo;
#
#   PLUGIN
#   6. a whitelist `PROVEDORES` de class-newsgame-settings.php (com
#      `option_chave` e `header_chave`), o `register_setting()` da option nova
#      e a entrada em `OPTIONS_AUTOLOAD_OFF`;
#   7. a linha do campo da chave em admin/views/page-settings.php.
#
# NÃO precisa ser tocado, e é o que a fase B do H1 comprou: `provider_headers()`,
# `provider_key()`, os quatro `wp_remote_post`, o `<select>` da tela (que é loop
# sobre a whitelist), `normalizar_provedor()`, `_get_llm()`, `_resolve_provedor()`
# e `detail_de_provedor()` — todos leem a tabela.
PROVEDOR_DEFAULT = "gemini"


def _cliente_gemini(modelo: str, temperatura: float, api_key: str) -> BaseChatModel:
    # `google_api_key=` — é a ÚNICA diferença de assinatura em relação ao
    # DeepSeek, medida na POC de 2026-09-04. O resto da forma de chamada
    # (`model=`, `temperature=`, `max_retries=`) é idêntico, e é o que permitiu
    # o pipeline de geração rodar sem ajuste com o segundo provedor.
    #
    # ⚠️ `thinking_budget=0` DESLIGA O RACIOCÍNIO, e é o análogo do `extra_body`
    # do DeepSeek, do `reasoning_effort="none"` da OpenAI e do
    # `thinking={"type": "disabled"}` do Claude. Entrou em 2026-09-16, junto da
    # troca para `gemini-3.6-flash`: o `gemini-2.5-flash` anterior não tinha o
    # modo, então esta linha nunca havia sido necessária.
    #
    # Sem ela o campo nasce `None`, o `thinking_config` NEM É ENVIADO
    # (`langchain_google_genai/chat_models.py:1717-1733`) e quem decide é o
    # default do SERVIDOR — que na linha 3 é LIGADO. É a armadilha dos dois
    # defaults empilhados que `_cliente_claude()` já descreve.
    #
    # MEDIDO em 2026-09-16, mesma instância e mesmo prompt, mudando só este
    # parâmetro — `usage_metadata` lido da resposta real:
    #   sem o parâmetro : 2.339 tokens de raciocínio (84,9% da saída), 56 s
    #   com `=0`        : 0 tokens de raciocínio, 4 s
    # Saída caiu de 2.754 para 448 tokens com MAIS texto visível (1.552 contra
    # 1.464 chars). Os 56 s eram 47% do teto de 120 s do `wp_remote_post`, e um
    # lote de 15 slots levava ~12 min.
    #
    # ⚠️⚠️ ESTE É O ÚNICO DOS QUATRO CONSTRUTORES QUE USA CAMINHO DE
    # COMPATIBILIDADE RETROATIVA, e a ressalva não existe nos outros três.
    # A doc oficial (ai.google.dev/gemini-api/docs/generate-content/thinking —
    # consultada na fonte, não no wheel) diz que `thinking_budget` é "aceito por
    # compatibilidade retroativa" nos modelos Gemini 3, com ressalva de
    # "performance inesperada" declarada para o **3 Pro** e NÃO para o Flash.
    # Testado no `gemini-3.6-flash`: zero erro, raciocínio zerado, dilema válido
    # na primeira tentativa.
    #
    # ⚠️ O parâmetro que o Google RECOMENDA para a linha 3 é `thinking_level`,
    # não este. A `langchain-google-genai` 4.4.0 marca `thinking_budget` como
    # *deprecated para Gemini 3+*, e a versão instalada aqui (2.1.12) NÃO
    # CONHECE `thinking_level` — nem o wrapper nem o proto
    # (`google-ai-generativelanguage 0.12.0`, cujo `ThinkingConfig` tem só
    # `include_thoughts` e `thinking_budget`). Ou seja, hoje não há alternativa
    # expressável.
    #
    # ⚠️ O MODO DE FALHA É SILENCIOSO: se o Google remover a
    # retrocompatibilidade, o parâmetro passa a ser ignorado, o raciocínio volta
    # ao default LIGADO e não há erro, aviso ou log — só a latência subindo de
    # volta para ~56 s. Mesma categoria do "nunca confiar no default".
    #
    # ⚠️ QUANDO/SE O UPGRADE DA FLAG 012 ACONTECER, REVISAR ESTA LINHA para
    # `thinking_level="minimal"` (na 4.4.0 é o campo `reasoning_effort` com
    # alias `thinking_level`, `Literal["minimal","low","medium","high"]` —
    # `_common.py`/`chat_models.py:3230`). Confirmado suportado no
    # `gemini-3.6-flash` pela tabela oficial, o que NÃO vale para todo modelo
    # 3.x. Atenção: o piso lá é `minimal`, que REDUZ e não zera — então a troca
    # precisa ser remedida, não assumida equivalente.
    return ChatGoogleGenerativeAI(
        model=modelo,
        temperature=temperatura,
        google_api_key=api_key,
        max_retries=2,
        thinking_budget=0,
    )


def _cliente_deepseek(modelo: str, temperatura: float, api_key: str) -> BaseChatModel:
    # ⚠️ `extra_body` DESLIGA O MODO THINKING, e não é ajuste fino: com
    # thinking ligado a calibração de 2026-09-04 mediu 96 s e até 12.322 tokens
    # de saída (95% deles `reasoning`), contra 4,6 s e 480 tokens desligado —
    # 21× mais rápido. O teto de 120 s do `wp_remote_post` do plugin torna o
    # modo thinking inviável na prática, não apenas caro.
    return ChatDeepSeek(
        model=modelo,
        temperature=temperatura,
        api_key=api_key,
        max_retries=2,
        extra_body={"thinking": {"type": "disabled"}},
    )


def _cliente_openai(modelo: str, temperatura: float, api_key: str) -> BaseChatModel:
    # `api_key=` — mesma forma do DeepSeek (é alias de `openai_api_key` no
    # ChatOpenAI), e `model=` é alias de `model_name`. Conferido no
    # `model_fields` da langchain-openai 0.3.35 em 2026-09-14, não suposto.
    #
    # ⚠️ `reasoning_effort="none"` É O ANÁLOGO DO `extra_body` DO DEEPSEEK, e é
    # EXPLÍCITO de propósito — não confiar no default do modelo. Os defaults
    # divergem por família: na 5.4 (`mini`/`nano`) o default já é `none`, mas na
    # 5.6 (`luna`/`terra`/`sol`) é `medium`. Sem esta linha, trocar
    # `llm_model_openai` de `gpt-5.4-nano` para `gpt-5.6-luna` — mesma faixa de
    # preço, troca de aparência inócua — LIGARIA O RACIOCÍNIO EM SILÊNCIO, com a
    # conta e a latência que vêm com ele. Reasoning token é cobrado como saída.
    #
    # Medido em 2026-09-14: com esta linha, `reasoning_tokens = 0` nas seis
    # chamadas do `luna` e do `nano`, incluindo as do `luna`, cujo default é
    # `medium`. A doc dizia que aceita `none`; o `usage` provou que pegou.
    #
    # ⚠️ `gpt-6-astra` NÃO É SUBSTITUÍVEL AQUI sem revisar esta decisão: o
    # `reasoning.effort` dele não aceita `none` (doc de 2026-09-14), ou seja o
    # raciocínio não desliga — e a $50/1M de saída isso sai caríssimo.
    #
    # ⚠️⚠️ `temperatura` É IGNORADA NA FAMÍLIA gpt-5.x, E NÃO É DEFEITO DAQUI.
    # A `langchain-openai` 0.3.35 REMOVE `temperature` do payload quando o nome
    # do modelo é `gpt-5*`, porque aquela API não aceita o parâmetro. Medido em
    # 2026-09-14 inspecionando `_default_params`:
    #   gpt-5.6-luna  -> temperature=None  (removida)
    #   gpt-5.4-nano  -> temperature=None  (removida)
    #   gpt-4o-mini   -> temperature=0.3   (preservada)
    #   gpt-3.5-turbo -> temperature=0.3   (preservada)
    #
    # CONSEQUÊNCIA A CONHECER: o `llm_temperature = 0.3` do projeto vale para o
    # Gemini e o DeepSeek, mas NÃO para a OpenAI com o modelo padrão atual — as
    # chamadas rodam no default da API. Não é divergência a corrigir: forçar o
    # parâmetro faria a API recusar. A calibração de 2026-09-14 foi medida
    # justamente nessas condições, então os números valem para o que roda.
    #
    # O parâmetro CONTINUA sendo recebido e repassado, de propósito: é o
    # contrato da coluna `construtor` da tabela, e volta a ter efeito se algum
    # dia `llm_model_openai` apontar para um modelo que o aceite.
    return ChatOpenAI(
        model=modelo,
        temperature=temperatura,
        api_key=api_key,
        max_retries=2,
        reasoning_effort="none",
    )


def _cliente_claude(modelo: str, temperatura: float, api_key: str) -> BaseChatModel:
    # `api_key=` e `model=` — mesma forma do DeepSeek e da OpenAI. Conferido no
    # `model_fields` da langchain-anthropic 0.3.22 em 2026-09-15, não suposto:
    # o campo se chama `anthropic_api_key` e tem `alias="api_key"`, e a classe
    # roda com `populate_by_name=True`, então os dois nomes funcionam. `model` é
    # alias de `model_name`. O Gemini segue sendo o único fora do padrão.
    #
    # ⚠️⚠️ `temperature=None` SEMPRE, E O PARÂMETRO RECEBIDO É IGNORADO. Isto é
    # DECISÃO MEDIDA, não omissão: em 2026-09-15, `temperature=0.3` devolveu
    # **HTTP 400** em `claude-sonnet-5` E em `claude-opus-5`, com a mensagem
    # "temperature is deprecated for this model". Não é degradação silenciosa —
    # é a requisição inteira falhando.
    #
    # ⚠️ E A DIFERENÇA PARA A OPENAI É O QUE TORNA ESTA LINHA OBRIGATÓRIA: lá a
    # `langchain-openai` REMOVE `temperature` do payload sozinha quando o modelo
    # é `gpt-5*`, então passar 0.3 é inócuo. Aqui NÃO existe essa limpeza — a
    # `langchain-anthropic` põe `"temperature": self.temperature` no payload e
    # só descarta os `None` (`chat_models.py:1601-1617`, lido em 2026-09-15).
    # Ou seja: o 0.3 CHEGARIA à API e derrubaria toda chamada. O `None` tem de
    # ser nosso.
    #
    # O parâmetro continua sendo RECEBIDO, e não removido da assinatura, pelo
    # mesmo motivo da OpenAI: é o contrato da coluna `construtor` da tabela, e
    # volta a ter efeito se algum dia `llm_model_claude` apontar para um modelo
    # que aceite o parâmetro (a geração 4.6 e o Haiku 4.5 ainda aceitam).
    _ = temperatura  # recebido por contrato da tabela; IGNORADO de propósito
    #
    # ⚠️ `thinking={"type": "disabled"}` É O ANÁLOGO do `extra_body` do DeepSeek
    # e do `reasoning_effort="none"` da OpenAI, e é EXPLÍCITO pela MESMA razão:
    # nunca confiar no default. Aqui o risco é maior que nos outros dois, porque
    # são DOIS defaults empilhados — o campo `thinking` do wrapper nasce `None`,
    # e `None` significa PARÂMETRO OMITIDO (`chat_models.py:1616`), não
    # "desligado"; quem decide então é o default do SERVIDOR, que varia por
    # modelo e em parte da linha atual é LIGADO.
    #
    # Medido em 2026-09-15 com o prompt real do projeto: neste modo o texto veio
    # SEM vazamento de tag, o JSON saiu limpo e `_is_valid_dilema` devolveu True.
    # Ou seja o Claude segue o MESMO padrão dos outros três (thinking sempre
    # desligado), e NÃO o padrão oposto que a leitura prévia levantou como risco
    # — a hipótese de que desligar faria vazar `<thinking>` no corpo da resposta
    # foi testada e NÃO se confirmou no modelo escolhido.
    #
    # ⚠️ Se `llm_model_claude` mudar, ISTO PRECISA SER REMEDIDO: há modelo na
    # linha atual que RECUSA `{"type": "disabled"}` com 400 (o parâmetro não é
    # universalmente aceito), e nesse caso a saída é `{"type": "adaptive"}` com
    # `output_config={"effort": "low"}` via `model_kwargs`.
    #
    # Nota sobre chave vazia: o nosso `_get_llm()` já barra antes de chegar aqui.
    # Vale registrar que, se chegasse, o `""` NÃO abriria a cascata própria do
    # SDK (ANTHROPIC_API_KEY > perfil em disco): `anthropic/_client.py:214-224`
    # testa `api_key is not None`, e `""` conta como credencial explícita. Ver a
    # nota de `claude_api_key` em config.py, que é onde isso importa de verdade.
    return ChatAnthropic(
        model=modelo,
        temperature=None,
        api_key=api_key,
        max_retries=2,
        thinking={"type": "disabled"},
    )


PROVEDORES: dict[str, dict] = {
    "gemini": {
        "rotulo": "Gemini",
        "campo_chave": "gemini_api_key",    # atributo de Settings
        "campo_modelo": "llm_model",        # idem
        "header_chave": "X-Gemini-Api-Key",
        "env_chave": "GEMINI_API_KEY",
        "construtor": _cliente_gemini,
    },
    "deepseek": {
        "rotulo": "DeepSeek",
        "campo_chave": "deepseek_api_key",
        "campo_modelo": "llm_model_deepseek",
        "header_chave": "X-DeepSeek-Api-Key",
        "env_chave": "DEEPSEEK_API_KEY",
        "construtor": _cliente_deepseek,
    },
    "openai": {
        "rotulo": "OpenAI",
        "campo_chave": "openai_api_key",
        "campo_modelo": "llm_model_openai",
        "header_chave": "X-OpenAI-Api-Key",
        "env_chave": "OPENAI_API_KEY",
        "construtor": _cliente_openai,
    },
    # ⚠️ `env_chave` é CLAUDE_API_KEY, e não ANTHROPIC_API_KEY — o segundo nome
    # é consumido pelo SDK da Anthropic e colidiria com a nossa resolução por
    # causa do load_dotenv() de main.py. Ver a nota de `claude_api_key` em
    # config.py. Este valor é o que aparece na mensagem do 502, então dizer o
    # nome errado aqui mandaria o editor editar a variável que não é lida.
    "claude": {
        "rotulo": "Claude",
        "campo_chave": "claude_api_key",
        "campo_modelo": "llm_model_claude",
        "header_chave": "X-Claude-Api-Key",
        "env_chave": "CLAUDE_API_KEY",
        "construtor": _cliente_claude,
    },
}


def normalizar_provedor(valor: str) -> str:
    """
    Valida um slug de provedor contra a lista fechada e o normaliza.

    Aceita variação de caixa e espaço em volta — `" Gemini "` vira `"gemini"`.
    Levanta `ValueError` para valor desconhecido, com a lista válida na
    mensagem; quem chama decide o status HTTP e diz de ONDE veio o valor.

    ⚠️ NÃO cai para o default em caso de valor desconhecido, de propósito.
    Um `LLM_PROVIDER=gemni` no .env ou um header com typo produziria, no
    silêncio, um cliente do Gemini recebendo a chave do DeepSeek — e o sintoma
    seria "o provedor recusou a chave", que aponta para a chave em vez de para
    o nome errado. É a mesma classe de defeito do C13/C14: falhar apontando
    para o lugar errado. A cascata de default é decidida por quem chama, e só
    para valor AUSENTE.
    """
    slug = (valor or "").strip().lower()
    if slug not in PROVEDORES:
        raise ValueError(
            "Provedor de IA desconhecido: %r. Válidos: %s."
            % (valor, ", ".join(sorted(PROVEDORES)))
        )
    return slug


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
# ⚠️ A CHAVE DE CACHE É `(provedor, api_key)`. O `provedor` entrou no passo 4
# da fase B justamente por este motivo: o modelo passou a variar com ele
# (`llm_model` contra `llm_model_deepseek`), e sem o provedor na assinatura
# duas requisições de provedores diferentes com a mesma chave compartilhariam
# o cliente — servindo o modelo errado, em silêncio.
#
# `llm_temperature` e o nome do modelo seguem lidos de dentro e FORA da
# chave. É seguro porque `get_settings()` é cacheado e não muda em runtime. Se
# algum dia o MODELO passar a vir por requisição (escolher `v4-pro` no
# painel, por exemplo), ele TEM de entrar na assinatura pelo mesmo motivo.
#
# `maxsize=8` — eram 4 quando a chave de cache era só a chave de API. Com
# `(provedor, api_key)` o mesmo cenário de várias instâncias WordPress pode
# multiplicar por provedor, então o teto dobrou. No uso real segue sendo uma
# entrada.
@lru_cache(maxsize=8)
def _get_llm(provedor: str, api_key: str) -> BaseChatModel:
    # ⚠️ GUARDA CONTRA CHAVE VAZIA — só alcançável por BUG DE CHAMADOR, e o
    # passo 4 da fase 0 é o que a tornou possível: com `gemini_api_key`
    # opcional em config.py, existe agora instalação sem chave nenhuma no .env.
    #
    # As seis rotas resolvem antes de chegar aqui, e `_resolve_api_key()`
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
            "_resolve_api_key(). Ver fase 0 do H1."
        )

    # ⚠️ MESMA CLASSE DE GUARDA, para o provedor. As rotas normalizam antes de
    # chegar aqui (`normalizar_provedor`), então slug desconhecido é bug de
    # chamador — e um `KeyError` cru na linha seguinte não diria isso.
    if provedor not in PROVEDORES:
        raise ValueError(
            "_get_llm() recebeu provedor desconhecido: %r. Válidos: %s. A rota "
            "chamadora não passou por normalizar_provedor()."
            % (provedor, ", ".join(sorted(PROVEDORES)))
        )

    cfg = get_settings()
    meta = PROVEDORES[provedor]
    # O modelo sai da tabela, e não de um `if provedor == ...`: é `llm_model`
    # para o Gemini, `llm_model_deepseek`, `llm_model_openai` e
    # `llm_model_claude` para os outros três. A enumeração cresce com a tabela;
    # esta linha não.
    modelo = getattr(cfg, meta["campo_modelo"])
    return meta["construtor"](modelo, cfg.llm_temperature, api_key)


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


def generate_one_dilema(
    req: GenerateRequest, api_key: str, provedor: str = PROVEDOR_DEFAULT
) -> DilemaNg | None:
    """
    Gera exatamente 1 dilema de forma síncrona.
    O nível narrativo é determinado 100% por req.nivel_padrao (escolha do painel WP).
    req.slot_index é usado apenas para rotação de personagens nas queries RAG.

    `api_key` chega JÁ RESOLVIDO pela rota (header > body > env). A resolução
    não pode acontecer aqui: o header não existe dentro do Request model.
    """
    cfg = get_settings()
    db  = get_collection(req.site_id)
    llm = _get_llm(provedor, api_key)

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
            # RECUSA DO PROVEDOR (chave rejeitada ou crédito esgotado) não é
            # falha de geração — passo 5b da fase 0. Antes, chave inválida caía
            # no `break` abaixo e a rota respondia 422 "não foi possível gerar
            # um dilema válido": mensagem que culpa o dilema por um problema de
            # credencial. Medido em 2026-09-04: aqui gastava 1 tentativa, e o
            # mesmo erro no regenerator gastava 5.
            #
            # O crédito esgotado já era fatal (bare `raise`), mas o `raise` cru
            # virava 500 "Erro interno" na rota. Agora leva `detail`.
            if erro_fatal_de_provedor(exc):
                logger.warning(
                    "[generator] generate_one slot=%s ABORTADO por recusa do provedor: %r",
                    req.slot_index, exc,
                )
                raise ErroDeProvedor(detail_de_provedor(exc, provedor)) from exc

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

def generate_stream(
    req: GenerateRequest, api_key: str, provedor: str = PROVEDOR_DEFAULT
) -> Generator[str, None, None]:
    """
    Gerador SSE — emite eventos à medida que dilemas são gerados.
    1 chamada LLM por dilema; itera N vezes por lote.

    Formato de cada evento:
      data: {"type": "dilema", "current": N, "total": M, "dilema": {...}}\\n\\n

    `api_key` chega JÁ RESOLVIDO pela rota — ver a nota em generate_one_dilema().
    """
    cfg            = get_settings()
    db             = get_collection(req.site_id)
    llm            = _get_llm(provedor, api_key)
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

                    # RECUSA DO PROVEDOR encerra o stream — passo 5b da fase 0.
                    # Aqui NÃO se levanta exceção: os headers HTTP já foram
                    # enviados e não há como trocar o status. Em vez disso emite
                    # um evento final e retorna.
                    #
                    # Antes, este caminho caía no `yield ... 'error'` com
                    # `str(exc)` cru e dava `break` só no laço INTERNO — com uma
                    # chave inválida, o stream seguia para o próximo lote e
                    # repetia o erro em todos, um por um.
                    if erro_fatal_de_provedor(exc):
                        logger.warning(
                            "[generator] stream ABORTADO por recusa do provedor: %r", exc
                        )
                        yield f"data: {json.dumps({'type': 'error', 'fatal': True, 'msg': detail_de_provedor(exc, provedor)})}\n\n"
                        return

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
