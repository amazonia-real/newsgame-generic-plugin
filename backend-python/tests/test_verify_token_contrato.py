"""
Contrato de texto entre o 401 do `_verify_token()` e o plugin WordPress.

Motivo: o backend tem dois 401 — token do backend errado e chave de provedor
ausente —, e o plugin só tem o `detail` para separá-los. O
`Newsgame_Admin_Instances::erro_do_backend()` compara o detail com a constante
`DETAIL_TOKEN_INVALIDO` por igualdade, e é isso que decide se a tela mostra o
roteiro uvicorn/token/URL. Se uma ponta mudar o texto e a outra não, o 401 de
token perde o roteiro em silêncio.

Este teste lê as DUAS pontas: o detail que o `_verify_token()` levanta de fato,
e o valor da constante no arquivo PHP. Os tokens são SINTÉTICOS.

Rodar a partir de backend-python/:
    .\\.venv\\Scripts\\python.exe -m unittest tests.test_verify_token_contrato -v
"""
import re
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

from fastapi import HTTPException

from app import main

ARQUIVO_PHP = (
    Path(__file__).resolve().parents[2]
    / "news-game-plugin" / "includes" / "class-newsgame-admin-instances.php"
)
CFG = SimpleNamespace(api_secret_token="token-sintetico-do-teste")


def _constante_php() -> str:
    texto = ARQUIVO_PHP.read_text(encoding="utf-8")
    m = re.search(r"const\s+DETAIL_TOKEN_INVALIDO\s*=\s*'([^']*)'\s*;", texto)
    if not m:
        raise AssertionError("DETAIL_TOKEN_INVALIDO não encontrada em %s" % ARQUIVO_PHP)
    return m.group(1)


class ContratoVerifyToken(unittest.TestCase):
    def test_detail_do_401_bate_com_a_constante_do_php(self):
        with mock.patch.object(main, "get_settings", return_value=CFG):
            with self.assertRaises(HTTPException) as ctx:
                main._verify_token(x_api_key="outro-valor")
        self.assertEqual(ctx.exception.status_code, 401)
        self.assertEqual(ctx.exception.detail, _constante_php())

    def test_token_certo_nao_levanta(self):
        with mock.patch.object(main, "get_settings", return_value=CFG):
            self.assertIsNone(main._verify_token(x_api_key=CFG.api_secret_token))


if __name__ == "__main__":
    unittest.main()
