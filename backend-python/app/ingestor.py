"""Lógica do endpoint /ingest — chunking, embedding e indexação no ChromaDB."""
from __future__ import annotations
import re

from langchain_core.documents import Document
from app.models import IngestRequest
from app.rag import get_collection, make_splitter
from app.config import get_settings


ANO_MIN, ANO_MAX = 1900, 2100


def ano_do_artigo(date: str | None) -> int:
    """
    Ano do artigo, como INTEIRO, para o metadado `ano`: os 4 primeiros dígitos de
    `date`, quando formam um ano entre 1900 e 2100. Vazio, texto que não começa
    por 4 dígitos ou ano fora do intervalo dão 0.

    Inteiro porque o ChromaDB só compara números em filtros (`$gte`, `$lte`): é o
    que permite um filtro de período no futuro sem reindexar o acervo. Nada na
    busca usa este campo ainda.
    """
    achado = re.match(r"\d{4}", (date or "").strip())
    if not achado:
        return 0
    ano = int(achado.group())
    return ano if ANO_MIN <= ano <= ANO_MAX else 0


def index_articles(req: IngestRequest) -> dict:
    """
    Indexa artigos do vault WP no ChromaDB.
    Operação idempotente: cada chunk recebe ID determinístico
    ("{wp_id}-{i}") e a inserção é um upsert — re-indexar o mesmo
    artigo substitui os chunks em vez de duplicá-los. Chunks antigos
    excedentes (artigo encurtou) são removidos antes, via delete
    por metadado wp_id.
    """
    cfg      = get_settings()
    db       = get_collection(req.site_id)
    splitter = make_splitter()

    batch:     list[Document] = []
    batch_ids: list[str]      = []
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
                "ano":        ano_do_artigo(article.date),
                "wp_id":      str(article.id),
                "type":       article.type,
            },
        )
        chunks = splitter.split_documents([doc])

        # Remove chunks pré-existentes deste artigo: se o texto encurtou,
        # o upsert sozinho deixaria órfãos os chunks de índice mais alto.
        db.delete(where={"wp_id": str(article.id)})

        batch.extend(chunks)
        batch_ids.extend(f"{article.id}-{i}" for i in range(len(chunks)))
        total_chunks += len(chunks)
        indexed += 1

        if len(batch) >= cfg.batch_size_index:
            db.add_documents(batch, ids=batch_ids)
            batch, batch_ids = [], []

    if batch:
        db.add_documents(batch, ids=batch_ids)

    return {
        "indexed":      indexed,
        "skipped":      skipped,
        "total_chunks": total_chunks,
        "collection":   ("ng_" + req.site_id)[:63],
    }
