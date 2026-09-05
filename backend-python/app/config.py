"""Configurações lidas de variáveis de ambiente (.env ou Cloud Run secrets)."""
import os
from functools import lru_cache
from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    # OPCIONAL desde a fase 0 do H1 (2026-09-04). Era campo sem default, e o
    # servidor não subia sem ele — inconsistente com a chave poder chegar por
    # requisição. Quem valida "existe alguma chave disponível" é
    # `_resolve_gemini_key()` (main.py), que levanta 401 quando os três degraus
    # da cascata — header, body, este campo — estão todos vazios.
    #
    # ⚠️ `api_secret_token`, mais abaixo, CONTINUA OBRIGATÓRIO. A guarda do
    # C3/FLAG 003 (o servidor recusa subir sem token de autenticação) não foi
    # afrouxada por esta mudança — `main.py:43` chama get_settings() no import
    # justamente para falhar cedo, e continua falhando por causa dele.
    #
    # Consequência prática: um deploy que use APENAS chave por requisição
    # (vinda do painel WordPress) não precisa mais de GEMINI_API_KEY no .env.
    gemini_api_key: str = ""
    chroma_db_dir: str = "/data/chroma_db"
    embed_model: str = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
    llm_model: str = "gemini-2.5-flash"
    llm_temperature: float = 0.3
    # RAG
    rag_fetch_k: int = 20
    rag_k: int = 5
    rag_context_window: int = 40000
    # Controle de lotes e rate limit
    max_retries_batch: int = 5
    rate_limit_sleep: int = 60
    batch_sleep: int = 3
    dilema_sleep: float = 1.0   # pausa entre chamadas individuais de dilema
    # Indexação
    chunk_size: int = 1000
    chunk_overlap: int = 150
    batch_size_index: int = 200
    # Segurança
    api_secret_token: str
    # Logging — consumido por logging_config.py via os.getenv("LOG_LEVEL").
    # Declarado aqui para que o .env seja aceito sem erro de campo extra.
    log_level: str = "INFO"
    # Telemetria — consumida pelo próprio ChromaDB via ambiente
    # (load_dotenv em main.py). Declarada aqui pelo mesmo motivo.
    anonymized_telemetry: bool = False

    model_config = {"env_file": ".env"}


@lru_cache()
def get_settings() -> Settings:
    return Settings()
