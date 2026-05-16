"""Configurações lidas de variáveis de ambiente (.env ou Cloud Run secrets)."""
import os
from functools import lru_cache
from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    gemini_api_key: str
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
    # Indexação
    chunk_size: int = 1000
    chunk_overlap: int = 150
    batch_size_index: int = 200
    # Segurança
    api_secret_token: str = "REDIGIDO"

    model_config = {"env_file": ".env"}


@lru_cache()
def get_settings() -> Settings:
    return Settings()
