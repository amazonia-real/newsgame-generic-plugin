"""Configuração central de logging do backend.

Compatível com Cloud Run: loga em stdout, sem arquivo.
Nível controlado pela env var LOG_LEVEL (default INFO).
"""
from __future__ import annotations
import logging
import os
import sys


def setup_logging() -> None:
    """Configura o logger raiz. Chamar uma única vez, na inicialização do app."""
    level_name = os.getenv("LOG_LEVEL", "INFO").upper()
    level = getattr(logging, level_name, logging.INFO)
    logging.basicConfig(
        level=level,
        stream=sys.stdout,
        format="%(asctime)s %(levelname)s %(name)s — %(message)s",
    )
    # ChromaDB 0.5.11 com um `posthog` mais novo: o capture() do Chroma chama o posthog
    # com a assinatura antiga, dá TypeError e o Chroma registra um ERROR a cada cliente
    # criado (3 linhas por chamada). Medido: o ruído sai MESMO com
    # anonymized_telemetry=False (env ou Settings), porque o erro vem antes de o
    # posthog olhar `disabled`. Nada é enviado; só se silencia esse logger.
    logging.getLogger("chromadb.telemetry.product.posthog").setLevel(logging.CRITICAL)
