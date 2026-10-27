"""
Testes do filtro "ano mínimo" (plugin 1.2.0): rag.mmr_search(), os modelos e as rotas.

- composição do `where`: nenhum / só ano / só `$nin` / os dois com `$and`;
- o relaxamento da exclusão de fontes relaxa SÓ o `$nin`: o ano nunca sai;
- `ano_minimo` validado em 1900–2100 (ou nulo);
- coleção TEMPORÁRIA REAL do ChromaDB (pasta em %TEMP%, embeddings falsos, sem rede);
- rotas: 409 com `codigo` + `detail` nas duas rotas, SEM chamar o provedor;
- /health com api_compat 3.

Rodar a partir de backend-python/:
    .\\.venv\\Scripts\\python.exe -m unittest tests.test_rag_ano -v
"""
import os
import re
import shutil
import tempfile
import unittest
from unittest import mock

os.environ.setdefault("API_SECRET_TOKEN", "token-de-teste")

from fastapi.testclient import TestClient
from langchain_community.vectorstores import Chroma
from langchain_core.documents import Document
from langchain_core.embeddings import DeterministicFakeEmbedding
from pydantic import ValidationError

from app import generator, main, rag, regenerator
from app.models import GenerateRequest, RegenerateRequest


def _doc(i, ano, com_ano=True):
    meta = {"titulo": f"T{i}", "source_url": f"https://exemplo.org/{i}", "wp_id": str(i)}
    if com_ano:
        meta["ano"] = ano
    return Document(page_content=f"trecho {i} sobre teatro e cultura", metadata=meta)


class TestComposicaoDoFiltro(unittest.TestCase):

    def test_nenhum(self):
        self.assertIsNone(rag._filtro(None, []))

    def test_so_ano(self):
        self.assertEqual(rag._filtro(2020, []), {"ano": {"$gte": 2020}})

    def test_so_fontes(self):
        self.assertEqual(rag._filtro(None, ["u1"]), {"source_url": {"$nin": ["u1"]}})

    def test_os_dois_com_and(self):
        self.assertEqual(
            rag._filtro(2020, ["u1"]),
            {"$and": [{"ano": {"$gte": 2020}}, {"source_url": {"$nin": ["u1"]}}]},
        )


class BancoFalso:
    """Registra o filtro de cada chamada. Simula: com `$nin` de TODAS as fontes não
    sobra nada; sem `$nin`, devolve o acervo."""

    def __init__(self, docs):
        self.docs = docs
        self.filtros = []

    def max_marginal_relevance_search(self, query, k, fetch_k, filter=None):
        self.filtros.append(filter)
        tem_nin = filter and ("source_url" in filter or "$and" in filter)
        return [] if tem_nin else list(self.docs)


class TestRelaxamentoMantemOAno(unittest.TestCase):

    def test_relaxa_so_o_nin(self):
        db = BancoFalso([_doc(1, 2024)])
        with self.assertLogs("app.rag", level="WARNING"):
            contexto = rag.mmr_search(db, ["q"], fetch_k=4, k=2,
                                      fontes_usadas=["https://exemplo.org/1"], ano_minimo=2020)
        self.assertIn("SOURCE_URL: https://exemplo.org/1", contexto)
        self.assertEqual(len(db.filtros), 2)
        self.assertEqual(db.filtros[0]["$and"][0], {"ano": {"$gte": 2020}})
        # O segundo passo (relaxado) ainda traz o ano, e só ele.
        self.assertEqual(db.filtros[1], {"ano": {"$gte": 2020}})

    def test_sem_ano_o_relaxamento_continua_sem_filtro(self):
        db = BancoFalso([_doc(1, 2024)])
        with self.assertLogs("app.rag", level="WARNING"):
            rag.mmr_search(db, ["q"], fetch_k=4, k=2, fontes_usadas=["https://exemplo.org/1"])
        self.assertIsNone(db.filtros[1])


class BancoQuebrado:
    """Falha em todas as queries, ou só nas primeiras `quebrar` (falha parcial)."""

    def __init__(self, docs=(), quebrar=None):
        self.docs, self.quebrar, self.chamadas = list(docs), quebrar, 0

    def max_marginal_relevance_search(self, query, k, fetch_k, filter=None):
        self.chamadas += 1
        if self.quebrar is None or self.chamadas <= self.quebrar:
            raise RuntimeError("chroma quebrou")
        return list(self.docs)


class TestBuscaQueFalha(unittest.TestCase):

    def test_todas_falham_com_ano_levanta_busca_falhou_e_nao_409(self):
        db = BancoQuebrado()
        with self.assertLogs("app.rag", level="WARNING") as cm,                 self.assertRaises(rag.BuscaFalhouNoAcervo) as ctx:
            rag.mmr_search(db, ["q1", "q2"], fetch_k=4, k=2, ano_minimo=2020)
        self.assertEqual(ctx.exception.codigo, "busca_falhou")
        self.assertNotIsInstance(ctx.exception, rag.SemTrechosNoPeriodo)
        falhas = [l for l in cm.output if "mmr_search falhou" in l]
        self.assertEqual(len(falhas), 2)
        self.assertIn("chroma quebrou", falhas[0])   # a mensagem da exceção vai ao log

    def test_falha_parcial_segue_com_resultado(self):
        db = BancoQuebrado([_doc(1, 2024)], quebrar=1)
        with self.assertLogs("app.rag", level="WARNING"):
            ctx = rag.mmr_search(db, ["q1", "q2"], fetch_k=4, k=2, ano_minimo=2020)
        self.assertIn("SOURCE_URL: https://exemplo.org/1", ctx)

    def test_sem_ano_o_comportamento_antigo_fica_igual(self):
        db = BancoQuebrado()
        with self.assertLogs("app.rag", level="WARNING"):
            self.assertEqual(rag.mmr_search(db, ["q1", "q2"], fetch_k=4, k=2), "")


ERRO_HNSW = "Cannot return the results in a contigious 2D array. Probably ef or M is too small"


def _falha_hnsw(*args, **kwargs):
    raise RuntimeError(ERRO_HNSW)


class ColecaoFalsa:
    def __init__(self, ids, docs, metas):
        self.ids, self.docs, self.metas, self.chamadas = ids, docs, metas, []

    def get(self, where=None, ids=None, include=None, limit=None):
        self.chamadas.append({"where": where, "ids": ids, "include": include})
        if ids is None:
            return {"ids": list(self.ids)}
        pos = [self.ids.index(i) for i in ids]
        return {"ids": list(ids), "documents": [self.docs[p] for p in pos],
                "metadatas": [self.metas[p] for p in pos]}


class BancoSemSimilaridade:
    """A busca por similaridade estoura como o hnswlib; `_collection` serve a leitura direta."""

    def __init__(self, colecao):
        self._collection = colecao

    def max_marginal_relevance_search(self, query, k, fetch_k, filter=None):
        raise RuntimeError(ERRO_HNSW)


class TestModelo(unittest.TestCase):

    BASE = dict(site_id="s", instance_id=0, theme_prompt="t", density=4)

    def test_limites_na_geracao(self):
        for ok in (1900, 2100, 2020, None):
            self.assertEqual(GenerateRequest(**self.BASE, ano_minimo=ok).ano_minimo, ok)
        for ruim in (1899, 2101):
            with self.assertRaises(ValidationError):
                GenerateRequest(**self.BASE, ano_minimo=ruim)

    def test_ausente_e_nulo(self):
        self.assertIsNone(GenerateRequest(**self.BASE).ano_minimo)
        self.assertIsNone(RegenerateRequest(site_id="s", instance_id=0, theme_prompt="t").ano_minimo)

    def test_limites_na_substituta(self):
        base = dict(site_id="s", instance_id=0, theme_prompt="t")
        for ok in (1900, 2100, None):
            self.assertEqual(RegenerateRequest(**base, ano_minimo=ok).ano_minimo, ok)
        for ruim in (1899, 2101):
            with self.assertRaises(ValidationError):
                RegenerateRequest(**base, ano_minimo=ruim)


class ChromaTemporario(unittest.TestCase):
    """Coleção REAL do ChromaDB numa pasta temporária. Nunca a coleção do editor."""

    def criar(self, docs, nome="teste_ano"):
        pasta = tempfile.mkdtemp(prefix="ng-teste-ano-")
        self.addCleanup(shutil.rmtree, pasta, True)
        db = Chroma(persist_directory=pasta, embedding_function=DeterministicFakeEmbedding(size=16),
                    collection_name=nome)
        self.addCleanup(lambda: db._client.clear_system_cache())
        if docs:
            db.add_documents(docs, ids=[f"{d.metadata['wp_id']}-0" for d in docs])
        return db


class TestBuscaReal(ChromaTemporario):

    ANOS = [0, 2015, 2021, 2024]

    def acervo(self):
        return self.criar([_doc(i, a) for i, a in enumerate(self.ANOS)])

    def urls(self, contexto):
        return sorted(set(__import__("re").findall(r"SOURCE_URL: (\S+)", contexto)))

    def test_so_2021_e_2024(self):
        db = self.acervo()
        ctx = rag.mmr_search(db, ["teatro"], fetch_k=10, k=5, ano_minimo=2020)
        self.assertEqual(self.urls(ctx), ["https://exemplo.org/2", "https://exemplo.org/3"])

    def test_ano_e_exclusao_juntos(self):
        db = self.acervo()
        ctx = rag.mmr_search(db, ["teatro"], fetch_k=10, k=5, ano_minimo=2020,
                             fontes_usadas=["https://exemplo.org/2"])
        self.assertEqual(self.urls(ctx), ["https://exemplo.org/3"])

    def test_exclusao_esgotada_relaxa_mas_mantem_o_ano(self):
        db = self.acervo()
        with self.assertLogs("app.rag", level="WARNING"):
            ctx = rag.mmr_search(db, ["teatro"], fetch_k=10, k=5, ano_minimo=2020,
                                 fontes_usadas=["https://exemplo.org/2", "https://exemplo.org/3"])
        self.assertEqual(self.urls(ctx), ["https://exemplo.org/2", "https://exemplo.org/3"])

    def test_sem_filtro_traz_tudo(self):
        ctx = rag.mmr_search(self.acervo(), ["teatro"], fetch_k=10, k=5)
        self.assertEqual(len(self.urls(ctx)), 4)

    def test_nada_no_periodo(self):
        with self.assertRaises(rag.SemTrechosNoPeriodo) as cm:
            rag.mmr_search(self.acervo(), ["teatro"], fetch_k=10, k=5, ano_minimo=2030)
        self.assertEqual(cm.exception.codigo, "sem_trechos_no_periodo")
        self.assertIn("2030", cm.exception.detail)

    def test_indice_sem_o_metadado_ano(self):
        db = self.criar([_doc(i, 0, com_ano=False) for i in range(3)])
        with self.assertRaises(rag.SemTrechosNoPeriodo) as cm:
            rag.mmr_search(db, ["teatro"], fetch_k=10, k=5, ano_minimo=2020)
        self.assertEqual(cm.exception.codigo, "indice_sem_ano")
        self.assertIn("Indexar Acervo no Backend", cm.exception.detail)

    def test_trechos_com_ano_mas_sem_url_nao_viram_409(self):
        # A busca voltou com trechos (ano 2024) que não têm URL de origem: não é
        # "nada no período". O contexto fica vazio e a rota segue no 422 de antes.
        docs = [Document(page_content=f"trecho {i} sobre teatro", metadata={"titulo": f"T{i}", "ano": 2024, "wp_id": str(i)})
                for i in range(3)]
        db = self.criar(docs)
        self.assertEqual(rag.mmr_search(db, ["teatro"], fetch_k=10, k=5, ano_minimo=2020), "")

    def test_acervo_vazio_continua_vazio(self):
        # Sem nada indexado, o comportamento de antes (contexto vazio, 422) continua.
        db = self.criar([])
        self.assertEqual(rag.mmr_search(db, ["teatro"], fetch_k=10, k=5, ano_minimo=2020), "")


class TestLeituraDireta(ChromaTemporario):

    def urls(self, ctx):
        return sorted(set(re.findall(r"SOURCE_URL: (\S+)", ctx)))

    def test_a_banco_falso_le_o_periodo_com_o_mesmo_filtro(self):
        col = ColecaoFalsa(["a", "b"], ["texto a", "texto b"],
                           [{"source_url": "https://exemplo.org/a", "titulo": "A", "ano": 2022},
                            {"source_url": "https://exemplo.org/b", "titulo": "B", "ano": 2024}])
        db = BancoSemSimilaridade(col)
        with self.assertLogs("app.rag", level="WARNING") as cm:
            ctx = rag.mmr_search(db, ["q1", "q2"], fetch_k=4, k=2,
                                 fontes_usadas=["https://exemplo.org/z"], ano_minimo=2020)
        self.assertEqual(self.urls(ctx), ["https://exemplo.org/a", "https://exemplo.org/b"])
        esperado = {"$and": [{"ano": {"$gte": 2020}}, {"source_url": {"$nin": ["https://exemplo.org/z"]}}]}
        self.assertEqual(col.chamadas[0]["where"], esperado)
        avisos = [l for l in cm.output
                  if "leitura direta do período (sem ordenação por tema): 2 trecho(s)" in l]
        self.assertEqual(len(avisos), 1)

    def test_b_colecao_real_so_traz_o_periodo_e_respeita_o_nin(self):
        db = self.criar([_doc(i, a) for i, a in enumerate([0, 2015, 2021, 2024])])
        db.max_marginal_relevance_search = _falha_hnsw
        with self.assertLogs("app.rag", level="WARNING"):
            ctx = rag.mmr_search(db, ["teatro"], fetch_k=10, k=5, ano_minimo=2020)
        self.assertEqual(self.urls(ctx), ["https://exemplo.org/2", "https://exemplo.org/3"])
        with self.assertLogs("app.rag", level="WARNING"):
            ctx = rag.mmr_search(db, ["teatro"], fetch_k=10, k=5, ano_minimo=2020,
                                 fontes_usadas=["https://exemplo.org/2"])
        self.assertEqual(self.urls(ctx), ["https://exemplo.org/3"])

    def test_c_teto_de_100_ids_sorteados(self):
        docs = [_doc(i, 2022) for i in range(150)] + [_doc(i, 2010) for i in range(150, 160)]
        db = self.criar(docs)
        db.max_marginal_relevance_search = _falha_hnsw
        real = db._collection

        class Espia:
            def __init__(self):
                self.gets = []

            def get(self, **kw):
                self.gets.append(kw)
                return real.get(**kw)

            def __getattr__(self, nome):
                return getattr(real, nome)

        espia = Espia()
        db._collection = espia
        with self.assertLogs("app.rag", level="WARNING"):
            ctx = rag.mmr_search(db, ["teatro"], fetch_k=10, k=5, ano_minimo=2020)
        self.assertEqual(len(espia.gets[0]["include"]), 0)               # 1º get: só ids
        self.assertEqual(len(espia.gets[1]["ids"]), rag.LIMITE_LEITURA_DIRETA)   # 2º get: no máximo 100
        self.assertTrue(ctx)
        self.assertTrue(all(int(u.rsplit("/", 1)[1]) < 150 for u in self.urls(ctx)))

    def test_d_leitura_direta_tambem_falha(self):
        class SemColecao:
            max_marginal_relevance_search = staticmethod(_falha_hnsw)

        class ColecaoQueFalha:
            def get(self, **kw):
                raise RuntimeError("get quebrou")

        for db in (SemColecao(), BancoSemSimilaridade(ColecaoQueFalha())):
            with self.assertLogs("app.rag", level="WARNING"), self.assertRaises(rag.BuscaFalhouNoAcervo):
                rag.mmr_search(db, ["q"], fetch_k=4, k=2, ano_minimo=2020)

    def test_e_leitura_direta_vazia_decide_entre_os_409(self):
        db = self.criar([_doc(i, a) for i, a in enumerate([0, 2015])])
        db.max_marginal_relevance_search = _falha_hnsw
        with self.assertLogs("app.rag", level="WARNING"), self.assertRaises(rag.SemTrechosNoPeriodo) as cm:
            rag.mmr_search(db, ["teatro"], fetch_k=10, k=5, ano_minimo=2030)
        self.assertEqual(cm.exception.codigo, "sem_trechos_no_periodo")

    def test_sem_ano_nao_usa_leitura_direta(self):
        col = ColecaoFalsa(["a"], ["t"], [{"source_url": "https://exemplo.org/a", "ano": 2022}])
        db = BancoSemSimilaridade(col)
        with self.assertLogs("app.rag", level="WARNING"):
            self.assertEqual(rag.mmr_search(db, ["q"], fetch_k=4, k=2), "")
        self.assertEqual(col.chamadas, [])


class TestRotas(ChromaTemporario):

    def setUp(self):
        main.app.dependency_overrides[main._verify_token] = lambda: None
        self.cliente = TestClient(main.app)
        self.addCleanup(main.app.dependency_overrides.clear)

    def corpo(self, **extra):
        base = dict(site_id="http://teste.local", instance_id=0, theme_prompt="teatro", density=4)
        base.update(extra)
        return base

    def chamar(self, rota, db, **extra):
        llm = mock.Mock()
        corpo = self.corpo(**extra)
        if rota == "/regenerate":
            corpo.pop("density")
        with mock.patch.object(generator, "get_collection", return_value=db), \
             mock.patch.object(regenerator, "get_collection", return_value=db), \
             mock.patch.object(generator, "_get_llm", return_value=llm), \
             mock.patch.object(regenerator, "_get_llm", return_value=llm), \
             mock.patch.object(main, "_resolve_api_key", return_value="chave-falsa"):
            r = self.cliente.post(rota, json=corpo)
        return r, llm

    def test_409_nas_duas_rotas_sem_chamar_o_provedor(self):
        sem_ano = self.criar([_doc(i, 0, com_ano=False) for i in range(2)])
        com_ano = self.criar([_doc(1, 2015)], nome="outra")
        for rota in ("/generate-one", "/regenerate"):
            r, llm = self.chamar(rota, sem_ano, ano_minimo=2020)
            self.assertEqual(r.status_code, 409, rota)
            self.assertEqual(r.json()["codigo"], "indice_sem_ano")
            self.assertIn("Reindexe", r.json()["detail"])
            llm.invoke.assert_not_called()
            r, llm = self.chamar(rota, com_ano, ano_minimo=2020)
            self.assertEqual(r.status_code, 409, rota)
            self.assertEqual(r.json()["codigo"], "sem_trechos_no_periodo")
            self.assertIn("2020", r.json()["detail"])
            llm.invoke.assert_not_called()

    def test_busca_falhou_vira_500_com_codigo_nas_duas_rotas(self):
        for rota in ("/generate-one", "/regenerate"):
            with self.assertLogs("app.rag", level="WARNING"):
                r, llm = self.chamar(rota, BancoQuebrado(), ano_minimo=2020)
            self.assertEqual(r.status_code, 500, rota)
            self.assertEqual(r.json(), {"codigo": "busca_falhou",
                                        "detail": "A busca no acervo falhou; veja o log do backend."})
            llm.invoke.assert_not_called()

    def test_d_rotas_500_quando_a_leitura_direta_tambem_falha(self):
        class SemColecao:
            max_marginal_relevance_search = staticmethod(_falha_hnsw)

        for rota in ("/generate-one", "/regenerate"):
            with self.assertLogs("app.rag", level="WARNING"):
                r, llm = self.chamar(rota, SemColecao(), ano_minimo=2020)
            self.assertEqual(r.status_code, 500, rota)
            self.assertEqual(r.json()["codigo"], "busca_falhou")
            llm.invoke.assert_not_called()

    def test_ano_fora_do_intervalo_e_422(self):
        r, llm = self.chamar("/generate-one", object(), ano_minimo=1899)
        self.assertEqual(r.status_code, 422)
        llm.invoke.assert_not_called()

    def test_health_nivel_3(self):
        r = self.cliente.get("/health")
        self.assertEqual(r.json()["api_compat"], 3)


if __name__ == "__main__":
    unittest.main()
