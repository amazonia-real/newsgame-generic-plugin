"""
Lógica do endpoint /generate.

Estratégia de distribuição de lotes:
  - 80% dos dilemas distribuídos igualmente entre os personagens definidos
  - 20% restante em um lote "geral" (perspectiva coletiva, sem personagem fixo)
  - Cada lote é uma chamada ao Gemini com structured output (LoteDilemas)
  - SSE stream: a cada dilema gerado, emite evento 'data: {...}\\n\\n'
"""
from __future__ import annotations
import json
import math
import sys
import time
from typing import Generator

from langchain_google_genai import ChatGoogleGenerativeAI

from app.config import get_settings
from app.models import GenerateRequest, LoteDilemas, DilemaNg
from app.rag import get_collection, mmr_search


# ── Validação de dilema pós-Gemini ───────────────────────

def _is_valid_dilema(d: DilemaNg, num_options: int) -> bool:
    """Descarta dilemas com campos críticos vazios ou número errado de alternativas."""
    if not d.titulo.strip() or not d.situacao.strip():
        return False
    if len(d.alternativas) != num_options:
        return False
    if not all(a.texto.strip() for a in d.alternativas):
        return False
    return True


# LLM singleton reutilizado entre chamadas da mesma instância do servidor
_llm: ChatGoogleGenerativeAI | None = None


def _get_llm() -> ChatGoogleGenerativeAI:
    global _llm
    if _llm is None:
        cfg  = get_settings()
        # google_api_key passado explicitamente — ChatGoogleGenerativeAI lê de
        # GOOGLE_API_KEY por padrão, mas nosso .env usa GEMINI_API_KEY.
        _llm = ChatGoogleGenerativeAI(
            model=cfg.llm_model,
            temperature=cfg.llm_temperature,
            google_api_key=cfg.gemini_api_key,
            max_retries=2,
        )
    return _llm


# ── Distribuição de lotes ─────────────────────────────────

def _build_batches(req: GenerateRequest) -> list[dict]:
    """
    Distribui req.density entre personagens + lote geral.

    Modo Geral (sem personagens): lote único com toda a density, foco em jogador genérico.
    Modo Personagem: 80% dividido entre personagens (nivel=1) + 20% lote geral.
      - Se enable_levels=False, o lote geral também recebe nivel=1 (complexidade uniforme).
    """
    n_chars = len(req.characters)

    # ── Modo Geral: sem personagens definidos ──────────────────
    if n_chars == 0:
        return [{
            "nivel":                1,
            "personagem_tipo":      "geral",
            "personagem_label":     "Geral",
            "personagem_descricao": None,
            "quantidade":           req.density,
            "queries":              [req.theme_prompt, f"perspectiva ampla sobre {req.theme_prompt}"],
            "modo_geral":           True,
        }]

    # ── Modo Personagem ────────────────────────────────────────
    char_density = math.floor(req.density * 0.8)
    per_char     = max(5, math.ceil(char_density / n_chars))
    geral_qty    = req.density - (per_char * n_chars)
    nivel_geral  = 2 if req.enable_levels else 1

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
            "personagem_tipo":      "geral",
            "personagem_label":     "Geral",
            "personagem_descricao": None,
            "quantidade":           geral_qty,
            "queries":              [req.theme_prompt, f"perspectiva ampla sobre {req.theme_prompt}"],
            "modo_geral":           False,
        })

    return batches


# ── Prompt de geração ─────────────────────────────────────

def _build_prompt(
    theme_prompt: str,
    contexto_rag: str,
    nivel: int,
    personagem_tipo: str,
    personagem_label: str,
    personagem_descricao: str | None,
    quantidade: int,
    num_options: int = 2,
    enable_levels: bool = True,
    modo_geral: bool = False,
) -> str:
    id_prefixo = f"{nivel}-{personagem_tipo}"
    opcoes_str = "A e B" if num_options == 2 else "A, B e C"

    # Foco narrativo: distingue Modo Geral (sem personagens) do lote geral (20% em modo personagem)
    if modo_geral:
        foco = (
            f"Perguntas universais direcionadas ao JOGADOR GENÉRICO sobre o tema '{theme_prompt}'. "
            "O jogador não tem papel pré-definido — é um cidadão, consumidor, eleitor ou estudante "
            "que enfrenta decisões relacionadas ao tema. "
            "Explore ângulos variados: social, econômico, cultural e histórico. Proibido repetir sub-temas."
        )
    elif personagem_tipo == "geral":
        foco = (
            f"Dilemas de perspectiva COLETIVA sobre o tema '{theme_prompt}', sem personagem fixo. "
            "O jogador assume o papel de tomador de decisão coletiva ou de membro de comunidade afetada. "
            "Explore ângulos variados: social, econômico, cultural e histórico — nunca apenas um sub-tema."
        )
    else:
        nivel_label = "avançado" if nivel == 2 else "básico"
        foco = (
            f"Dilemas narrados do ponto de vista do personagem: **{personagem_label}**.\n"
            f"Descrição: {personagem_descricao}\n"
            f"O dilema deve refletir os valores, conflitos e responsabilidades específicos "
            f"deste personagem diante do tema '{theme_prompt}'. "
            f"Nível de complexidade {nivel_label}: ajuste o vocabulário e a profundidade conforme o público."
        )

    # Instrução de nível: omite diferenciação quando níveis estão desativados
    if enable_levels:
        nivel_instrucao = (
            "NÍVEL 1 — BÁSICO: Linguagem clara e acessível. Conecte o cenário ao cotidiano do leitor. "
            "Explique brevemente conceitos técnicos quando necessários."
            if nivel == 1 else
            "NÍVEL 2 — AVANÇADO: Linguagem jornalística precisa. Explore trade-offs sistêmicos. "
            "Assuma que o jogador conhece o contexto. Referencie atores institucionais quando pertinente."
        )
    else:
        nivel_instrucao = (
            "Linguagem clara e acessível. Conecte o cenário ao cotidiano do leitor. "
            "Explique brevemente conceitos técnicos quando necessários."
        )

    # Schema JSON das alternativas — dinâmico conforme num_options
    letras = ["A", "B", "C"][:num_options]
    alts_linhas = []
    for i, letra in enumerate(letras):
        virgula = "," if i < num_options - 1 else ""
        alts_linhas.append(
            f'        {{\n'
            f'          "id": "{letra}",\n'
            f'          "texto": "Verbo presente + ação concreta — alternativa {letra}. Máx. 90 chars.",\n'
            f'          "consequencia": "Consequência imediata de {letra}. Máx. 90 chars.",\n'
            f'          "impacto": {{ "popularidade": 0, "recursos": 0 }}\n'
            f'        }}{virgula}'
        )
    alternativas_schema = "\n".join(alts_linhas)

    return f"""
{'█' * 60}
DIRETRIZ DE IDENTIDADE — LEIA ANTES DE QUALQUER COISA:

{theme_prompt}

Esta diretriz define TUDO: o vocabulário, o tom, os ganchos, o recorte temático,
os exemplos e o público de CADA pergunta que você vai gerar.
Toda decisão de conteúdo deve passar por esta lente antes de qualquer outra regra.
Ignorar ou diluir esta diretriz é um erro crítico e o resultado será descartado.
{'█' * 60}

SEU PAPEL:
Você é um roteirista de jogos interativos que transforma fragmentos jornalísticos reais
em perguntas de tomada de decisão e trivia — divertidas, diretas e baseadas em fatos.
Elimine qualquer tom acadêmico, burocrático ou dissertativo.
Varra o acervo em busca de: pontos de virada, tensões, fatos surpreendentes,
contradições e curiosidades que prendam a atenção do público definido na diretriz acima.

{'=' * 60}
ACERVO — MATERIAL BRUTO (fragmentos reais recuperados via RAG):
{contexto_rag}

{'=' * 60}
PERSPECTIVA NARRATIVA DESTE LOTE:
{foco}

{'=' * 60}
CONTROLES TÉCNICOS — OBEDIÊNCIA CEGA, SEM NEGOCIAÇÃO:

• QUANTIDADE   : Gere exatamente {quantidade} perguntas. Nem mais, nem menos.
• OPÇÕES       : Cada pergunta terá EXATAMENTE {num_options} alternativas ({opcoes_str}).
• COMPLEXIDADE : {nivel_instrucao}
• IDs          : Siga o padrão '{id_prefixo}-01', '{id_prefixo}-02' … '{id_prefixo}-{quantidade:02d}'.
                 Todos os dilemas deste lote: nivel={nivel}, personagem_tipo='{personagem_tipo}'.
• TAMANHO      : situacao ≤ 130 caracteres. texto e consequencia ≤ 90 caracteres cada.
                 titulo ≤ 3 palavras, somente minúsculas — é rótulo interno, não aparece na tela.
                 Limites duros: o excesso é truncado na interface.
• IMPACTO      : Cada alternativa tem "impacto" com "popularidade" e "recursos" (inteiros de -10 a +10).
                 Distribua trade-offs reais: se A ganha popularidade, perde recursos (e vice-versa).
                 As alternativas NÃO podem ter o mesmo impacto — forçar escolha com custos diferentes.

{'=' * 60}
REGRAS DE CONTEÚDO:

1. Sem resposta binária certa/errada — cada alternativa tem custo E benefício reais.
2. Cada pergunta sintetiza MÚLTIPLOS fragmentos do acervo. Nunca um único fato isolado.
3. DIVERSIDADE: os {quantidade} dilemas devem cobrir ângulos diferentes. Proibido repetir sub-temas.
4. Português do Brasil. Sentence case em TODOS os campos — nunca all-caps, nem caixa alta decorativa.
   Apenas a primeira letra de cada campo começa em maiúscula; o restante em minúsculas.
5. CTA obrigatório: o campo "situacao" DEVE terminar com dois pontos (:).
   A última frase apresenta o ponto de decisão ao jogador, ex: "Você precisa decidir:"
   NÃO termine com ponto final, exclamação ou qualquer outra pontuação além de (:).
6. Verbos no presente: TODAS as alternativas começam com verbo conjugado no PRESENTE DO INDICATIVO.
   PROIBIDO infinitivo: não use "Destacar", "Focar", "Apoiar", "Reduzir".
   USE: "Destaca", "Foca", "Apoia", "Reduz", "Pressiona", "Anuncia", "Recusa", "Investe".
7. ACESSIBILIDADE: público-alvo é jovem leigo, não jornalista. PROIBIDO jargão editorial interno
   (ex: "pauta", "apuração", "off the record", "release", "briefing", "lead").
   O foco é trivia criativa de bastidores: elenco, direção, anos de peças, curiosidades de produção.
   Cada dilema deve ser jogável por quem nunca trabalhou na área.
8. Tom direto: PROIBIDO começar com "Como [Personagem]", "Enquanto [Personagem]", "Na posição de".

EXTRAÇÃO DE URL — OBRIGATÓRIO:
9. Campo "link_original": use SOMENTE a URL marcada como SOURCE_URL: no acervo acima.
   Deve iniciar com 'http'. NUNCA invente, suponha ou complete URLs parciais.
   Se não houver URL válida para um dilema, descarte-o e gere um substituto com SOURCE_URL válida.
10. Rotatividade de links: a mesma SOURCE_URL não pode aparecer em mais de 2 dilemas deste lote.

{'=' * 60}
ESTRUTURA JSON OBRIGATÓRIA — nomes de campo exatos, sem omitir nem renomear:

{{
  "dilemas": [
    {{
      "id_unico": "{id_prefixo}-01",
      "nivel": {nivel},
      "personagem_tipo": "{personagem_tipo}",
      "titulo": "rótulo em 3 palavras",
      "situacao": "Cenário em 1-2 frases. Máx. 130 chars. Termina obrigatoriamente com dois pontos (:)",
      "alternativas": [
{alternativas_schema}
      ],
      "desfecho_real": "O que de fato ocorreu segundo a reportagem. 2-3 frases. Factual.",
      "licao": "Lição extraída do desfecho, na linguagem e tom da diretriz. 1-2 frases.",
      "info_contextual": {{
        "sintese": "Síntese jornalística em 2-3 frases. Dados e atores reais.",
        "link_original": "https://url-extraida-do-SOURCE_URL-do-contexto"
      }}
    }}
  ]
}}
"""


# ── Stream SSE ────────────────────────────────────────────

def generate_stream(req: GenerateRequest) -> Generator[str, None, None]:
    """
    Gerador SSE — emite eventos à medida que dilemas são gerados.

    Formato de cada evento:
      data: {"type": "dilema", "current": N, "total": M, "dilema": {...}}\\n\\n
    """
    cfg            = get_settings()
    db             = get_collection(req.site_id)
    structured_llm = _get_llm().with_structured_output(LoteDilemas)
    batches        = _build_batches(req)
    total_plan     = sum(b["quantidade"] for b in batches)
    generated      = 0
    position       = 0

    yield f"data: {json.dumps({'type': 'start', 'total': total_plan})}\n\n"

    for batch_idx, batch in enumerate(batches):
        nivel             = batch["nivel"]
        personagem_tipo   = batch["personagem_tipo"]
        personagem_label  = batch["personagem_label"]
        personagem_desc   = batch["personagem_descricao"]
        quantidade        = batch["quantidade"]
        queries           = batch["queries"]
        modo_geral        = batch.get("modo_geral", False)

        contexto_rag = mmr_search(
            db, queries,
            fetch_k=cfg.rag_fetch_k,
            k=cfg.rag_k,
        )

        if not contexto_rag.strip():
            yield f"data: {json.dumps({'type': 'warning', 'msg': f'Sem contexto RAG para {personagem_tipo}. Acervo pode estar vazio.'})}\n\n"
            continue

        prompt = _build_prompt(
            theme_prompt=req.theme_prompt,
            contexto_rag=contexto_rag,
            nivel=nivel,
            personagem_tipo=personagem_tipo,
            personagem_label=personagem_label,
            personagem_descricao=personagem_desc,
            quantidade=quantidade,
            num_options=req.num_options,
            enable_levels=req.enable_levels,
            modo_geral=modo_geral,
        )

        dilemas: list[DilemaNg] = []
        for tentativa in range(1, cfg.max_retries_batch + 1):
            try:
                resultado: LoteDilemas = structured_llm.invoke(prompt)
                dilemas = resultado.dilemas
                break
            except Exception as exc:
                err = str(exc).lower()
                is_rate = any(k in err for k in ["429", "resource_exhausted", "quota", "rate_limit"])
                # Loga o erro completo no stderr para diagnóstico no log do servidor
                print(
                    f"[generator] tentativa={tentativa} batch={batch_idx} "
                    f"personagem={personagem_tipo} erro={exc!r}",
                    file=sys.stderr,
                )
                if is_rate and tentativa < cfg.max_retries_batch:
                    yield f"data: {json.dumps({'type': 'rate_limit', 'sleep': cfg.rate_limit_sleep, 'tentativa': tentativa})}\n\n"
                    time.sleep(cfg.rate_limit_sleep)
                else:
                    yield f"data: {json.dumps({'type': 'error', 'batch': batch_idx, 'personagem': personagem_tipo, 'msg': str(exc)})}\n\n"
                    break

        # Descarta dilemas inválidos: título/situação vazios, número errado de alternativas
        dilemas = [d for d in dilemas if _is_valid_dilema(d, req.num_options)]

        for d in dilemas:
            position  += 1
            generated += 1
            payload = d.model_dump()
            payload["instance_id"] = req.instance_id
            payload["position"]    = position
            yield (
                f"data: {json.dumps({'type': 'dilema', 'current': generated, 'total': total_plan, 'dilema': payload}, ensure_ascii=False)}\n\n"
            )

        if batch_idx < len(batches) - 1:
            time.sleep(cfg.batch_sleep)

    yield f"data: {json.dumps({'type': 'done', 'total_generated': generated})}\n\n"
