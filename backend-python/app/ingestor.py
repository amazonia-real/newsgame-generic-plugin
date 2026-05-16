"""Lógica do endpoint /ingest — chunking, embedding e indexação no ChromaDB."""
from __future__ import annotations
from langchain_core.documents import Document
from app.models import IngestRequest
from app.rag import get_collection, make_splitter
from app.config import get_settings


def index_articles(req: IngestRequest) -> dict:
    """
    Indexa artigos do vault WP no ChromaDB.
    Operação idempotente: re-indexar o mesmo artigo só duplica chunks,
    mas o MMR de diversidade mitiga o impacto na qualidade do RAG.
    """
    cfg      = get_settings()
    db       = get_collection(req.site_id)
    splitter = make_splitter()

    batch: list[Document] = []
    indexed   = 0
    skipped   = 0
    total_chunks = 0

    for article in req.articles:
        content = (article.content or "").strip()
        if len(content) < 100:
            skipped += 1
            continue

        doc = Document(
            page_content=content,
            metadata={
                "titulo":     article.title,
                "source_url": article.url,
                "data":       article.date,
                "wp_id":      str(article.id),
                "type":       article.type,
            },
        )
        chunks = splitter.split_documents([doc])
        batch.extend(chunks)
        total_chunks += len(chunks)
        indexed += 1

        if len(batch) >= cfg.batch_size_index:
            db.add_documents(batch)
            batch = []

    if batch:
        db.add_documents(batch)

    return {
        "indexed":      indexed,
        "skipped":      skipped,
        "total_chunks": total_chunks,
        "collection":   ("ng_" + req.site_id)[:63],
    }
