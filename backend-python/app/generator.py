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
import time
from typing import Generator

from langchain_google_genai import ChatGoogleGenerativeAI

from app.config import get_settings
from app.models import GenerateRequest, LoteDilemas, DilemaNg
from app.rag import get_collection, mmr_search


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
    Retorna lista de lotes, cada um com queries RAG e quantidade.
    """
    n_chars = len(req.characters)
    char_density = math.floor(req.density * 0.8)
    per_char     = max(5, math.ceil(char_density / n_chars))
    geral_qty    = req.density - (per_char * n_chars)

    batches = []
    for char in req.characters:
        # Queries derivadas da descrição do personagem + tema — sem hardcode temático
        queries = [
            f"{char.description} {req.theme_prompt}",
            f"{char.label} {req.theme_prompt}",
            char.description,
            req.theme_prompt,
        ]
        batches.append({
            "nivel":              1,
            "personagem_tipo":    char.slug,
            "personagem_label":   char.label,
            "personagem_descricao": char.description,
            "quantidade":         per_char,
            "queries":            queries,
        })

    if geral_qty > 0:
        batches.append({
            "nivel":              2,
            "personagem_tipo":    "geral",
            "personagem_label":   "Geral",
            "personagem_descricao": None,
            "quantidade":         geral_qty,
            "queries":            [req.theme_prompt, f"perspectiva ampla sobre {req.theme_prompt}"],
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
) -> str:
    id_prefixo = f"{nivel}-{personagem_tipo}"

    if personagem_tipo == "geral":
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

    nivel_instrucao = (
        "NÍVEL 1 — BÁSICO: Linguagem clara e acessível. Conecte o cenário ao cotidiano do leitor. "
        "Explique brevemente conceitos técnicos quando necessários."
        if nivel == 1 else
        "NÍVEL 2 — AVANÇADO: Linguagem jornalística precisa. Explore trade-offs sistêmicos. "
        "Assuma que o jogador conhece o contexto. Referencie atores institucionais quando pertinente."
    )

    return f"""Você é um Designer de Narrativas para um plugin WordPress educativo que transforma reportagens reais em dilemas interativos gamificados.

CONTEXTO JORNALÍSTICO (reportagens reais — recuperadas via RAG com diversidade MMR):
{contexto_rag}

{'=' * 60}
TEMA CENTRAL: {theme_prompt}
NÍVEL DESTE LOTE: {nivel} | PERSONAGEM: {personagem_tipo}

INSTRUÇÃO DE NÍVEL (obrigatória — define tom e profundidade):
{nivel_instrucao}

FOCO DAS PERGUNTAS:
{foco}

SUA MISSÃO: Gere exatamente **{quantidade} dilemas**.

REGRAS OBRIGATÓRIAS:
1. Cada dilema deve ter EXATAMENTE 2 alternativas (A e B).
2. Não existe resposta binária certa/errada — cada escolha tem custo E benefício reais.
3. Os cenários devem emergir da SÍNTESE de múltiplos trechos do contexto, nunca de um único fato.
4. DIVERSIDADE: os {quantidade} dilemas devem cobrir ângulos diferentes. Proibido repetir sub-temas.
5. Todo o conteúdo em Português do Brasil. Sentence case no corpo (caixa alta permitida no título).
6. O campo "id_unico" deve seguir o padrão: '{id_prefixo}-01', '{id_prefixo}-02', ..., '{id_prefixo}-{quantidade:02d}'.
7. O campo "nivel" de TODOS os dilemas deste lote deve ser o inteiro {nivel}.
8. O campo "personagem_tipo" de TODOS deve ser a string '{personagem_tipo}'.

EXTRAÇÃO DE URL — OBRIGATÓRIO:
9. Para o campo "link_original" dentro de "info_contextual", use apenas a URL marcada como SOURCE_URL: no contexto acima.
   Deve iniciar com 'http'. NUNCA invente, suponha ou complete URLs.
   Se nenhuma URL válida for encontrada para um dilema, descarte-o e gere um substituto
   baseado em outro documento do contexto que possua SOURCE_URL válida.
10. A narrativa deve ser íntegra, respeitosa às comunidades envolvidas e educativa.

{'=' * 60}
ESTRUTURA JSON OBRIGATÓRIA — use estes nomes de campo exatamente (não renomeie, não omita):

{{
  "dilemas": [
    {{
      "id_unico": "{id_prefixo}-01",
      "nivel": {nivel},
      "personagem_tipo": "{personagem_tipo}",
      "titulo": "TÍTULO EM ATÉ 8 PALAVRAS",
      "situacao": "Cenário do dilema em 2-4 frases. Sentence case.",
      "alternativas": [
        {{
          "id": "A",
          "texto": "Ação que o jogador pode escolher. Máx. 2 frases.",
          "consequencia": "Consequência direta e realista desta escolha. Máx. 2 frases."
        }},
        {{
          "id": "B",
          "texto": "Ação alternativa. Máx. 2 frases.",
          "consequencia": "Consequência direta e realista. Máx. 2 frases."
        }}
      ],
      "desfecho_real": "O que de fato ocorreu segundo a reportagem. 2-3 frases. Factual.",
      "licao": "Lição educativa extraída do desfecho. 1-2 frases.",
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
                if is_rate and tentativa < cfg.max_retries_batch:
                    yield f"data: {json.dumps({'type': 'rate_limit', 'sleep': cfg.rate_limit_sleep, 'tentativa': tentativa})}\n\n"
                    time.sleep(cfg.rate_limit_sleep)
                else:
                    yield f"data: {json.dumps({'type': 'error', 'batch': batch_idx, 'personagem': personagem_tipo, 'msg': str(exc)})}\n\n"
                    break

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
