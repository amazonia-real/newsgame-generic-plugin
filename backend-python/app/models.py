"""Modelos Pydantic para I/O da API e para structured output do Gemini."""
from __future__ import annotations
from pydantic import BaseModel, Field
from typing import List


# ── Modelos de entrada (request bodies) ───────────────────

class VaultArticle(BaseModel):
    id: int
    type: str = "posts"
    title: str
    excerpt: str = ""
    content: str
    url: str
    date: str = ""


class IngestRequest(BaseModel):
    site_id: str
    articles: List[VaultArticle]


class CharacterDef(BaseModel):
    slug: str
    label: str
    description: str


class GenerateRequest(BaseModel):
    site_id: str
    instance_id: int
    theme_prompt: str
    density: int = Field(ge=20, le=120)
    characters: List[CharacterDef] = Field(min_length=1)


# ── Modelos Pydantic para structured output do Gemini ─────

class Alternativa(BaseModel):
    id: str = Field(description="Identificador fixo: 'A' ou 'B'.")
    texto: str = Field(
        description="Ação que o jogador pode escolher. Máximo 2 frases, sentence case, linguagem direta."
    )
    consequencia: str = Field(
        description="Consequência direta e realista desta escolha. Máximo 2 frases."
    )


class InfoContextual(BaseModel):
    sintese: str = Field(
        description=(
            "Síntese jornalística em 2-3 frases do contexto real, embasada nas reportagens fornecidas. "
            "Inclua dados, números ou atores reais quando disponíveis. Sem opinião."
        )
    )
    link_original: str = Field(
        description=(
            "URL da reportagem principal que embasou este dilema. "
            "Extraída OBRIGATORIAMENTE do campo SOURCE_URL do contexto fornecido. "
            "Deve iniciar com 'http'. NUNCA invente ou suponha URLs. "
            "Se não houver URL válida, este dilema deve ser DESCARTADO e substituído."
        )
    )


class DilemaNg(BaseModel):
    id_unico: str = Field(
        description="Slug único no formato: '{nivel}-{personagem_slug}-{numero:02d}'. Ex: '1-diretor-01'."
    )
    nivel: int = Field(ge=1, le=2, description="Nível de complexidade: 1=Básico, 2=Avançado.")
    personagem_tipo: str = Field(
        description="Slug do personagem (ex: 'diretor-teatral') ou 'geral' para dilemas coletivos."
    )
    titulo: str = Field(description="Título curto do dilema em até 8 palavras. Pode usar caixa alta para impacto.")
    situacao: str = Field(description="Cenário do dilema em 2-4 frases. Sentence case.")
    alternativas: List[Alternativa] = Field(
        min_length=2, max_length=2,
        description="Exatamente 2 alternativas (A e B). Sem binário certo/errado — cada escolha tem custo e benefício reais."
    )
    desfecho_real: str = Field(
        description="O que de fato ocorreu, embasado na reportagem. 2-3 frases. Factual, sem opinião."
    )
    licao: str = Field(
        description="Lição educativa extraída do desfecho para o jogador refletir. 1-2 frases."
    )
    info_contextual: InfoContextual


class LoteDilemas(BaseModel):
    dilemas: List[DilemaNg] = Field(description="Lista de dilemas gerados neste lote.")
