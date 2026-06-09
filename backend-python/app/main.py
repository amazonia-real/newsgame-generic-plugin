"""FastAPI — entry point do backend News-Game."""
from __future__ import annotations
import json
import math
import os
import re
import sys
import traceback
from fastapi import FastAPI, Depends, HTTPException, Header, Request
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse, StreamingResponse
from langchain_core.messages import AIMessage, HumanMessage, SystemMessage
from langchain_google_genai import ChatGoogleGenerativeAI
from app.config import get_settings
from app.models import (
    IngestRequest,
    GenerateRequest,
    RegenerateRequest,
    AxisSet,
    DomainAnalysisRequest,
    DomainAnalysisResponse,
    AssignWeightsRequest,
    AssignWeightsResponse,
    GenerateVerdictRequest,
    GenerateVerdictResponse,
    ProfileSuggestion,
    VerdictNarrative,
)
from app import ingestor, generator, regenerator


app = FastAPI(title="News-Game Backend", version="1.0.0")

# Permite chamadas diretas do browser (WordPress admin → backend Python)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


def _verify_token(x_api_key: str = Header(default="")) -> None:
    cfg = get_settings()
    if cfg.api_secret_token and x_api_key != cfg.api_secret_token:
        raise HTTPException(status_code=401, detail="Invalid API key")


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "version": "1.0.0"}


@app.post("/ingest", dependencies=[Depends(_verify_token)])
def ingest(req: IngestRequest) -> dict:
    """Indexa artigos do vault WordPress no ChromaDB."""
    return ingestor.index_articles(req)


@app.post("/regenerate", dependencies=[Depends(_verify_token)])
def regenerate_one(req: RegenerateRequest) -> dict:
    """
    Gera 1 pergunta substituta de forma síncrona.
    Retorna o dilema serializado ou 422 se não conseguir gerar.
    """
    dilema = regenerator.generate_one(req)
    if dilema is None:
        raise HTTPException(status_code=422, detail="Não foi possível gerar uma pergunta substituta válida.")
    return dilema.model_dump()


@app.post("/generate-one", dependencies=[Depends(_verify_token)])
def generate_one(req: GenerateRequest) -> dict:
    """
    Gera 1 dilema de forma síncrona. O JS do painel chama este endpoint
    em loop (uma vez por dilema) para evitar timeout de conexão.
    req.slot_index (0-based) controla distribuição personagem/nível.
    """
    try:
        dilema = generator.generate_one_dilema(req)
        if dilema is None:
            raise HTTPException(status_code=422, detail="Não foi possível gerar um dilema válido para este slot.")
        result = dilema.model_dump()
        result["instance_id"] = req.instance_id
        result["position"]    = req.slot_index + 1
        return result
    except HTTPException:
        raise
    except Exception as e:
        import traceback
        traceback.print_exc()
        from fastapi.responses import JSONResponse
        return JSONResponse(
            status_code=200,  # Enganamos o Chrome para ele ler o JSON sem travar no CORS
            headers={
                "Access-Control-Allow-Origin": "*",
                "Access-Control-Allow-Methods": "*",
                "Access-Control-Allow-Headers": "*"
            },
            content={
                "detail": "Internal Server Error in /generate-one",
                "error": str(e),
                "trace": traceback.format_exc(),
                "dilema_invalido": True # Evita o colapso do JS do WordPress
            }
        )


@app.post("/generate", dependencies=[Depends(_verify_token)])
def generate(req: GenerateRequest) -> StreamingResponse:
    """
    Gera dilemas via RAG + Gemini e retorna como SSE stream.
    Cada evento: data: {"type": "dilema", "current": N, "total": M, "dilema": {...}}
    Evento final: data: {"type": "done", "total_generated": N}
    """
    return StreamingResponse(
        generator.generate_stream(req),
        media_type="text/event-stream",
        headers={
            "Cache-Control":   "no-cache",
            "X-Accel-Buffering": "no",   # desativa buffer do Nginx
            "Connection":      "keep-alive",
        },
    )


# ── Helpers para os endpoints de análise de domínio ───────────────────────

_SYSTEM_PROMPT_ANALYZE_DOMAIN = """\
Você é um analista editorial especializado em identificar tensões de valor em corpora jornalísticos \
e culturais. Sua tarefa é:

1. Ler o texto de contexto fornecido.
2. Identificar as TRÊS tensões de valor mais recorrentes e estruturantes desse domínio.
3. Para cada tensão, gerar:
   - Um nome curto (máximo 2 palavras)
   - Uma descrição neutra (máximo 20 palavras)
   - Um polo positivo (o que maximizar)
   - Um polo negativo (o que se abre mão)
4. Para cada eixo, gerar também 2 opções reserva alternativas com a mesma estrutura.
5. Sugerir entre 4 e 6 perfis de jogador baseados nesses eixos. Cada perfil deve ter:
   - Nome memorável (máx. 3 palavras)
   - Descrição (máx. 30 palavras)
   - Força dominante (1 frase)
   - Ponto cego (1 frase — sem julgamento moral)
   - Vetor aproximado: valores entre -1.0 e 1.0 para cada um dos 3 eixos

REGRAS CRÍTICAS:
- Responda APENAS com JSON válido.
- Sem texto fora do objeto JSON.
- Sem blocos de código markdown.
- Sem comentários dentro do JSON.
- Siga exatamente o schema fornecido.
- Os eixos devem emergir do texto — nunca force categorias externas (ex: ambiental/social/econômico) \
se o domínio não as justificar.\
"""

_SCHEMA_DOMAIN_ANALYSIS_EXAMPLE = """{
  "eixos_principais": {
    "eixo1": {
      "nome": "Nome Curto",
      "descricao": "Descrição neutra em até vinte palavras.",
      "polo_positivo": "O que se maximiza neste eixo.",
      "polo_negativo": "O que se sacrifica neste eixo."
    },
    "eixo2": {
      "nome": "Nome Curto",
      "descricao": "Descrição neutra em até vinte palavras.",
      "polo_positivo": "O que se maximiza neste eixo.",
      "polo_negativo": "O que se sacrifica neste eixo."
    },
    "eixo3": {
      "nome": "Nome Curto",
      "descricao": "Descrição neutra em até vinte palavras.",
      "polo_positivo": "O que se maximiza neste eixo.",
      "polo_negativo": "O que se sacrifica neste eixo."
    }
  },
  "eixos_reserva": {
    "eixo1": [
      {"nome": "Opção A", "descricao": "...", "polo_positivo": "...", "polo_negativo": "..."},
      {"nome": "Opção B", "descricao": "...", "polo_positivo": "...", "polo_negativo": "..."}
    ],
    "eixo2": [
      {"nome": "Opção A", "descricao": "...", "polo_positivo": "...", "polo_negativo": "..."},
      {"nome": "Opção B", "descricao": "...", "polo_positivo": "...", "polo_negativo": "..."}
    ],
    "eixo3": [
      {"nome": "Opção A", "descricao": "...", "polo_positivo": "...", "polo_negativo": "..."},
      {"nome": "Opção B", "descricao": "...", "polo_positivo": "...", "polo_negativo": "..."}
    ]
  },
  "perfis_sugeridos": [
    {
      "nome": "Nome do Perfil",
      "descricao": "Descrição em até trinta palavras.",
      "forca_dominante": "Uma frase sobre a principal força deste perfil.",
      "ponto_cego": "Uma frase sobre o ponto cego, sem julgamento moral.",
      "vetor_aproximado": {"eixo1": 0.8, "eixo2": -0.3, "eixo3": 0.5}
    }
  ]
}"""


def _resolve_gemini_key(header_key: str, body_key: str) -> str:
    """Resolve chave de API Gemini por prioridade: header > body > variável de ambiente."""
    if header_key:
        return header_key
    if body_key:
        return body_key
    env_key = os.getenv("GEMINI_API_KEY", "")
    if env_key:
        return env_key
    raise HTTPException(
        status_code=401,
        detail=(
            "Chave de API Gemini não encontrada. Forneça via header X-Gemini-Api-Key, "
            "campo gemini_api_key no body, ou variável de ambiente GEMINI_API_KEY."
        ),
    )


def _make_llm(api_key: str) -> ChatGoogleGenerativeAI:
    """Instancia o LLM Gemini com temperatura baixa para geração estrutural."""
    cfg = get_settings()
    return ChatGoogleGenerativeAI(
        model=cfg.llm_model,
        temperature=cfg.llm_temperature,
        google_api_key=api_key,
        max_retries=2,
    )


def _extract_json(text: str) -> dict:
    """Extrai e parseia o JSON bruto da resposta do Gemini, ignorando markdown e lixo."""
    text = text.strip()
    match = re.search(r'```(?:json)?\s*([\s\S]*?)\s*```', text)
    if match:
        text = match.group(1).strip()
    match2 = re.search(r'(\{.*\})', text, re.DOTALL)
    if match2:
        text = match2.group(1)
    return json.loads(text)


# ── Rota /analyze-domain ──────────────────────────────────────────────────

@app.post("/analyze-domain")
def analyze_domain(
    req: DomainAnalysisRequest,
    x_gemini_api_key: str = Header(default=""),
) -> DomainAnalysisResponse:
    """Analisa um corpus textual e identifica eixos de tensão e perfis de jogador via Gemini."""

    # PASSO 1 — Resolver chave de API
    api_key = _resolve_gemini_key(x_gemini_api_key, req.gemini_api_key)
    print(f"[analyze-domain] Iniciando análise de domínio. Tamanho do contexto: {len(req.context_text)} chars.")

    # PASSO 2 — Construir prompt de usuário
    perfis_hint = ""
    if req.perfis_disponiveis:
        lista = ", ".join(f'"{p}"' for p in req.perfis_disponiveis)
        perfis_hint = (
            f"\n\nPerfis pré-definidos pelo editor (use como referência, mas adapte ao domínio):\n{lista}"
        )

    user_prompt = (
        f"Texto de contexto:\n\n{req.context_text}"
        f"{perfis_hint}"
        f"\n\nSchema de saída obrigatório (substitua os valores pelos dados reais, mantendo as chaves):\n"
        f"{_SCHEMA_DOMAIN_ANALYSIS_EXAMPLE}"
        f"\n\nRetorne APENAS o JSON válido, sem texto adicional."
    )

    messages = [
        SystemMessage(content=_SYSTEM_PROMPT_ANALYZE_DOMAIN),
        HumanMessage(content=user_prompt),
    ]

    # PASSO 3 — Chamar Gemini
    llm = _make_llm(api_key)
    print("[analyze-domain] Chamando Gemini (tentativa 1)...")
    raw_text = llm.invoke(messages).content

    # PASSO 4 — Validar resposta
    try:
        data = _extract_json(raw_text)
        result = DomainAnalysisResponse(**data)
        print("[analyze-domain] Validação OK na primeira tentativa.")
    except Exception as erro:
        print(f"[analyze-domain] Tentativa 1 falhou: {erro}. Reenviando com correção...")
        retry_messages = messages + [
            AIMessage(content=raw_text),
            HumanMessage(
                content=(
                    f"Sua resposta anterior falhou por: {erro}. "
                    f"Corrija e retorne apenas o JSON.\n\nSchema esperado:\n{_SCHEMA_DOMAIN_ANALYSIS_EXAMPLE}"
                )
            ),
        ]
        raw_text2 = llm.invoke(retry_messages).content
        try:
            data = _extract_json(raw_text2)
            result = DomainAnalysisResponse(**data)
            print("[analyze-domain] Validação OK na segunda tentativa.")
        except Exception as erro2:
            print(f"[analyze-domain] Tentativa 2 falhou: {erro2}.")
            raise HTTPException(
                status_code=422,
                detail=f"Falha na validação da resposta do Gemini após 2 tentativas: {erro2}",
            )

    # PASSO 5 — Retornar
    return result


# ── Rota /assign-weights ──────────────────────────────────────────────────

_SYSTEM_PROMPT_ASSIGN_WEIGHTS = """\
Você é um analista de narrativa interativa. Atribua pesos de posicionamento para cada alternativa \
de dilemas jornalísticos em relação a três eixos de valor.

Escala: -20 (máximo polo negativo) a +20 (máximo polo positivo). Use inteiros.

REGRAS:
- Responda APENAS com JSON válido.
- Sem texto fora do JSON.
- Sem blocos de código markdown.
- Pesos devem refletir o posicionamento real da escolha nos eixos, com trade-offs genuínos.\
"""


@app.post("/assign-weights")
def assign_weights(
    req: AssignWeightsRequest,
    x_gemini_api_key: str = Header(default=""),
) -> AssignWeightsResponse:
    """Atribui pesos (-20 a +20) em cada eixo para cada alternativa de cada dilema."""

    # PASSO 1 — Resolver chave de API
    api_key = _resolve_gemini_key(x_gemini_api_key, req.gemini_api_key)
    print(f"[assign-weights] Atribuindo pesos para {len(req.dilemmas)} dilema(s).")

    # PASSO 2 — Montar representação simplificada dos dilemas para o prompt
    dilemas_simplificados = []
    for i, d in enumerate(req.dilemmas):
        alts = [
            {"letra": a.get("letra", str(i)), "texto": a.get("texto", "")}
            for a in d.get("alternativas", [])
        ]
        dilemas_simplificados.append({
            "indice": i,
            "situacao": d.get("situacao", ""),
            "alternativas": alts,
        })

    eixos = req.eixos
    schema_saida = {
        "resultados": [
            {
                "indice": 0,
                "pesos_alternativas": [
                    {"letra": "A", "pesos": {"eixo1": 15, "eixo2": -5, "eixo3": 10}},
                    {"letra": "B", "pesos": {"eixo1": -10, "eixo2": 20, "eixo3": -5}},
                ],
            }
        ]
    }

    user_prompt = (
        f"Eixos de valor:\n"
        f"- eixo1: {eixos.eixo1.nome} — Polo positivo: {eixos.eixo1.polo_positivo} | "
        f"Polo negativo: {eixos.eixo1.polo_negativo}\n"
        f"- eixo2: {eixos.eixo2.nome} — Polo positivo: {eixos.eixo2.polo_positivo} | "
        f"Polo negativo: {eixos.eixo2.polo_negativo}\n"
        f"- eixo3: {eixos.eixo3.nome} — Polo positivo: {eixos.eixo3.polo_positivo} | "
        f"Polo negativo: {eixos.eixo3.polo_negativo}\n\n"
        f"Dilemas:\n{json.dumps(dilemas_simplificados, ensure_ascii=False, indent=2)}\n\n"
        f"Schema de saída obrigatório (uma entrada por dilema, uma por alternativa):\n"
        f"{json.dumps(schema_saida, ensure_ascii=False, indent=2)}\n\n"
        f"Retorne APENAS o JSON válido."
    )

    messages = [
        SystemMessage(content=_SYSTEM_PROMPT_ASSIGN_WEIGHTS),
        HumanMessage(content=user_prompt),
    ]

    # PASSO 3 — Chamar Gemini
    llm = _make_llm(api_key)
    print("[assign-weights] Chamando Gemini (tentativa 1)...")
    raw_text = llm.invoke(messages).content

    # PASSO 4 — Validar e fundir os pesos
    def _parse_and_merge(raw: str) -> list[dict]:
        data = _extract_json(raw)
        resultados = data.get("resultados", [])
        dilemas_weighted = [dict(d) for d in req.dilemmas]
        for item in resultados:
            idx = item.get("indice", -1)
            if idx < 0 or idx >= len(dilemas_weighted):
                continue
            pesos_map = {p["letra"]: p["pesos"] for p in item.get("pesos_alternativas", [])}
            alts = dilemas_weighted[idx].get("alternativas", [])
            dilemas_weighted[idx]["alternativas"] = [
                {**a, "pesos": pesos_map.get(a.get("letra", ""), {"eixo1": 0, "eixo2": 0, "eixo3": 0})}
                for a in alts
            ]
        return dilemas_weighted

    try:
        weighted = _parse_and_merge(raw_text)
        print("[assign-weights] Pesos atribuídos com sucesso na tentativa 1.")
    except Exception as erro:
        print(f"[assign-weights] Tentativa 1 falhou: {erro}. Reenviando...")
        retry_messages = messages + [
            AIMessage(content=raw_text),
            HumanMessage(
                content=(
                    f"Sua resposta anterior falhou por: {erro}. "
                    f"Corrija e retorne apenas o JSON."
                )
            ),
        ]
        raw_text2 = llm.invoke(retry_messages).content
        try:
            weighted = _parse_and_merge(raw_text2)
            print("[assign-weights] Pesos atribuídos com sucesso na tentativa 2.")
        except Exception as erro2:
            print(f"[assign-weights] Tentativa 2 falhou: {erro2}.")
            raise HTTPException(
                status_code=422,
                detail=f"Falha ao atribuir pesos após 2 tentativas: {erro2}",
            )

    return AssignWeightsResponse(dilemmas_weighted=weighted)


# ── Rota /generate-verdict ────────────────────────────────────────────────

_SYSTEM_PROMPT_GENERATE_VERDICT = """\
Você é um narrador editorial que cria textos de perfil reflexivos e não-julgadores para jogadores \
de quiz jornalístico. Seu texto deve ser empático, direto e convidativo — nunca moralizante.\
"""

_SCHEMA_VERDICT_EXAMPLE = """{
  "nome_perfil": "Nome do perfil selecionado",
  "texto_forca": "Duas a três frases sobre a força dominante do jogador, conectadas ao eixo predominante.",
  "texto_ponto_cego": "Duas a três frases sobre o ponto cego, sem julgamento moral.",
  "chamada_compartilhamento": "Uma frase convidativa para compartilhar nas redes sociais."
}"""


def _distancia_euclidiana(v1: dict[str, float], v2: dict[str, float]) -> float:
    """Calcula a distância euclidiana entre dois vetores de eixos."""
    return math.sqrt(sum((v1.get(k, 0.0) - v2.get(k, 0.0)) ** 2 for k in v1))


@app.post("/generate-verdict")
def generate_verdict(
    req: GenerateVerdictRequest,
    x_gemini_api_key: str = Header(default=""),
) -> GenerateVerdictResponse:
    """Seleciona o perfil mais próximo do vetor do jogador e gera texto narrativo personalizado."""

    # PASSO 1 — Resolver chave de API
    api_key = _resolve_gemini_key(x_gemini_api_key, req.gemini_api_key)
    print(f"[generate-verdict] Vetor do jogador: {req.vetor_jogador}. Perfis disponíveis: {len(req.perfis_disponiveis)}.")

    if not req.perfis_disponiveis:
        raise HTTPException(status_code=422, detail="A lista perfis_disponiveis não pode estar vazia.")

    # PASSO 2 — Selecionar perfil mais próximo por distância euclidiana
    perfil_selecionado: ProfileSuggestion = min(
        req.perfis_disponiveis,
        key=lambda p: _distancia_euclidiana(req.vetor_jogador, p.vetor_aproximado),
    )
    print(f"[generate-verdict] Perfil selecionado: {perfil_selecionado.nome}.")

    # PASSO 3 — Construir prompt para texto narrativo
    eixos = req.eixos
    vetor = req.vetor_jogador

    def _fmt_valor(v: float) -> str:
        return f"{v:+.2f}"

    user_prompt = (
        f"O jogador completou o quiz. Seu vetor de escolhas (normalizado entre -1.0 e +1.0) é:\n"
        f"- {eixos.eixo1.nome} (eixo1): {_fmt_valor(vetor.get('eixo1', 0.0))} "
        f"— polo positivo: {eixos.eixo1.polo_positivo} | polo negativo: {eixos.eixo1.polo_negativo}\n"
        f"- {eixos.eixo2.nome} (eixo2): {_fmt_valor(vetor.get('eixo2', 0.0))} "
        f"— polo positivo: {eixos.eixo2.polo_positivo} | polo negativo: {eixos.eixo2.polo_negativo}\n"
        f"- {eixos.eixo3.nome} (eixo3): {_fmt_valor(vetor.get('eixo3', 0.0))} "
        f"— polo positivo: {eixos.eixo3.polo_positivo} | polo negativo: {eixos.eixo3.polo_negativo}\n\n"
        f"Perfil mais próximo:\n"
        f"- Nome: {perfil_selecionado.nome}\n"
        f"- Descrição: {perfil_selecionado.descricao}\n"
        f"- Força dominante: {perfil_selecionado.forca_dominante}\n"
        f"- Ponto cego: {perfil_selecionado.ponto_cego}\n\n"
        f"Gere o texto narrativo personalizado seguindo exatamente este schema:\n"
        f"{_SCHEMA_VERDICT_EXAMPLE}\n\n"
        f"Retorne APENAS o JSON válido."
    )

    messages = [
        SystemMessage(content=_SYSTEM_PROMPT_GENERATE_VERDICT),
        HumanMessage(content=user_prompt),
    ]

    # PASSO 4 — Chamar Gemini
    llm = _make_llm(api_key)
    print("[generate-verdict] Chamando Gemini (tentativa 1)...")
    raw_text = llm.invoke(messages).content

    # PASSO 5 — Validar narrativa
    def _parse_narrativa(raw: str) -> VerdictNarrative:
        data = _extract_json(raw)
        return VerdictNarrative(**data)

    try:
        narrativa = _parse_narrativa(raw_text)
        print("[generate-verdict] Narrativa gerada com sucesso na tentativa 1.")
    except Exception as erro:
        print(f"[generate-verdict] Tentativa 1 falhou: {erro}. Reenviando...")
        retry_messages = messages + [
            AIMessage(content=raw_text),
            HumanMessage(
                content=(
                    f"Sua resposta anterior falhou por: {erro}. "
                    f"Corrija e retorne apenas o JSON.\n\nSchema esperado:\n{_SCHEMA_VERDICT_EXAMPLE}"
                )
            ),
        ]
        raw_text2 = llm.invoke(retry_messages).content
        try:
            narrativa = _parse_narrativa(raw_text2)
            print("[generate-verdict] Narrativa gerada com sucesso na tentativa 2.")
        except Exception as erro2:
            print(f"[generate-verdict] Tentativa 2 falhou: {erro2}.")
            raise HTTPException(
                status_code=422,
                detail=f"Falha ao gerar narrativa após 2 tentativas: {erro2}",
            )

    # PASSO 6 — Retornar
    return GenerateVerdictResponse(
        perfil_selecionado=perfil_selecionado,
        narrativa=narrativa,
        vetor_jogador=req.vetor_jogador,
    )
