"""
Testes do grupo da geração (item 58): `grupo` no /generate-one.

- grupo="geral" gera pergunta geral SEM personagem no prompt, mesmo em
  instância com personagens (antes, instância com personagens nunca gerava geral);
- grupo=<slug> gera daquele personagem;
- sem grupo, o rodízio por slot_index de antes (plugin antigo e VPS);
- slug fora da lista: 422 na rota, antes de resolver chave ou chamar o provedor.

Rodar a partir de backend-python/:
    .\\.venv\\Scripts\\python.exe -m unittest tests.test_grupo_geracao -v

Só biblioteca padrão (unittest + mock) e o TestClient do FastAPI, que já vem
com o httpx instalado. LLM e ChromaDB são dublês: nada chama provedor nem lê o
acervo.
"""
import unittest
from types import SimpleNamespace
from unittest import mock

from fastapi.testclient import TestClient

from app import generator, main
from app.models import GenerateRequest

PERSONAGENS = [
    {"slug": "diretor", "label": "Diretor de teatro", "description": "Quem dirige a peça"},
    {"slug": "atriz", "label": "Atriz", "description": "Quem atua"},
]

RESPOSTA = (
    '{"titulo": "teste unitario", "situacao": "Cenário de teste:", '
    '"alternativas": [{"letra": "A", "texto": "Faz A"}, {"letra": "B", "texto": "Faz B"}]}'
)


def pedido(**extra):
    base = dict(site_id="http://teste.local", instance_id=0, theme_prompt="tema de teste",
                density=4, characters=PERSONAGENS, num_options=2)
    base.update(extra)
    return GenerateRequest(**base)


class TestEscolherGrupo(unittest.TestCase):

    def test_geral_com_personagens_e_geral_de_verdade(self):
        tipo, rotulo, desc, consultas = generator.escolher_grupo(pedido(grupo="geral", slot_index=1))
        self.assertEqual((tipo, rotulo, desc), ("geral", None, None))
        self.assertEqual(consultas, ["tema de teste", "perspectiva ampla sobre tema de teste"])

    def test_slug_escolhe_o_personagem_e_ignora_o_slot(self):
        tipo, rotulo, _, consultas = generator.escolher_grupo(pedido(grupo="atriz", slot_index=0))
        self.assertEqual((tipo, rotulo), ("atriz", "Atriz"))
        self.assertIn("Quem atua tema de teste", consultas)

    def test_sem_grupo_mantem_o_rodizio(self):
        self.assertEqual(generator.escolher_grupo(pedido(slot_index=0))[0], "diretor")
        self.assertEqual(generator.escolher_grupo(pedido(slot_index=1))[0], "atriz")
        self.assertEqual(generator.escolher_grupo(pedido(slot_index=2))[0], "diretor")

    def test_sem_grupo_e_sem_personagens_e_geral(self):
        self.assertEqual(generator.escolher_grupo(pedido(characters=[]))[0], "geral")

    def test_slug_fora_da_lista_e_recusado(self):
        with self.assertRaises(generator.GrupoInvalido):
            generator.escolher_grupo(pedido(grupo="inexistente"))
        # Sem personagens, só "geral" vale.
        with self.assertRaises(generator.GrupoInvalido):
            generator.escolher_grupo(pedido(grupo="atriz", characters=[]))


class TestGeracaoComLlmSimulado(unittest.TestCase):
    """Passa por generate_one_dilema() inteiro com LLM e ChromaDB falsos e lê o
    prompt que chegaria ao provedor."""

    def gerar(self, req):
        llm = mock.Mock()
        llm.invoke.return_value = SimpleNamespace(content=RESPOSTA)
        busca = mock.Mock(return_value="SOURCE_URL: https://exemplo.org/a\ntexto do acervo")
        with mock.patch.object(generator, "get_collection", return_value=object()), \
             mock.patch.object(generator, "mmr_search", busca), \
             mock.patch.object(generator, "_get_llm", return_value=llm):
            dilema = generator.generate_one_dilema(req, api_key="chave-falsa", provedor="gemini")
        mensagens = llm.invoke.call_args[0][0]
        return dilema, mensagens[1].content, busca.call_args[0][1]

    def test_geral_sem_personagem_no_prompt(self):
        dilema, user, consultas = self.gerar(pedido(grupo="geral"))
        self.assertEqual(dilema.personagem_tipo, "geral")
        self.assertNotIn("Personagem:", user)
        self.assertNotIn("Perspectiva:", user)
        for p in PERSONAGENS:
            self.assertNotIn(p["label"], user)
        self.assertEqual(consultas, ["tema de teste", "perspectiva ampla sobre tema de teste"])

    def test_personagem_entra_no_prompt(self):
        dilema, user, _ = self.gerar(pedido(grupo="diretor", slot_index=1))
        self.assertEqual(dilema.personagem_tipo, "diretor")
        self.assertIn("Personagem: Diretor de teatro", user)
        self.assertIn("Perspectiva: Quem dirige a peça", user)


class TestRota(unittest.TestCase):

    def setUp(self):
        main.app.dependency_overrides[main._verify_token] = lambda: None
        self.cliente = TestClient(main.app)

    def tearDown(self):
        main.app.dependency_overrides.clear()

    def test_slug_desconhecido_da_422_sem_chamar_o_gerador(self):
        corpo = pedido(grupo="inexistente").model_dump()
        with mock.patch.object(generator, "generate_one_dilema") as gerar, \
             mock.patch.object(main, "_resolve_api_key") as chave:
            r = self.cliente.post("/generate-one", json=corpo)
        self.assertEqual(r.status_code, 422)
        self.assertIn("Grupo desconhecido: 'inexistente'", r.json()["detail"])
        gerar.assert_not_called()
        chave.assert_not_called()

    def test_grupo_valido_chega_ao_gerador(self):
        corpo = pedido(grupo="geral").model_dump()
        falso = generator.DilemaNg(**generator._extract_json_dilema(RESPOSTA))
        with mock.patch.object(generator, "generate_one_dilema", return_value=falso) as gerar, \
             mock.patch.object(main, "_resolve_api_key", return_value="chave-falsa"):
            r = self.cliente.post("/generate-one", json=corpo)
        self.assertEqual(r.status_code, 200)
        self.assertEqual(gerar.call_args[0][0].grupo, "geral")


if __name__ == "__main__":
    unittest.main()
