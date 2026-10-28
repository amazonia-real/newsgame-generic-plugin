"""
Teto do `density` em `GenerateRequest` (etapa 1, outubro).

O `density` passou a ser a meta do CONJUNTO de aprovadas da instância, com teto
120. O PHP o manda em todo `/generate-one` como o déficit do lote, e o backend
só o valida — não o lê. Com o teto antigo (15), um déficit acima de 15 dava 422
de validação em todos os slots.

Sem chamada a provedor: só a validação do Pydantic.

Rodar a partir de backend-python/:
    .\\.venv\\Scripts\\python.exe -m unittest tests.test_generate_request_density -v
"""
import unittest

from pydantic import ValidationError

from app.models import GenerateRequest

BASE = {"site_id": "http://localhost/jda", "instance_id": 1, "theme_prompt": "teste"}


class TestDensityTeto(unittest.TestCase):
    def test_valores_validos(self):
        for d in (1, 15, 120):
            with self.subTest(density=d):
                self.assertEqual(GenerateRequest(**BASE, density=d).density, d)

    def test_acima_do_teto_falha(self):
        with self.assertRaises(ValidationError) as ctx:
            GenerateRequest(**BASE, density=121)
        erro = ctx.exception.errors()[0]
        self.assertEqual(erro["loc"], ("density",))
        self.assertEqual(erro["type"], "less_than_equal")

    def test_zero_falha(self):
        with self.assertRaises(ValidationError):
            GenerateRequest(**BASE, density=0)


if __name__ == "__main__":
    unittest.main()
