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
