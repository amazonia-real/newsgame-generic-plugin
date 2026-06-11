"""
Lógica do endpoint /regenerate.

Gera exatamente 1 pergunta substituta de forma síncrona (sem SSE).
Reutiliza _build_prompt(), _nivel_nome() e mmr_search() do generator.py.
"""
from __future__ import annotations
import logging
import re

from langchain_core.messages import SystemMessage, HumanMessage

from app.config import get_settings
from app.generator import _get_llm, _build_prompt, _is_valid_dilema, _nivel_nome, _extract_json_dilema
from app.models import RegenerateRequest, DilemaNg
from app.rag import get_collection, mmr_search

logger = logging.getLogger(__name__)


def generate_one(req: RegenerateRequest) -> DilemaNg | None:
    """
    Gera 1 dilema substituto baseado no mesmo personagem e nível do original descartado.
    Retorna DilemaNg ou None se falhar após todas as tentativas.
    """
    cfg            = get_settings()
    db             = get_collection(req.site_id)
    llm            = _get_llm()

    modo_geral = not bool(req.characters)
    n_nome     = _nivel_nome(req.nivel, modo_geral, req.personagem_tipo)

    char = next((c for c in req.characters if c.slug == req.personagem_tipo), None)

    if char:
        queries    = [
            f"{char.description} {req.theme_prompt}",
            f"{char.label} {req.theme_prompt}",
            char.description,
            req.theme_prompt,
        ]
        char_label = char.label
        char_desc  = char.description
    else:
        queries    = [req.theme_prompt, f"perspectiva ampla sobre {req.theme_prompt}"]
        char_label = None
        char_desc  = None

    contexto_rag = mmr_search(
        db, queries, 
        fetch_k=cfg.rag_fetch_k, 
        k=cfg.rag_k,
        fontes_usadas=req.fontes_usadas
    )
    if not contexto_rag.strip():
        return None

    urls_disponiveis = list(dict.fromkeys(
        re.findall(r'SOURCE_URL:\s*(https?://\S+)', contexto_rag)
    ))
    url_fonte = urls_disponiveis[0] if urls_disponiveis else ""

    system, user = _build_prompt(
        theme_prompt=req.theme_prompt,
        contexto_rag=contexto_rag,
        nivel_nome=n_nome,
        url_fonte=url_fonte,
        num_options=req.num_options,
        personagem_label=char_label,
        personagem_descricao=char_desc,
    )

    for tentativa in range(1, cfg.max_retries_batch + 1):
        try:
            res = llm.invoke(
                [SystemMessage(system), HumanMessage(user)]
            )
            data_dict = _extract_json_dilema(res.content)
            dilema: DilemaNg = DilemaNg(**data_dict)
            
            if req.num_options == 2 and len(dilema.alternativas) > 2:
                dilema.alternativas = dilema.alternativas[:2]
            if _is_valid_dilema(dilema, req.num_options):
                dilema.nivel          = req.nivel
                dilema.nivel_nome     = n_nome
                dilema.personagem_tipo = req.personagem_tipo
                return dilema
        except Exception as exc:
            logger.warning(
                "[regenerator] tentativa=%s personagem=%s erro=%r",
                tentativa, req.personagem_tipo, exc,
            )
            if tentativa >= cfg.max_retries_batch:
                break

    return None
