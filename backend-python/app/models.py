"""Modelos Pydantic para I/O da API e para structured output do Gemini."""
from __future__ import annotations
from pydantic import BaseModel, Field, ConfigDict
from typing import List, Literal


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
    density: int = Field(ge=1, le=15)
    characters: List[CharacterDef] = Field(default_factory=list)
    num_options: Literal[2, 3] = 2
    enable_levels: bool = False
    slot_index: int = Field(ge=0, default=0)    # posição no lote (0-based); rotação de personagens
    nivel_padrao: str = Field(default='pedagogico')  # 'ludico' | 'pedagogico' | 'complexo'
    fontes_usadas: List[str] = Field(default_factory=list)



# ── Modelos Pydantic para structured output do Gemini ─────
#
# IMPORTANTE: todos os campos têm defaults para que a validação Pydantic
# não falhe quando o Gemini omite campos opcionais. A validação de
# qualidade (título vazio, alternativas insuficientes, etc.) é feita
# em generator.py após a deserialização, não aqui.

class Impacto(BaseModel):
    model_config = ConfigDict(extra="ignore")

    popularidade: int = Field(
        default=0,
        description="Impacto em visibilidade/audiência. Escala de -10 a +10.",
    )
    recursos: int = Field(
        default=0,
        description="Impacto em capital financeiro/político. Escala de -10 a +10.",
    )


class Alternativa(BaseModel):
    model_config = ConfigDict(extra="ignore")

    letra: str = Field(
        default="A",
        description="Identificador da alternativa: 'A', 'B' ou 'C'.",
    )
    texto: str = Field(
        default="",
        description="Ação em verbo presente do indicativo. Máx. 90 caracteres. Sentence case.",
    )
    consequencia: str = Field(
        default="",
        description="Consequência direta desta escolha. Máx. 90 caracteres. Sentence case.",
    )
    impacto: Impacto = Field(
        default_factory=Impacto,
        description="Pesos de impacto narrativo. Trade-offs reais entre popularidade e recursos.",
    )


class InfoContextual(BaseModel):
    model_config = ConfigDict(extra="ignore")

    sintese: str = Field(
        default="",
        description=(
            "Síntese jornalística em 2-3 frases do contexto real, embasada nas reportagens fornecidas. "
            "Inclua dados, números ou atores reais quando disponíveis. Sem opinião."
        ),
    )
    link_original: str = Field(
        default="",
        description=(
            "URL da reportagem principal que embasou este dilema. "
            "Extraída OBRIGATORIAMENTE do campo SOURCE_URL do contexto fornecido. "
            "Deve iniciar com 'http'. NUNCA invente ou suponha URLs."
        ),
    )


class DilemaNg(BaseModel):
    model_config = ConfigDict(extra="ignore")

    id_unico: str = Field(
        default="",
        description="Slug único no formato: '{nivel}-{personagem_slug}-{numero:02d}'. Ex: '1-diretor-01'.",
    )
    nivel: int = Field(
        default=1,
        description="Nível de complexidade: 1=Básico, 2=Avançado.",
    )
    personagem_tipo: str = Field(
        default="geral",
        description="Slug do personagem (ex: 'diretor-teatral') ou 'geral' para dilemas coletivos.",
    )
    titulo: str = Field(
        default="",
        description="Rótulo interno em até 3 palavras, letra minúscula. Apenas indexação — não aparece na tela.",
    )
    situacao: str = Field(
        default="",
        description="Cenário em 1-2 frases. Máx. 130 caracteres. Sentence case. Deve terminar com dois pontos (:).",
    )
    alternativas: List[Alternativa] = Field(
        default_factory=list,
        description=(
            "Lista de alternativas conforme num_options (2 ou 3). "
            "Cada uma com custo e benefício reais — sem binário certo/errado."
        ),
    )
    desfecho_real: str = Field(
        default="",
        description="O que de fato ocorreu, embasado na reportagem. 2-3 frases. Factual, sem opinião.",
    )
    licao: str = Field(
        default="",
        description="Lição educativa extraída do desfecho para o jogador refletir. 1-2 frases.",
    )
    info_contextual: InfoContextual = Field(
        default_factory=InfoContextual,
    )
    alternativa_correta: str = Field(
        default="",
        description="Letra da alternativa que corresponde ao fato real (nível LÚDICO): 'A', 'B' ou 'C'.",
    )
    nivel_nome: str = Field(
        default="LÚDICO",
        description="Nome do nível: 'LÚDICO', 'PEDAGÓGICO' ou 'COMPLEXO'.",
    )


class LoteDilemas(BaseModel):
    model_config = ConfigDict(extra="ignore")

    dilemas: List[DilemaNg] = Field(
        default_factory=list,
        description="Lista de dilemas gerados neste lote.",
    )


class RegenerateRequest(BaseModel):
    site_id: str
    instance_id: int
    theme_prompt: str
    personagem_tipo: str = "geral"
    nivel: int = Field(ge=1, le=2, default=1)
    characters: List[CharacterDef] = Field(default_factory=list)
    num_options: Literal[2, 3] = 2
    enable_levels: bool = False
    fontes_usadas: List[str] = Field(default_factory=list)


# ── Modelos para /analyze-domain ──────────────────────────────────────────

class AxisOption(BaseModel):
    """Tensão de valor com nome curto, descrição neutra e seus dois polos."""
    nome: str
    descricao: str
    polo_positivo: str
    polo_negativo: str


class AxisSet(BaseModel):
    """Conjunto dos três eixos de tensão principais do domínio."""
    eixo1: AxisOption
    eixo2: AxisOption
    eixo3: AxisOption


class ProfileSuggestion(BaseModel):
    """Perfil de jogador derivado dos eixos de tensão do domínio."""
    nome: str
    descricao: str
    forca_dominante: str
    ponto_cego: str
    vetor_aproximado: dict[str, float]


class DomainAnalysisRequest(BaseModel):
    context_text: str
    perfis_disponiveis: List[str] = Field(default_factory=list)
    gemini_api_key: str = Field(default="")


class DomainAnalysisResponse(BaseModel):
    eixos_principais: AxisSet
    eixos_reserva: dict[str, List[AxisOption]]
    perfis_sugeridos: List[ProfileSuggestion]


# ── Modelos para /assign-weights ──────────────────────────────────────────

class AssignWeightsRequest(BaseModel):
    dilemmas: List[dict]
    eixos: AxisSet
    gemini_api_key: str = Field(default="")


class AssignWeightsResponse(BaseModel):
    dilemmas_weighted: List[dict]


# ── Modelos para /generate-verdict ────────────────────────────────────────

class VerdictNarrative(BaseModel):
    nome_perfil: str
    texto_forca: str
    texto_ponto_cego: str
    chamada_compartilhamento: str


class GenerateVerdictRequest(BaseModel):
    vetor_jogador: dict[str, float]
    perfis_disponiveis: List[ProfileSuggestion]
    eixos: AxisSet
    gemini_api_key: str = Field(default="")


class GenerateVerdictResponse(BaseModel):
    perfil_selecionado: ProfileSuggestion
    narrativa: VerdictNarrative
    vetor_jogador: dict[str, float]
