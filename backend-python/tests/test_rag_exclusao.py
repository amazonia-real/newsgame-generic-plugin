"""
Testes do relaxamento da exclusão de fontes em rag.mmr_search().

Motivo: com `fontes_usadas` cobrindo todo o acervo, o filtro `$nin` devolvia zero
trechos, o contexto ficava vazio e o slot falhava com 422 — e retentar não
adiantava, porque a exclusão só cresce. Decidido em outubro: relaxar a exclusão
em vez de falhar o slot.

O banco é FALSO: um objeto com o mesmo `max_marginal_relevance_search()` do Chroma,
que registra o filtro de cada chamada. Nenhum ChromaDB é aberto.

Rodar a partir de backend-python/:
    .\\.venv\\Scripts\\python.exe -m unittest tests.test_rag_exclusao -v
"""
import unittest

from langchain_core.documents import Document

from app import rag

LOGGER = "app.rag"


def _doc(url, texto):
    return Document(page_content=texto, metadata={"source_url": url, "titulo": "t"})


class BancoFalso:
    """Simula o `$nin`: com filtro, só devolve trechos cuja URL não foi excluída."""

    def __init__(self, docs):
        self.docs = docs
        self.filtros = []

    def max_marginal_relevance_search(self, query, k, fetch_k, filter=None):
        self.filtros.append(filter)
        if not filter:
            return list(self.docs)
        excluidas = set(filter["source_url"]["$nin"])
        return [d for d in self.docs if d.metadata["source_url"] not in excluidas]


ACERVO = [_doc("https://exemplo.org/a", "trecho A"), _doc("https://exemplo.org/b", "trecho B")]


class TestRelaxamentoDaExclusao(unittest.TestCase):

    def test_exclusao_que_esgota_o_acervo_relaxa(self):
        db = BancoFalso(ACERVO)
        todas = [d.metadata["source_url"] for d in ACERVO]
        with self.assertLogs(LOGGER, level="WARNING") as cm:
            contexto = rag.mmr_search(db, ["q1", "q2"], fetch_k=4, k=2, fontes_usadas=todas)
        self.assertIn("SOURCE_URL: https://exemplo.org/", contexto)
        # Duas queries com filtro, depois as mesmas duas sem filtro.
        self.assertEqual(len(db.filtros), 4)
        self.assertIsNotNone(db.filtros[0])
        self.assertIsNone(db.filtros[2])
        self.assertEqual(len(cm.output), 1)
        self.assertIn("2 fonte(s) esgotou o acervo", cm.output[0])

    def test_exclusao_parcial_nao_relaxa(self):
        db = BancoFalso(ACERVO)
        with self.assertNoLogs(LOGGER, level="WARNING"):
            contexto = rag.mmr_search(db, ["q1"], fetch_k=4, k=2,
                                      fontes_usadas=["https://exemplo.org/a"])
        self.assertIn("https://exemplo.org/b", contexto)
        self.assertNotIn("https://exemplo.org/a", contexto)
        self.assertEqual(len(db.filtros), 1)

    def test_sem_exclusao_busca_uma_vez_sem_filtro(self):
        db = BancoFalso(ACERVO)
        with self.assertNoLogs(LOGGER, level="WARNING"):
            contexto = rag.mmr_search(db, ["q1"], fetch_k=4, k=2, fontes_usadas=[])
        self.assertIn("SOURCE_URL:", contexto)
        self.assertEqual(db.filtros, [None])

    def test_acervo_vazio_de_verdade_continua_vazio(self):
        # Sem nada no banco, relaxar não inventa contexto: o 422 continua correto.
        db = BancoFalso([])
        with self.assertLogs(LOGGER, level="WARNING"):
            contexto = rag.mmr_search(db, ["q1"], fetch_k=4, k=2,
                                      fontes_usadas=["https://exemplo.org/a"])
        self.assertEqual(contexto, "")

    def test_trechos_sem_url_contam_como_esgotado(self):
        # Só sobra trecho sem `http`: o laço de montagem o descartaria e o contexto
        # sairia vazio. Por isso o critério é URL rastreável, e não "algum resultado".
        db = BancoFalso([_doc("", "sem url"), _doc("https://exemplo.org/a", "trecho A")])
        with self.assertLogs(LOGGER, level="WARNING"):
            contexto = rag.mmr_search(db, ["q1"], fetch_k=4, k=2,
                                      fontes_usadas=["https://exemplo.org/a"])
        self.assertIn("https://exemplo.org/a", contexto)


if __name__ == "__main__":
    unittest.main()
