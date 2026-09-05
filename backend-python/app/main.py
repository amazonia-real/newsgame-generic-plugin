"""FastAPI — entry point do backend News-Game."""
from __future__ import annotations
import logging
import json
import secrets
import math
import re
from dotenv import load_dotenv

# Exporta o .env para os.environ antes dos imports da app — pydantic-settings
# lê o .env só para os campos de Settings; variáveis consumidas por terceiros
# direto do ambiente (ex.: ANONYMIZED_TELEMETRY do ChromaDB) precisam disto.
load_dotenv()

from fastapi import FastAPI, Depends, HTTPException, Header, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse, StreamingResponse
from langchain_core.messages import AIMessage, HumanMessage, SystemMessage
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
from app.logging_config import setup_logging

setup_logging()
logger = logging.getLogger(__name__)

app = FastAPI(title="News-Game Backend", version="1.0.0")
get_settings()  # falha aqui se API_SECRET_TOKEN não estiver definido

# Sem CORSMiddleware: todas as chamadas chegam server-to-server via proxy
# PHP (wp_remote_post) — nenhum browser acessa o backend diretamente.


def _verify_token(x_api_key: str = Header(default="")) -> None:
    cfg = get_settings()
    if not cfg.api_secret_token:
        raise HTTPException(503, "API_SECRET_TOKEN não configurado.")
    if not secrets.compare_digest(x_api_key, cfg.api_secret_token):
        raise HTTPException(401, "Invalid API key")


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "version": "1.0.0"}


@app.post("/ingest", dependencies=[Depends(_verify_token)])
def ingest(req: IngestRequest) -> dict:
    """Indexa artigos do vault WordPress no ChromaDB."""
    return ingestor.index_articles(req)


@app.post("/regenerate", dependencies=[Depends(_verify_token)])
def regenerate_one(
    req: RegenerateRequest,
    x_gemini_api_key: str = Header(default=""),
) -> dict:
    """
    Gera 1 pergunta substituta de forma síncrona.
    Retorna o dilema serializado ou 422 se não conseguir gerar.
    """
    api_key = _resolve_gemini_key(x_gemini_api_key, req.gemini_api_key)
    dilema = regenerator.generate_one(req, api_key)
    if dilema is None:
        raise HTTPException(status_code=422, detail="Não foi possível gerar uma pergunta substituta válida.")
    return dilema.model_dump()


@app.post("/generate-one", dependencies=[Depends(_verify_token)])
def generate_one(
    req: GenerateRequest,
    x_gemini_api_key: str = Header(default=""),
) -> dict:
    """
    Gera 1 dilema de forma síncrona. O JS do painel chama este endpoint
    em loop (uma vez por dilema) para evitar timeout de conexão.
    req.slot_index (0-based) controla distribuição personagem/nível.
    """
    # Resolução FORA do try: um 401 de chave ausente não deve ser confundido
    # com falha de geração. O `except HTTPException: raise` abaixo já o
    # repassaria, mas resolver antes deixa a ordem explícita.
    api_key = _resolve_gemini_key(x_gemini_api_key, req.gemini_api_key)
    try:
        dilema = generator.generate_one_dilema(req, api_key)
        if dilema is None:
            raise HTTPException(status_code=422, detail="Não foi possível gerar um dilema válido para este slot.")
        result = dilema.model_dump()
        result["instance_id"] = req.instance_id
        result["position"]    = req.slot_index + 1
        return result
    except HTTPException:
        raise
    except Exception:
        logger.exception("[generate-one] Erro inesperado no slot %s", req.slot_index)
        return JSONResponse(
            status_code=500,
            content={"detail": "Erro interno", "dilema_invalido": True},
        )


@app.post("/generate", dependencies=[Depends(_verify_token)])
def generate(
    req: GenerateRequest,
    x_gemini_api_key: str = Header(default=""),
) -> StreamingResponse:
    """
    Gera dilemas via RAG + Gemini e retorna como SSE stream.
    Cada evento: data: {"type": "dilema", "current": N, "total": M, "dilema": {...}}
    Evento final: data: {"type": "done", "total_generated": N}
    """
    # Resolvida ANTES de a resposta começar: com o stream já aberto não há como
    # devolver 401 — os headers HTTP já foram enviados.
    api_key = _resolve_gemini_key(x_gemini_api_key, req.gemini_api_key)
    return StreamingResponse(
        generator.generate_stream(req, api_key),
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
    """
    Resolve a chave de API por prioridade: header > body > Settings (.env).

    Único gate de "existe chave disponível" do backend — as seis rotas que usam
    LLM passam por aqui antes de construir cliente. Desde a fase 0 do H1,
    `gemini_api_key` é opcional em config.py, então é este 401 que substitui a
    falha no boot.
    """
    if header_key:
        return header_key
    if body_key:
        return body_key

    # LÊ DO SETTINGS, e não de os.getenv() — a troca é da fase 0 do H1.
    #
    # O `.env` só chegava a os.environ por causa do `load_dotenv()` da linha 14,
    # que existe para OUTRA finalidade: exportar ANONYMIZED_TELEMETRY para o
    # ChromaDB (M5, commit 268030f). Ou seja, o terceiro degrau desta cascata
    # dependia de um efeito colateral instalado por outro motivo — remover
    # aquele load_dotenv quebraria este degrau em silêncio, enquanto o resto do
    # backend seguiria lendo a chave por Settings. Split-brain entre dois
    # caminhos que deveriam ler a mesma coisa.
    #
    # pydantic-settings lê variável de ambiente REAL e o arquivo .env, com a
    # real tendo precedência — cobre estritamente mais que os.getenv(), que só
    # vê o que já está no ambiente do processo.
    env_key = get_settings().gemini_api_key
    if env_key:
        return env_key
    raise HTTPException(
        status_code=401,
        detail=(
            "Chave de API Gemini não encontrada. Forneça via header X-Gemini-Api-Key, "
            "campo gemini_api_key no body, ou variável de ambiente GEMINI_API_KEY."
        ),
    )


# O construtor do cliente saiu daqui na fase 0 do H1 e virou
# `generator._get_llm(api_key)` — ver a nota longa lá. Havia dois construtores
# com políticas diferentes: este, sem cache, e o singleton do generator, que
# ignorava a chave da requisição. Agora é um só, com cache por chave.
#
# ⚠️ CONSEQUÊNCIA QUE VALE REGISTRAR: com este bloco, `main.py` deixou de
# importar `langchain_google_genai`. O acoplamento com o SDK do provedor passou
# a estar num arquivo só (`generator.py`), que é exatamente o que o H1 completo
# vai precisar substituir.


# ── Chamada ao provedor, com erro traduzido ────────────────
#
# Marcadores de "o provedor recusou a chave". FRÁGIL DE PROPÓSITO, E DECLARADO:
# o SDK do Gemini levanta UMA classe genérica (ChatGoogleGenerativeAIError)
# para tudo, com a causa apenas no texto da mensagem. Importar as classes de
# exceção do google aqui acoplaria `main.py` ao provedor, que é o oposto do H1 —
# este arquivo deixou de importar o SDK no passo 2 e não deve voltar a importar.
#
# ⚠️ QUANDO O SEGUNDO PROVEDOR ENTRAR, ISTO MUDA DE FORMA. `openai` e
# `anthropic` levantam classes DEDICADAS de autenticação, com `status_code` —
# lá a detecção deve ser por classe/status, não por substring. O Gemini vai
# continuar sendo o caso que exige o remendo de texto. Nada de taxonomia por
# provedor antes de o segundo existir de fato (decisão da fase 0).
#
# Sem "401"/"403" na lista de propósito: são números curtos demais e apareceriam
# por coincidência em mensagens que não são de autenticação.
_MARCADORES_AUTH = (
    "api key not valid",
    "api_key_invalid",
    "invalid api key",
    "incorrect api key",
    "invalid authentication",
    "unauthenticated",
    "permission denied",
    "permission_denied",
)


def _invoke_llm(llm, messages, rota: str) -> str:
    """
    Chama o provedor e traduz recusa em resposta HTTP estruturada.

    ── POR QUE 502 E NÃO 401 ──────────────────────────────────────────
    Nesta API o 401 já significa outra coisa: é o que `_verify_token()` (:54)
    devolve quando o `X-Api-Key` do plugin está errado. Reusar 401 para "o
    PROVEDOR recusou a NOSSA chave" faria duas causas muito diferentes chegarem
    ao editor com o mesmo código. 502 = a dependência externa recusou a chamada.

    ── O QUE ESTE HELPER NÃO É ────────────────────────────────────────
    Ele NÃO cobre a validação da resposta. O `try` de cada rota, logo depois da
    chamada, continua cuidando de "o provedor respondeu mas o JSON não presta" —
    é o auto-reparo em duas tentativas da FLAG 001. São duas falhas diferentes,
    em dois `try` diferentes: aqui é "a chamada não aconteceu", lá é "a resposta
    não serve". A prova de que não se atropelam é que o retry da FLAG 001 chama
    ESTE helper de novo (linhas 333, 457, 566): se a segunda chamada falhar no
    provedor, sai 502; se ela responder e o JSON continuar ruim, sai o 422 da
    validação, como antes.

    Antes da fase 0 (passo 5a) os seis `invoke` rodavam soltos e qualquer erro
    de provedor virava `500 Internal Server Error` sem `detail` — medido em
    2026-09-04: cinco 500 mudos durante os testes da cascata.

    O traceback completo vai para o log do servidor; o cliente recebe só
    `detail`, que é a decisão do C2/FLAG 003.
    """
    try:
        return llm.invoke(messages).content
    except Exception as exc:
        logger.exception("[%s] Chamada ao provedor de IA falhou.", rota)
        texto = str(exc).lower()

        if any(marcador in texto for marcador in _MARCADORES_AUTH):
            detail = (
                "O provedor de IA recusou a chave de API. Verifique a chave em "
                "News-Game › Configurações — ou, se o backend usa a própria, a "
                "variável GEMINI_API_KEY do servidor."
            )
        else:
            # Mensagem truncada, não traceback: quota estourada, timeout e
            # indisponibilidade caem aqui, e o editor precisa de pista.
            detail = (
                f"Falha na chamada ao provedor de IA ({type(exc).__name__}): "
                f"{str(exc)[:200]}"
            )

        raise HTTPException(status_code=502, detail=detail) from exc


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

@app.post("/analyze-domain", dependencies=[Depends(_verify_token)])
def analyze_domain(
    req: DomainAnalysisRequest,
    x_gemini_api_key: str = Header(default=""),
) -> DomainAnalysisResponse:
    """Analisa um corpus textual e identifica eixos de tensão e perfis de jogador via Gemini."""

    # PASSO 1 — Resolver chave de API
    api_key = _resolve_gemini_key(x_gemini_api_key, req.gemini_api_key)
    logger.info("[analyze-domain] Iniciando análise de domínio. Tamanho do contexto: %d chars.", len(req.context_text))

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
    llm = generator._get_llm(api_key)
    logger.debug("[analyze-domain] Chamando Gemini (tentativa 1)...")
    raw_text = _invoke_llm(llm, messages, "analyze-domain")

    # PASSO 4 — Validar resposta
    try:
        data = _extract_json(raw_text)
        result = DomainAnalysisResponse(**data)
        logger.info("[analyze-domain] Validação OK na primeira tentativa.")
    except Exception as erro:
        logger.warning("[analyze-domain] Tentativa 1 falhou: %s. Reenviando com correção...", erro)
        retry_messages = messages + [
            AIMessage(content=raw_text),
            HumanMessage(
                content=(
                    f"Sua resposta anterior falhou por: {erro}. "
                    f"Corrija e retorne apenas o JSON.\n\nSchema esperado:\n{_SCHEMA_DOMAIN_ANALYSIS_EXAMPLE}"
                )
            ),
        ]
        raw_text2 = _invoke_llm(llm, retry_messages, "analyze-domain")
        try:
            data = _extract_json(raw_text2)
            result = DomainAnalysisResponse(**data)
            logger.info("[analyze-domain] Validação OK na segunda tentativa.")
        except Exception as erro2:
            logger.error("[analyze-domain] Tentativa 2 falhou: %s.", erro2)
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


@app.post("/assign-weights", dependencies=[Depends(_verify_token)])
def assign_weights(
    req: AssignWeightsRequest,
    x_gemini_api_key: str = Header(default=""),
) -> AssignWeightsResponse:
    """Atribui pesos (-20 a +20) em cada eixo para cada alternativa de cada dilema."""

    # PASSO 1 — Resolver chave de API
    api_key = _resolve_gemini_key(x_gemini_api_key, req.gemini_api_key)
    logger.info("[assign-weights] Atribuindo pesos para %d dilema(s).", len(req.dilemmas))

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
    llm = generator._get_llm(api_key)
    logger.debug("[assign-weights] Chamando Gemini (tentativa 1)...")
    raw_text = _invoke_llm(llm, messages, "assign-weights")

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
        logger.info("[assign-weights] Pesos atribuídos com sucesso na tentativa 1.")
    except Exception as erro:
        logger.warning("[assign-weights] Tentativa 1 falhou: %s. Reenviando...", erro)
        retry_messages = messages + [
            AIMessage(content=raw_text),
            HumanMessage(
                content=(
                    f"Sua resposta anterior falhou por: {erro}. "
                    f"Corrija e retorne apenas o JSON."
                )
            ),
        ]
        raw_text2 = _invoke_llm(llm, retry_messages, "assign-weights")
        try:
            weighted = _parse_and_merge(raw_text2)
            logger.info("[assign-weights] Pesos atribuídos com sucesso na tentativa 2.")
        except Exception as erro2:
            logger.error("[assign-weights] Tentativa 2 falhou: %s.", erro2)
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


@app.post("/generate-verdict", dependencies=[Depends(_verify_token)])
def generate_verdict(
    req: GenerateVerdictRequest,
    x_gemini_api_key: str = Header(default=""),
) -> GenerateVerdictResponse:
    """Seleciona o perfil mais próximo do vetor do jogador e gera texto narrativo personalizado."""

    # PASSO 1 — Resolver chave de API
    api_key = _resolve_gemini_key(x_gemini_api_key, req.gemini_api_key)
    logger.info("[generate-verdict] Vetor do jogador: %s. Perfis disponíveis: %d.", req.vetor_jogador, len(req.perfis_disponiveis))

    if not req.perfis_disponiveis:
        raise HTTPException(status_code=422, detail="A lista perfis_disponiveis não pode estar vazia.")

    # PASSO 2 — Selecionar perfil mais próximo por distância euclidiana
    perfil_selecionado: ProfileSuggestion = min(
        req.perfis_disponiveis,
        key=lambda p: _distancia_euclidiana(req.vetor_jogador, p.vetor_aproximado),
    )
    logger.info("[generate-verdict] Perfil selecionado: %s.", perfil_selecionado.nome)

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
    llm = generator._get_llm(api_key)
    logger.debug("[generate-verdict] Chamando Gemini (tentativa 1)...")
    raw_text = _invoke_llm(llm, messages, "generate-verdict")

    # PASSO 5 — Validar narrativa
    def _parse_narrativa(raw: str) -> VerdictNarrative:
        data = _extract_json(raw)
        return VerdictNarrative(**data)

    try:
        narrativa = _parse_narrativa(raw_text)
        logger.info("[generate-verdict] Narrativa gerada com sucesso na tentativa 1.")
    except Exception as erro:
        logger.warning("[generate-verdict] Tentativa 1 falhou: %s. Reenviando...", erro)
        retry_messages = messages + [
            AIMessage(content=raw_text),
            HumanMessage(
                content=(
                    f"Sua resposta anterior falhou por: {erro}. "
                    f"Corrija e retorne apenas o JSON.\n\nSchema esperado:\n{_SCHEMA_VERDICT_EXAMPLE}"
                )
            ),
        ]
        raw_text2 = _invoke_llm(llm, retry_messages, "generate-verdict")
        try:
            narrativa = _parse_narrativa(raw_text2)
            logger.info("[generate-verdict] Narrativa gerada com sucesso na tentativa 2.")
        except Exception as erro2:
            logger.error("[generate-verdict] Tentativa 2 falhou: %s.", erro2)
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
