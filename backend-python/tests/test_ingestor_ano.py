"""
Testes do metadado inteiro `ano` no /ingest (pacote H).

O ChromaDB só compara números em filtros, então o ano é guardado como inteiro
para um filtro de período futuro, sem reindexar. Nada na busca usa o campo ainda.
O banco é FALSO: nenhum ChromaDB é aberto.

Rodar a partir de backend-python/:
    python -m unittest tests.test_ingestor_ano -v   (com o .venv ativado)
"""
import os
import unittest
from unittest import mock

os.environ.setdefault("API_SECRET_TOKEN", "token-de-teste")

from app import ingestor
from app.models import IngestRequest, VaultArticle


class BancoFalso:
    def __init__(self):
        self.documentos = []
        self.apagados = []

    def delete(self, where=None):
        self.apagados.append(where)

    def add_documents(self, docs, ids=None):
        self.documentos.extend(docs)


def indexar(artigos):
    banco = BancoFalso()
    req = IngestRequest(site_id="https://exemplo.com.br", articles=artigos)
    with mock.patch.object(ingestor, "get_collection", return_value=banco):
        resultado = ingestor.index_articles(req)
    return banco, resultado


def artigo(id_, date, palavras=400):
    return VaultArticle(id=id_, title=f"T{id_}", content=("palavra " * palavras).strip(),
                        url=f"http://x/{id_}", date=date)


class AnoDoArtigo(unittest.TestCase):
    def test_data_iso_normal(self):
        self.assertEqual(ingestor.ano_do_artigo("2024-05-17T10:30:00"), 2024)
        self.assertEqual(ingestor.ano_do_artigo("2019-12-31"), 2019)

    def test_so_o_ano(self):
        self.assertEqual(ingestor.ano_do_artigo("2021"), 2021)

    def test_data_vazia_ou_ausente(self):
        self.assertEqual(ingestor.ano_do_artigo(""), 0)
        self.assertEqual(ingestor.ano_do_artigo("   "), 0)
        self.assertEqual(ingestor.ano_do_artigo(None), 0)

    def test_texto_invalido(self):
        for texto in ("ontem", "maio de 2024", "05/2024", "20x4-01-01", "abcd"):
            self.assertEqual(ingestor.ano_do_artigo(texto), 0, texto)

    def test_ano_fora_do_intervalo(self):
        for texto in ("1899-12-31", "0000-00-00 00:00:00", "2101-01-01", "9999-01-01", "0001-01-01"):
            self.assertEqual(ingestor.ano_do_artigo(texto), 0, texto)

    def test_limites_do_intervalo_valem(self):
        self.assertEqual(ingestor.ano_do_artigo("1900-01-01"), 1900)
        self.assertEqual(ingestor.ano_do_artigo("2100-12-31"), 2100)

    def test_e_inteiro(self):
        self.assertIs(type(ingestor.ano_do_artigo("2024-01-01")), int)
        self.assertIs(type(ingestor.ano_do_artigo("")), int)


class AnoNosChunks(unittest.TestCase):
    def test_todos_os_chunks_do_mesmo_artigo_recebem_o_mesmo_ano(self):
        banco, res = indexar([artigo(1, "2023-03-10T08:00:00", palavras=1500)])
        self.assertGreater(len(banco.documentos), 3)           # texto longo vira vários trechos
        self.assertEqual({d.metadata["ano"] for d in banco.documentos}, {2023})
        self.assertEqual(res["total_chunks"], len(banco.documentos))

    def test_artigos_diferentes_cada_um_com_o_seu_ano(self):
        banco, _ = indexar([artigo(1, "2020-01-01"), artigo(2, "2024-06-30"), artigo(3, "")])
        por_artigo = {}
        for d in banco.documentos:
            por_artigo.setdefault(d.metadata["wp_id"], set()).add(d.metadata["ano"])
        self.assertEqual(por_artigo, {"1": {2020}, "2": {2024}, "3": {0}})

    def test_metadado_ano_e_inteiro_no_chunk(self):
        banco, _ = indexar([artigo(1, "2022-02-02")])
        self.assertTrue(all(type(d.metadata["ano"]) is int for d in banco.documentos))

    def test_o_metadado_data_continua_igual(self):
        banco, _ = indexar([artigo(1, "2022-02-02T12:00:00"), artigo(2, "texto qualquer")])
        datas = {d.metadata["wp_id"]: d.metadata["data"] for d in banco.documentos}
        self.assertEqual(datas, {"1": "2022-02-02T12:00:00", "2": "texto qualquer"})

    def test_os_outros_metadados_nao_mudaram(self):
        banco, _ = indexar([artigo(7, "2022-02-02")])
        chaves = set(banco.documentos[0].metadata)
        self.assertEqual(chaves, {"titulo", "source_url", "data", "ano", "wp_id", "type"})


if __name__ == "__main__":
    unittest.main()
