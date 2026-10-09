"""
Tom da substituta = tom da geração (09/10).

Até 09/10 o /regenerate calculava o tom por regra própria (`_nivel_nome`, pelo
level e pela presença de personagem) e saía em outro tom em 2 dos 6 casos:
lúdico sem personagens (virava PEDAGÓGICO) e pedagógico com personagens (virava
LÚDICO). Agora os dois caminhos usam `generator.tom_do_nivel(nivel_padrao)`.

Os testes rodam os dois caminhos de verdade até o `_build_prompt`, com RAG,
coleção e cliente do provedor substituídos — sem rede e sem cota. O tom que
chega ao prompt é capturado e o caminho é interrompido ali.

Os patches vão no módulo que USA o nome: o import do regenerator é por valor
(ver a nota em `regenerator.generate_one`).

Rodar a partir de backend-python/:
    .\\.venv\\Scripts\\python.exe -m unittest tests.test_tom_substituta -v
"""
import unittest
from unittest import mock

from app import generator, regenerator
from app.models import CharacterDef, GenerateRequest, RegenerateRequest

BASE = {"site_id": "http://localhost/jda", "instance_id": 1, "theme_prompt": "teste"}
PERSONAGENS = [CharacterDef(slug="atriz", label="Atriz", description="uma atriz")]
ESPERADO = {"ludico": "LÚDICO", "pedagogico": "PEDAGÓGICO", "complexo": "COMPLEXO"}


class _Parar(Exception):
    pass


def _tom_no_prompt(modulo, chamar):
    """Roda `chamar()` e devolve o nivel_nome que chegou ao _build_prompt."""
    visto = {}

    def build_prompt(**kw):
        visto["tom"] = kw["nivel_nome"]
        raise _Parar

    with mock.patch.object(modulo, "get_collection", return_value=object()), \
         mock.patch.object(modulo, "mmr_search", return_value="SOURCE_URL: http://x\ntexto"), \
         mock.patch.object(modulo, "_get_llm", return_value=object()), \
         mock.patch.object(modulo, "_build_prompt", side_effect=build_prompt):
        try:
            chamar()
        except _Parar:
            pass
    return visto.get("tom")


class TestTomSubstituta(unittest.TestCase):
    def test_seis_combinacoes(self):
        for nivel_padrao, tom in ESPERADO.items():
            nivel = generator.tom_do_nivel(nivel_padrao)[0]
            for com in (True, False):
                chars = PERSONAGENS if com else []
                tipo = "atriz" if com else "geral"
                with self.subTest(nivel_padrao=nivel_padrao, personagens=com):
                    ger = _tom_no_prompt(generator, lambda: generator.generate_one_dilema(
                        GenerateRequest(**BASE, density=10, characters=chars, nivel_padrao=nivel_padrao), "k"))
                    sub = _tom_no_prompt(regenerator, lambda: regenerator.generate_one(
                        RegenerateRequest(**BASE, characters=chars, personagem_tipo=tipo,
                                          nivel=nivel, nivel_padrao=nivel_padrao), "k"))
                    self.assertEqual(ger, tom)
                    self.assertEqual(sub, tom)

    def test_padrao_desconhecido_cai_em_pedagogico(self):
        self.assertEqual(generator.tom_do_nivel("outro"), (1, "PEDAGÓGICO"))

    def test_regenerate_sem_nivel_padrao_usa_o_default(self):
        self.assertEqual(RegenerateRequest(**BASE).nivel_padrao, "pedagogico")


if __name__ == "__main__":
    unittest.main()
