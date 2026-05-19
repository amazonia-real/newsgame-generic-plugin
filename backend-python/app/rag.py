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

import random

def mmr_search(db: Chroma, queries: list[str], fetch_k: int = 40, k: int = 5, fontes_usadas: list[str] | None = None) -> str:
    """
    MMR search em múltiplas queries — garante diversidade temática.
    Exclui via filtro $nin no banco de dados vetorial as matérias já pregressas (histórico do frontend).
    Retorna string de contexto formatada para injeção no prompt do Gemini.
    """
    cfg = get_settings()
    seen: set[str] = set()
    fontes = fontes_usadas or []
    filter_dict = {"source_url": {"$nin": fontes}} if fontes else None

    # Acumulador temporário de blocos recuperados para posterior amostragem
    all_chunks = []

    for query in queries:
        try:
            # Buscando o dobro (k*2 e fetch_k adaptado) para aumentar o alcance
            results = db.max_marginal_relevance_search(
                query, 
                k=k * 2, 
                fetch_k=fetch_k, 
                filter=filter_dict
            )
            all_chunks.extend(results)
        except Exception:
            pass  # Ignorar erros pontuais de busca
            
    # Amostragem estocástica (Random Sampling)
    # Garante orgânica na recuperação reduzida
    if len(all_chunks) > k:
        all_chunks = random.sample(all_chunks, k=min(len(all_chunks), k * 2))

    trechos: list[str] = []
    
    for doc in all_chunks:
        # Prevenção de repeteco entre chunks internos próximos
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
