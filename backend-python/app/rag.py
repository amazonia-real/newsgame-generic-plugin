"""Utilitários compartilhados de ChromaDB e embeddings."""
from __future__ import annotations
import logging
from functools import lru_cache
from langchain_huggingface import HuggingFaceEmbeddings
from langchain_community.vectorstores import Chroma
from langchain_core.documents import Document
from langchain_text_splitters import RecursiveCharacterTextSplitter
from app.config import get_settings

logger = logging.getLogger(__name__)


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
    chroma_dir = get_settings().chroma_db_dir
    if not os.path.exists(chroma_dir):
        raise RuntimeError(
            f"CHROMA_DB_DIR '{chroma_dir}' não existe. "
            "Verifique o .env antes de iniciar o servidor."
        )

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


# Teto de trechos da leitura direta do período (fallback da busca por similaridade).
LIMITE_LEITURA_DIRETA = 100


class SemTrechosNoPeriodo(Exception):
    """Filtro de ano ativo e nenhum trecho utilizável. A rota responde 409 com
    `codigo` e `detail`, ANTES de qualquer chamada ao provedor (não gasta cota).
    Dois códigos: `indice_sem_ano` (índice criado por versão antiga, sem o
    metadado) e `sem_trechos_no_periodo` (o índice tem ano, mas nada atende)."""

    def __init__(self, codigo: str, detail: str):
        super().__init__(detail)
        self.codigo = codigo
        self.detail = detail


class BuscaFalhouNoAcervo(Exception):
    """Com o ano mínimo ativo, TODAS as queries da busca falharam (exceção engolida em
    cada uma). Não é "nada no período": responder 409 afirmaria uma causa falsa. A rota
    responde 500 com `codigo` `busca_falhou`, antes de qualquer chamada ao provedor."""

    codigo = "busca_falhou"
    detail = "A busca no acervo falhou; veja o log do backend."


def _filtro(ano_minimo: int | None, fontes: list[str]) -> dict | None:
    """Monta o `where` do Chroma: nenhum, só ano, só `$nin` de fontes, ou os dois.

    ⚠️ Dois campos no MESMO dicionário dão ValueError no Chroma ("exactly one
    operator"); com os dois filtros ativos o `$and` é obrigatório. E o filtro
    `$gte` exclui em silêncio o trecho sem o metadado `ano` (TECH_FLAGS).
    """
    partes = []
    if ano_minimo is not None:
        partes.append({"ano": {"$gte": ano_minimo}})
    if fontes:
        partes.append({"source_url": {"$nin": fontes}})
    if not partes:
        return None
    return partes[0] if len(partes) == 1 else {"$and": partes}


def _erro_de_periodo(db, ano_minimo: int) -> SemTrechosNoPeriodo | None:
    """Explica por que o filtro de ano não trouxe nada. None = acervo vazio de
    verdade (o comportamento de antes, 422, continua valendo)."""
    col = db._collection
    if col.count() == 0:
        return None
    if not col.get(where={"ano": {"$gte": 0}}, limit=1)["ids"]:
        return SemTrechosNoPeriodo(
            "indice_sem_ano",
            "Este acervo foi indexado por uma versão antiga e não tem a informação de ano. "
            'Reindexe em "Indexar Acervo no Backend" para usar o ano mínimo.',
        )
    return SemTrechosNoPeriodo(
        "sem_trechos_no_periodo",
        f"Nenhum trecho do acervo tem ano igual ou maior que {ano_minimo}. "
        "Confira o ano mínimo da instância.",
    )


def _buscar(db: Chroma, queries: list[str], k: int, fetch_k: int, filter_dict: dict | None,
            estrito: bool = False) -> list:
    """O laço de MMR por query, extraído sem mudança de mmr_search().

    `estrito` (só com o ano mínimo ativo): se TODAS as queries falharem, levanta
    BuscaFalhouNoAcervo em vez de devolver lista vazia. Falha parcial segue como antes."""
    all_chunks = []
    falhas = 0
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
        except Exception as exc:
            # Erro pontual de busca não interrompe as demais queries (M4)
            logger.warning("[rag] mmr_search falhou para query %r: %r", query, exc)
            falhas += 1
    if estrito and queries and falhas == len(queries):
        raise BuscaFalhouNoAcervo()
    return all_chunks


def _ler_periodo(db, filtro: dict) -> list:
    """Leitura direta dos trechos que passam no filtro, sem busca por similaridade.

    Só os ids primeiro; acima de LIMITE_LEITURA_DIRETA, sorteia a amostra entre os ids
    (limit/offset viesariam para os textos mais antigos: a indexação vai do mais velho
    ao mais novo). Depois lê documentos e metadados dessa amostra. Qualquer falha,
    inclusive db sem `_collection`, vira BuscaFalhouNoAcervo."""
    try:
        col = db._collection
        ids = col.get(where=filtro, include=[])["ids"]
        if len(ids) > LIMITE_LEITURA_DIRETA:
            ids = random.sample(ids, LIMITE_LEITURA_DIRETA)
        if not ids:
            return []
        lido = col.get(ids=ids, include=["documents", "metadatas"])
        return [Document(page_content=texto, metadata=meta or {})
                for texto, meta in zip(lido["documents"], lido["metadatas"])]
    except Exception as exc:
        logger.warning("[rag] a leitura direta do período também falhou: %r", exc)
        raise BuscaFalhouNoAcervo() from exc


def _buscar_ou_ler(db, queries: list[str], k: int, fetch_k: int, filter_dict: dict | None,
                   ano_minimo: int | None) -> list:
    """Ponto único da busca. Sem ano mínimo é o `_buscar` de sempre. Com ele, se TODAS as
    queries falharem (o hnswlib estoura com filtro estreito mesmo havendo trechos),
    lê o período direto com o MESMO filtro da chamada, sem ordenar por tema."""
    if ano_minimo is None:
        return _buscar(db, queries, k, fetch_k, filter_dict)
    try:
        return _buscar(db, queries, k, fetch_k, filter_dict, estrito=True)
    except BuscaFalhouNoAcervo:
        chunks = _ler_periodo(db, filter_dict)
        logger.warning(
            "[rag] a busca por similaridade falhou com o filtro de ano; usando leitura "
            "direta do período (sem ordenação por tema): %d trecho(s).", len(chunks),
        )
        return chunks


def _tem_url(chunks: list) -> bool:
    """True se algum trecho tem URL rastreável — o mesmo filtro do laço de montagem."""
    return any(doc.metadata.get("source_url", "").startswith("http") for doc in chunks)

def mmr_search(db: Chroma, queries: list[str], fetch_k: int = 40, k: int = 5,
               fontes_usadas: list[str] | None = None, ano_minimo: int | None = None) -> str:
    """
    MMR search em múltiplas queries — garante diversidade temática.
    Exclui via filtro $nin no banco de dados vetorial as matérias já pregressas (histórico do frontend).
    Retorna string de contexto formatada para injeção no prompt do Gemini.
    """
    cfg = get_settings()
    seen: set[str] = set()
    fontes = fontes_usadas or []
    filter_dict = _filtro(ano_minimo, fontes)

    # Acumulador temporário de blocos recuperados para posterior amostragem
    all_chunks = _buscar_ou_ler(db, queries, k, fetch_k, filter_dict, ano_minimo)

    # Exclusão que esgota o acervo RELAXA em vez de falhar o slot: num acervo
    # pequeno, repetir uma fonte é melhor que perder a pergunta. Sem isto o
    # contexto volta vazio, generate_one_dilema() devolve None e a rota dá 422 —
    # e retentar não adianta, porque a exclusão só cresce. Decidido em outubro.
    #
    # O critério é "algum trecho com URL rastreável", e não "algum resultado":
    # o laço abaixo descarta os trechos sem `http`, e esvaziaria o contexto mesmo
    # com resultados.
    # Relaxa SÓ o `$nin`: o filtro de ano nunca é removido.
    if fontes and not _tem_url(all_chunks):
        logger.warning(
            "[rag] a exclusão de %d fonte(s) esgotou o acervo; buscando sem exclusão.",
            len(fontes),
        )
        all_chunks = _buscar_ou_ler(db, queries, k, fetch_k, _filtro(ano_minimo, []), ano_minimo)

    # Com o ano mínimo ativo, a busca VAZIA nunca recua para "sem filtro": avisa.
    # Só `not all_chunks`: trecho que voltou (com ano válido) mas sem URL de origem
    # não é "nada no período", e segue o caminho de antes (contexto vazio, 422).
    if ano_minimo is not None and not all_chunks:
        erro = _erro_de_periodo(db, ano_minimo)
        if erro is not None:
            raise erro

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
