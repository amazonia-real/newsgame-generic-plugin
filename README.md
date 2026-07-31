# News Game Plugin

Plugin WordPress open source para criar jogos de dilemas interativos
a partir do acervo editorial de qualquer veículo jornalístico.

Desenvolvido pela equipe da [Amazônia Real](https://amazoniareal.com.br),
generalizado como ferramenta reutilizável para qualquer domínio editorial.

---

## Como funciona

O editor sobe um corpus de textos. A IA infere os eixos de tensão de valor
daquele domínio específico. O sistema gera dilemas calibrados por esses eixos.
O jogador escolhe — e ao final recebe um perfil narrativo baseado no vetor
acumulado das suas decisões.

Nenhum eixo, peso ou perfil é hardcoded: tudo emerge do corpus fornecido.

---

## Arquitetura

```
WordPress (plugin PHP)
    ↕ HTTP (JSON)
FastAPI (Python)
    ↕ embeddings + MMR search
ChromaDB (banco vetorial local)
    ↑ indexação
Corpus de textos do editor
```

### Rotas do backend

| Rota | Método | Função |
|------|--------|--------|
| `/health` | GET | Healthcheck |
| `/ingest` | POST | Indexa artigos no ChromaDB |
| `/analyze-domain` | POST | Infere 3 eixos de tensão do corpus + perfis de jogador |
| `/assign-weights` | POST | Atribui pesos (-20 a +20) por eixo a cada alternativa |
| `/generate-verdict` | POST | Seleciona perfil mais próximo e gera texto narrativo |
| `/generate-one` | POST | Gera 1 dilema de forma síncrona (chamada por slot) |
| `/generate` | POST | Gera dilemas via SSE stream |
| `/regenerate` | POST | Substitui 1 dilema inválido |

Resolução da chave Gemini em todas as rotas:
`X-Gemini-Api-Key` (header) → `gemini_api_key` (body) → `GEMINI_API_KEY` (env)

---

## Requisitos

- PHP 8.1+ / WordPress 6.x
- Python 3.11+
- [ChromaDB](https://www.trychroma.com/) (local ou containerizado)
- Chave de API [Google Gemini](https://aistudio.google.com/)

---

## Instalação

### Backend

```bash
cd backend-python
python -m venv .venv
.venv\Scripts\activate        # Windows
pip install -r requirements.txt
cp .env.example .env          # editar GEMINI_API_KEY, CHROMA_DB_DIR e demais vars
uvicorn app.main:app --reload --port 8000
```

### Plugin WordPress

Copie (ou crie link simbólico de) `news-game-plugin/` para
`wp-content/plugins/news-game-plugin/` e ative pelo painel do WordPress.

Configure a URL do backend em **News Game → Configurações**.

---

## Fluxo do editor

1. Cria uma instância em **News Game → Instâncias**
2. Cola o corpus de textos (reportagens, artigos) na aba de ingestão
3. Clica em **Analisar domínio** — a IA propõe 3 eixos de tensão e 4–6 perfis de jogador
4. Edita/aprova os eixos e perfis no textarea
5. Clica em **Gerar dilemas** — o backend produz dilemas calibrados pelo corpus
6. Revisa dilema a dilema; substitui os insatisfatórios com **Regenerar**
7. Publica — o shortcode `[news-game id="1"]` renderiza o jogo em qualquer página (ID numérico da instância)

## Fluxo do jogador

1. Responde dilemas apresentados sequencialmente
2. Cada escolha acumula pesos nos 3 eixos da instância
3. Ao final, `/generate-verdict` calcula o vetor do jogador e seleciona o perfil
   mais próximo por distância euclidiana
4. Exibe: nome do perfil + força dominante + ponto cego + chamada de compartilhamento

---

## Estrutura do repositório

```
news-game/
├── backend-python/
│   └── app/
│       ├── main.py        ← rotas FastAPI
│       ├── models.py      ← schemas Pydantic
│       ├── generator.py   ← geração de dilemas via Gemini + RAG
│       ├── rag.py         ← motor RAG / ChromaDB (MMR search)
│       ├── ingestor.py    ← ingestão e indexação do corpus
│       ├── regenerator.py ← auto-reparo de dilemas inválidos
│       └── config.py      ← configurações centralizadas
├── news-game-plugin/
│   ├── news-game.php
│   ├── admin/views/       ← telas do painel WP
│   ├── includes/          ← classes PHP (db, generator, ingestor, etc.)
│   └── public/            ← CSS + JS do jogo
├── FEW_SHOT_EXAMPLES.md   ← exemplos canônicos de dilemas — não editar
├── AGENTS.md              ← diretrizes de geração de conteúdo
├── CLAUDE.md              ← instruções operacionais para Claude Code
├── ROADMAP_OPEN_SOURCE.md ← roadmap de universalização
└── TECH_FLAGS.md          ← decisões de arquitetura e dívida técnica
```

---

## Variáveis de ambiente (backend)

| Variável | Obrigatória | Padrão | Descrição |
|----------|-------------|--------|-----------|
| `GEMINI_API_KEY` | Sim* | — | Chave da API Google Gemini |
| `CHROMA_DB_DIR` | Sim | `/data/chroma_db` | Caminho do banco vetorial (ajustar no Windows) |
| `API_SECRET_TOKEN` | Sim | — | Token para autenticar chamadas do plugin WP. Obrigatório e sem valor padrão: a aplicação se recusa a subir sem ele |
| `LLM_MODEL` | Não | `gemini-2.5-flash` | Modelo Gemini usado na geração |
| `LLM_TEMPERATURE` | Não | `0.3` | Temperatura do modelo |
| `EMBED_MODEL` | Não | `paraphrase-multilingual-MiniLM-L12-v2` | Modelo de embeddings |
| `RAG_FETCH_K` | Não | `20` | Documentos buscados antes do MMR |
| `RAG_K` | Não | `5` | Documentos retornados após MMR |
| `RAG_CONTEXT_WINDOW` | Não | `40000` | Tamanho máximo do contexto RAG em chars |
| `MAX_RETRIES_BATCH` | Não | `5` | Tentativas por dilema antes de desistir |
| `RATE_LIMIT_SLEEP` | Não | `60` | Segundos de espera ao atingir rate limit |

*Pode ser fornecida por header ou body em vez de variável de ambiente.

---

## Contribuição

Veja `ROADMAP_OPEN_SOURCE.md` para o estado atual e próximos passos.
Dívida técnica documentada em `TECH_FLAGS.md`.
Decisões de arquitetura e regras de desenvolvimento em `CLAUDE.md`.

---

*Originado no [Jogo da Amazônia](https://github.com/amazonia-real) — jornalismo interativo da maior floresta tropical do mundo.*
