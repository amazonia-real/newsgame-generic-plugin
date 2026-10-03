"""
Testes do texto_da_resposta() — a normalização do `.content` de resposta de LLM.

Motivo: em 2026-10-02 o Gemini devolveu `.content` como LISTA em 3 de 8
`/generate-one` e nas 2 tentativas de um `/analyze-domain`, e os pontos de
leitura quebraram no `.replace`/`.strip` seguinte (ver CLAUDE.md, §10,
"Aberto em 02/10").

Rodar a partir de backend-python/:
    .\\.venv\\Scripts\\python.exe -m unittest tests.test_texto_da_resposta -v

Só biblioteca padrão (unittest + mock): nenhuma dependência nova. Nenhum teste
chama provedor, ChromaDB ou banco do WordPress.
"""
import unittest
from types import SimpleNamespace
from unittest import mock

from app import generator, regenerator
from app.generator import texto_da_resposta
from app.models import RegenerateRequest

LOGGER = "app.generator"


class TestTextoDaResposta(unittest.TestCase):

    def test_string_passa_intacta_e_sem_log(self):
        # assertNoLogs só existe a partir do 3.10; o venv roda 3.12.
        with self.assertNoLogs(LOGGER, level="WARNING"):
            self.assertEqual(texto_da_resposta('{"a": 1}', "teste"), '{"a": 1}')

    def test_lista_so_com_texto_junta_sem_separador(self):
        content = ['{"a": ', {"type": "text", "text": "1}"}]
        with self.assertLogs(LOGGER, level="WARNING") as cm:
            self.assertEqual(texto_da_resposta(content, "teste"), '{"a": 1}')
        self.assertEqual(len(cm.output), 1)
        self.assertIn("tipos=['str', 'text']", cm.output[0])

    def test_thinking_mais_texto_descarta_o_raciocinio(self):
        content = [
            {"type": "thinking", "thinking": "SEGREDO-DO-RACIOCINIO"},
            {"type": "text", "text": '{"a": 1}'},
        ]
        with self.assertLogs(LOGGER, level="WARNING") as cm:
            resultado = texto_da_resposta(content, "teste")
        self.assertEqual(resultado, '{"a": 1}')
        self.assertEqual(len(cm.output), 1)
        self.assertIn("tipos=['thinking', 'text']", cm.output[0])
        # O log registra TIPOS, nunca conteúdo — nem o do raciocínio, nem o do texto.
        self.assertNotIn("SEGREDO-DO-RACIOCINIO", cm.output[0])
        self.assertNotIn('{"a": 1}', cm.output[0])

    def test_lista_vazia_devolve_string_vazia(self):
        with self.assertLogs(LOGGER, level="WARNING") as cm:
            resultado = texto_da_resposta([], "teste")
        self.assertEqual(resultado, "")
        self.assertIsInstance(resultado, str)
        self.assertEqual(len(cm.output), 1)

    def test_nunca_devolve_lista(self):
        for content in ([], ["x"], [{"type": "reasoning"}], None, 42):
            with self.subTest(content=content), self.assertLogs(LOGGER, level="WARNING"):
                self.assertIsInstance(texto_da_resposta(content, "teste"), str)


class TestRegeneratorComRespostaEmLista(unittest.TestCase):
    """Passa pela linha alterada de regenerator.generate_one() com um LLM falso.

    ⚠️ Os patches são no módulo `regenerator`, não no `generator`: o import lá é
    por valor (ver a docstring de regenerator.generate_one()). ChromaDB e LLM
    são dublês — nada é lido do acervo nem gravado em lugar nenhum.
    """

    RESPOSTA_EM_LISTA = [
        {"type": "thinking", "thinking": "SEGREDO-DO-RACIOCINIO"},
        '{"titulo": "teste unitario", "situacao": "Cenário de teste:", ',
        '"alternativas": [{"letra": "A", "texto": "Faz A"}, {"letra": "B", "texto": "Faz B"}]}',
    ]

    def test_lista_vira_dilema_valido_na_primeira_tentativa(self):
        llm = mock.Mock()
        llm.invoke.return_value = SimpleNamespace(content=list(self.RESPOSTA_EM_LISTA))

        req = RegenerateRequest(
            site_id="http://teste.local", instance_id=0, theme_prompt="tema de teste",
        )

        with mock.patch.object(regenerator, "get_collection", return_value=object()), \
             mock.patch.object(regenerator, "mmr_search",
                               return_value="SOURCE_URL: https://exemplo.org/a\ntexto"), \
             mock.patch.object(regenerator, "_build_prompt", return_value=("sys", "user")), \
             mock.patch.object(regenerator, "_get_llm", return_value=llm), \
             self.assertLogs(LOGGER, level="WARNING") as cm:
            dilema = regenerator.generate_one(req, api_key="chave-falsa", provedor="gemini")

        self.assertIsNotNone(dilema)
        self.assertEqual(dilema.titulo, "teste unitario")
        self.assertEqual([a.texto for a in dilema.alternativas], ["Faz A", "Faz B"])
        # Uma chamada só: a lista não pode mais virar exceção e gastar retentativa.
        self.assertEqual(llm.invoke.call_count, 1)
        avisos = [l for l in cm.output if "[texto_da_resposta]" in l]
        self.assertEqual(len(avisos), 1)
        self.assertIn("regenerate", avisos[0])
        self.assertIn("tipos=['thinking', 'str', 'str']", avisos[0])
        self.assertNotIn("SEGREDO-DO-RACIOCINIO", avisos[0])


if __name__ == "__main__":
    unittest.main()
