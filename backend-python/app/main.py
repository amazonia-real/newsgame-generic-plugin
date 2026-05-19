"""FastAPI — entry point do backend News-Game."""
from __future__ import annotations
from fastapi import FastAPI, Depends, HTTPException, Header
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import StreamingResponse
from app.config import get_settings
from app.models import IngestRequest, GenerateRequest, RegenerateRequest
from app import ingestor, generator, regenerator


app = FastAPI(title="News-Game Backend", version="1.0.0")

# Permite chamadas diretas do browser (WordPress admin → backend Python)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["POST", "GET", "OPTIONS"],
    allow_headers=["Content-Type", "X-Api-Key"],
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
    dilema = generator.generate_one_dilema(req)
    if dilema is None:
        raise HTTPException(status_code=422, detail="Não foi possível gerar um dilema válido para este slot.")
    result = dilema.model_dump()
    result["instance_id"] = req.instance_id
    result["position"]    = req.slot_index + 1
    return result


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
