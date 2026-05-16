"""Utilitários compartilhados de ChromaDB e embeddings."""
from __future__ import annotations
from functools import lru_cache
from langchain_huggingface import HuggingFaceEmbeddings
from langchain_community.vectorstores import Chroma
from langchain_text_splitters import RecursiveCharacterTextSplitter
from app.config import get_settings


@lru_cache(maxsize=1)
def get_embeddings() -> HuggingFaceEmbeddings:
    cfg = get_settings()
    return HuggingFaceEmbeddings(model_name=cfg.embed_model)


def make_splitter() -> RecursiveCharacterTextSplitter:
    cfg = get_settings()
    return RecursiveCharacterTextSplitter(
        chunk_size=cfg.chunk_size,
        chunk_overlap=cfg.chunk_overlap,
        separators=["\n\n", "\n", ". ", "! ", "? ", " ", ""],
    )

def get_collection(site_id: str):
    import os
    # Lê a pasta direto do ambiente ou usa um padrão seguro para o Windows caso esteja vazia
    chroma_dir = os.getenv("CHROMA_DB_DIR", "E:/news-game/backend-python/data/chroma_db")

    # Remove http://, https://, barras, pontos e dois pontos para limpar o nome
    clean_name = site_id.replace("https://", "").replace("http://", "")
    clean_name = clean_name.replace("/", "_").replace(".", "_").replace(":", "_")
    
    # Garante o prefixo obrigatório exigido pelo plugin
    collection_name = f"ng_{clean_name}"
    
    # Garante que o nome não passe de 63 caracteres (limite do ChromaDB)
    collection_name = collection_name[:63].strip("_")

    return Chroma(
        persist_directory=chroma_dir,
        embedding_function=get_embeddings(),
        collection_name=collection_name
    )

def mmr_search(db: Chroma, queries: list[str], fetch_k: int = 20, k: int = 5) -> str:
    """
    MMR search em múltiplas queries — garante diversidade temática.
    Retorna string de contexto formatada para injeção no prompt do Gemini.
    Filtra docs sem source_url válida (rigor jornalístico).
    """
    cfg = get_settings()
    seen: set[str] = set()
    trechos: list[str] = []

    for query in queries:
        results = db.max_marginal_relevance_search(query, k=k, fetch_k=fetch_k)
        for doc in results:
            key = doc.page_content[:100]
            if key in seen:
                continue
            seen.add(key)

            url = doc.metadata.get("source_url", "")
            if not url.startswith("http"):
                continue  # descarta chunks sem URL rastreável

            titulo = doc.metadata.get("titulo", "Sem título")
            trechos.append(
                f"TÍTULO: {titulo}\nSOURCE_URL: {url}\nTEXTO:\n{doc.page_content}"
            )

    separator = "\n\n" + ("=" * 60) + "\n\n"
    full_context = separator.join(trechos)
    return full_context[: cfg.rag_context_window]
