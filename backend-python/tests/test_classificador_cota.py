"""
Testes da separação entre COTA (limite de uso) e CRÉDITO (conta sem saldo).

Motivo: em outubro de 2026 a cota diária do plano gratuito do Gemini chegou ao editor
como "falta de crédito", porque a mensagem do Google contém "billing" e o
marcador "billing" estava em MARCADORES_CREDITO (o falso positivo de setembro).

As exceções são as classes REAIS dos SDKs instalados (google.api_core e openai),
para exercitar os mesmos atributos (`.code`, `.status_code`) que o classificador
lê em produção. Cada texto diz de onde veio: REAL (copiado do log) ou SINTÉTICO.

Rodar a partir de backend-python/:
    .\\.venv\\Scripts\\python.exe -m unittest tests.test_classificador_cota -v
"""
import unittest

import httpx
import openai
from google.api_core.exceptions import ResourceExhausted

from app import generator

CREDITO = "falta de crédito na conta"

# REAL — `uvicorn.log` de outubro de 2026, 22:13:57, linha
# "[generator] generate_one slot=0 ABORTADO por recusa do provedor: ResourceExhausted(...)".
# Chave do painel no plano gratuito; o `repr` do log é a mensagem abaixo.
GEMINI_COTA_DIARIA_REAL = (
    "You exceeded your current quota, please check your plan and billing details. "
    "For more information on this error, head to: https://ai.google.dev/gemini-api/docs/rate-limits. "
    "To monitor your current usage, head to: https://ai.dev/rate-limit. \n"
    "* Quota exceeded for metric: generativelanguage.googleapis.com/generate_content_free_tier_requests, "
    "limit: 20, model: gemini-3.6-flash\n"
    "Please retry in 22h46m2.334074296s."
)

# FRAGMENTO — o único registro do texto de crédito esgotado do Gemini no projeto é
# o comentário do commit 06449b0 ("prepayment credits are depleted"). Não há
# mensagem completa real.
GEMINI_CREDITO_FRAGMENTO = "Your prepayment credits are depleted."

# SINTÉTICO — cota por minuto, no formato da mensagem real acima.
GEMINI_COTA_POR_MINUTO_SINTETICA = (
    "Quota exceeded for metric: generativelanguage.googleapis.com/generate_content_free_tier_requests, "
    "limit: 10, model: gemini-3.6-flash\nPlease retry in 37.2s."
)

# SINTÉTICO — não há texto real de `insufficient_quota` no projeto. O formato
# "Error code: NNN - {'error': {...}}" é o REAL da OpenAI: o 401 de outubro, 17:36,
# no uvicorn.log, veio exatamente assim.
OPENAI_SEM_CREDITO_SINTETICA = (
    "Error code: 429 - {'error': {'message': 'You exceeded your current quota, please check "
    "your plan and billing details.', 'type': 'insufficient_quota', 'param': None, "
    "'code': 'insufficient_quota'}}"
)

# SINTÉTICO — 429 de rate limit comum da OpenAI, que renova.
OPENAI_RATE_LIMIT_SINTETICA = (
    "Error code: 429 - {'error': {'message': 'Rate limit reached for requests. Please try "
    "again in 20s.', 'type': 'requests', 'param': None, 'code': 'rate_limit_exceeded'}}"
)


def _openai_429(mensagem):
    req = httpx.Request("POST", "https://api.openai.com/v1/chat/completions")
    return openai.RateLimitError(mensagem, response=httpx.Response(429, request=req), body=None)


class TestClassificadorCota(unittest.TestCase):

    def test_cota_diaria_real_do_gemini_e_fatal_e_diz_cota(self):
        exc = ResourceExhausted(GEMINI_COTA_DIARIA_REAL)
        self.assertEqual(generator._status_por_code(exc), 429)
        self.assertTrue(generator.erro_fatal_de_provedor(exc))
        self.assertAlmostEqual(generator.espera_sugerida_s(exc), 81962.33, delta=1)
        detail = generator.detail_de_provedor(exc, "gemini")
        self.assertNotIn(CREDITO, detail)
        self.assertIn("limite de uso", detail)
        self.assertIn("plano gratuito", detail)
        self.assertIn("22h46m", detail)
        self.assertIn("Aguarde a renovação ou use uma chave de um projeto com faturamento ativo.", detail)

    def test_credito_esgotado_do_gemini_e_fatal_e_diz_credito(self):
        exc = ResourceExhausted(GEMINI_CREDITO_FRAGMENTO)
        self.assertTrue(generator.erro_fatal_de_provedor(exc))
        self.assertIn(CREDITO, generator.detail_de_provedor(exc, "gemini"))

    def test_cota_por_minuto_do_gemini_nao_e_fatal(self):
        exc = ResourceExhausted(GEMINI_COTA_POR_MINUTO_SINTETICA)
        self.assertTrue(generator.erro_de_cota(exc))
        self.assertFalse(generator.erro_fatal_de_provedor(exc))

    def test_rate_limit_da_openai_nao_e_fatal(self):
        # Status no objeto tem precedência: 429 comum segue no caminho de retry.
        exc = _openai_429(OPENAI_RATE_LIMIT_SINTETICA)
        self.assertEqual(generator._status_http(exc), 429)
        self.assertFalse(generator.erro_fatal_de_provedor(exc))

    def test_insufficient_quota_da_openai_e_credito_e_nao_cota(self):
        exc = _openai_429(OPENAI_SEM_CREDITO_SINTETICA)
        self.assertEqual(generator._status_http(exc), 429)
        self.assertTrue(generator.erro_fatal_de_provedor(exc))
        detail = generator.detail_de_provedor(exc, "openai")
        self.assertIn(CREDITO, detail)
        self.assertNotIn("limite de uso", detail)


if __name__ == "__main__":
    unittest.main()
