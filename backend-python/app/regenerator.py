"""
Lógica do endpoint /regenerate.

Gera exatamente 1 pergunta substituta de forma síncrona (sem SSE).
Reutiliza _build_prompt() e mmr_search() do generator.py.
"""
from __future__ import annotations
import sys

from app.config import get_settings
from app.generator import _get_llm, _build_prompt, _is_valid_dilema
from app.models import RegenerateRequest, LoteDilemas, DilemaNg
from app.rag import get_collection, mmr_search


def generate_one(req: RegenerateRequest) -> DilemaNg | None:
    """
    Gera 1 dilema substituto baseado no mesmo personagem e nível do original descartado.
    Retorna DilemaNg ou None se falhar após todas as tentativas.
    """
    cfg            = get_settings()
    db             = get_collection(req.site_id)
    structured_llm = _get_llm().with_structured_output(LoteDilemas)

    # Localiza o personagem nas definições da instância (pode não existir se for "geral")
    char = next((c for c in req.characters if c.slug == req.personagem_tipo), None)

    if char:
        queries          = [
            f"{char.description} {req.theme_prompt}",
            f"{char.label} {req.theme_prompt}",
            char.description,
            req.theme_prompt,
        ]
        personagem_label = char.label
        personagem_desc  = char.description
        modo_geral       = False
    else:
        queries          = [req.theme_prompt, f"perspectiva ampla sobre {req.theme_prompt}"]
        personagem_label = "Geral"
        personagem_desc  = None
        # Modo Geral verdadeiro apenas quando não há personagens cadastrados na instância
        modo_geral       = not bool(req.characters)

    contexto_rag = mmr_search(db, queries, fetch_k=cfg.rag_fetch_k, k=cfg.rag_k)
    if not contexto_rag.strip():
        return None

    prompt = _build_prompt(
        theme_prompt=req.theme_prompt,
        contexto_rag=contexto_rag,
        nivel=req.nivel,
        personagem_tipo=req.personagem_tipo,
        personagem_label=personagem_label,
        personagem_descricao=personagem_desc,
        quantidade=1,
        num_options=req.num_options,
        enable_levels=req.enable_levels,
        modo_geral=modo_geral,
    )

    for tentativa in range(1, cfg.max_retries_batch + 1):
        try:
            resultado: LoteDilemas = structured_llm.invoke(prompt)
            validos = [d for d in resultado.dilemas if _is_valid_dilema(d, req.num_options)]
            if validos:
                return validos[0]
        except Exception as exc:
            print(
                f"[regenerator] tentativa={tentativa} personagem={req.personagem_tipo} erro={exc!r}",
                file=sys.stderr,
            )
            if tentativa >= cfg.max_retries_batch:
                break

    return None
