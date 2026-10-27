# News Game Plugin

Plugin WordPress open source para criar jogos de dilemas interativos
a partir do acervo editorial de qualquer veículo jornalístico.

Desenvolvido pela equipe da [Amazônia Real](https://amazoniareal.com.br),
generalizado como ferramenta reutilizável para qualquer domínio editorial.

**Versão do plugin:** 1.2.0 · **Licença:** GPL-2.0-or-later (arquivo
`news-game-plugin/LICENSE`) · **Compatibilidade:** testado nos temas Twenty Twenty-Five
e Newspaper.

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
| `/generate-verdict` | POST | Seleciona perfil mais próximo e gera texto narrativo — disponível, **não usada pelo jogo** |
| `/generate-one` | POST | Gera 1 dilema de forma síncrona (chamada por slot) |
| `/regenerate` | POST | Substitui 1 dilema inválido |

Todas as rotas, exceto `/health`, exigem o header `X-Api-Key` com o valor de
`API_SECRET_TOKEN`.

Nas rotas que chamam IA, o **provedor** é resolvido por
`X-LLM-Provider` (header) → `LLM_PROVIDER` (env) → `gemini`, e a **chave** do
provedor resolvido por header próprio → campo no body → variável de ambiente
(tabela em [Provedores de IA](#provedores-de-ia)). O provedor não viaja no body.

---

## Requisitos

- PHP 8.1+ / WordPress 6.x
- Python 3.11+
- [ChromaDB](https://www.trychroma.com/) (local ou containerizado)
- Chave de API de **um** dos quatro provedores suportados — ver abaixo
- Um servidor para o backend Python (Docker com Caddy, ou uvicorn direto) — o backend
  **não vem no zip** do plugin

Passos completos: [`README_PORTABILIDADE.md`](README_PORTABILIDADE.md) (plugin, acervo,
primeira instância, modelo de segurança) e
[`docs/INSTALACAO_BACKEND.md`](docs/INSTALACAO_BACKEND.md) (backend em servidor, com Docker).

---

## Skins

O jogo tem 7 skins de fábrica, escolhidas por instância: Padrão (`default`), Mono, Pixel,
Drama, Games, Pop Art e Minimal. Depois de trocar a skin ou atualizar o plugin, limpe
todas as camadas de cache do site (ver `README_PORTABILIDADE.md`, §2).

Não testado com leitor de tela real.

---

## Provedores de IA

O plugin funciona com quatro provedores. O editor escolhe o ativo em
**News-Game → Configurações → Provedor de IA**, e o plugin manda o provedor e a
chave dele em cada chamada. As variáveis de ambiente do backend são o padrão
usado quando o painel não manda nada.

| Provedor | `LLM_PROVIDER` | Chave (env) | Header da chave | Modelo (env) | Modelo padrão |
|---|---|---|---|---|---|
| Google Gemini | `gemini` | `GEMINI_API_KEY` | `X-Gemini-Api-Key` | `LLM_MODEL` | `gemini-3.6-flash` |
| DeepSeek | `deepseek` | `DEEPSEEK_API_KEY` | `X-DeepSeek-Api-Key` | `LLM_MODEL_DEEPSEEK` | `deepseek-v4-flash` |
| OpenAI | `openai` | `OPENAI_API_KEY` | `X-OpenAI-Api-Key` | `LLM_MODEL_OPENAI` | `gpt-5.6-luna` |
| Anthropic Claude | `claude` | `CLAUDE_API_KEY` | `X-Claude-Api-Key` | `LLM_MODEL_CLAUDE` | `claude-sonnet-5` |

Modelos padrão do código; confirme na lista de modelos do provedor, porque mudam.

- A chave do Claude é **`CLAUDE_API_KEY`**, e não `ANTHROPIC_API_KEY`: o segundo
  nome é lido pelo próprio SDK da Anthropic e criaria um caminho de autenticação
  fora do controle do backend.
- `LLM_MODEL`, sem sufixo, é o modelo do **Gemini**, por compatibilidade.
- Sem chave no painel e sem chave no `.env`, as rotas de IA respondem 401.
- Erro de provedor que retentar não resolve (chave recusada, falta de crédito,
  modelo inexistente, cota que só renova depois de 2 minutos) chega ao editor
  como recusa com a causa nomeada, e não como "pergunta inválida".

> ⚠️ **O plano gratuito do Gemini não comporta a geração de um jogo.** A cota dele
> é de **20 requisições por dia** (`generate_content_free_tier_requests, limit: 20`),
> e pode se esgotar no meio de uma geração. Um jogo de meta *N* gasta, no mínimo,
> *N* + 2 chamadas: uma para analisar o acervo, uma por pergunta e uma para
> atribuir os pesos. A isso somam-se
> a segunda tentativa da análise quando a primeira resposta não valida, as
> retentativas do lote (até *N* + 3 chamadas) e cada "Pedir Substituta". Use uma
> chave de projeto com faturamento ativo. O limite é do Google e pode mudar.

---

## Instalação

### Backend

```bash
cd backend-python
python -m venv .venv
.venv\Scripts\activate        # Windows
pip install -r requirements.txt
cp .env.example .env          # API_SECRET_TOKEN, CHROMA_DB_DIR e a chave do provedor
python -m uvicorn app.main:app --port 8000
```

- **Use `python -m uvicorn`, e não o executável `uvicorn`.** No Windows com o
  **Smart App Control** ligado, o `uvicorn.exe` que o pip instala em `Scripts\` não
  é assinado e pode ser bloqueado. Com `python -m`, quem roda é o `python.exe`, que
  é assinado.
- `--reload` é opcional. No Windows ele aceita dois servidores na mesma porta sem
  erro — ver a **FLAG 013** em `TECH_FLAGS.md` antes de usar.

### Plugin WordPress

Para instalar, baixe o arquivo `news-game-plugin.zip` na página **Releases** do repositório, e não em *Code → Download ZIP* (esse traz o repositório inteiro, e não só o plugin), e envie-o em **Plugins → Adicionar novo → Enviar plugin**.

Para desenvolver, copie (ou crie link simbólico de) `news-game-plugin/` para
`wp-content/plugins/news-game-plugin/` e ative pelo painel do WordPress.

Em **News-Game → Configurações**:

- **URL do Backend Python** — o endereço onde o uvicorn responde, visto **do
  servidor WordPress** (por exemplo `http://localhost:8000`). O navegador do
  jogador nunca fala com o backend: todas as chamadas passam pelo PHP.
- **Token do Backend** — o mesmo valor de `API_SECRET_TOKEN` do `.env`. O plugin o
  manda no header `X-Api-Key` em toda chamada; diferente, o backend responde 401.
- **Provedor de IA** e a **chave** do provedor escolhido.

> ⚠️ **As chaves ficam em texto claro no banco do WordPress** (`wp_options`), sem
> criptografia — limitação aceita (**FLAG 011**), documentada em "Modelo de segurança"
> no `README_PORTABILIDADE.md`. Elas aparecem no HTML da
> tela de configurações (o campo é do tipo senha, mas o valor está no código-fonte da
> página), e a chave do Gemini e o Token do Backend são carregados em todo request
> (`autoload`). O plugin **não** oferece mitigação técnica hoje. Na operação:
> restrinja a chave no console do provedor à API de geração e com teto de gasto;
> mantenha poucas contas com permissão de administrador; trate backups do banco
> como material sensível; e, se o banco vazar, rotacione as chaves e o token.

---

## Fluxo do editor

1. **Acervo** — em **News-Game → Configurações**, "Sincronizar Acervo" extrai os
   posts e páginas publicados do próprio site, e "Indexar Acervo no Backend" os
   envia ao ChromaDB.
2. **Instância** — em **News-Game → Instâncias**, cria a instância: nome,
   descrição do acervo, meta de perguntas aprovadas (4 a 120), perguntas por
   partida (4 a 15; cada partida sorteia essa quantidade entre as aprovadas, em
   ordem embaralhada), 2 ou 3 opções, perfil do jogo,
   skin e personagens. **Salvar** fica na tela; **Salvar e voltar à lista** volta à
   listagem.
3. **Eixos** — "Analisar Acervo e Propor Eixos" (liberado depois do primeiro
   salvamento): a IA propõe 3 eixos de tensão e os perfis de jogador. O editor
   ajusta o JSON e clica em "Confirmar e Salvar Matriz de Eixos".
4. **Perguntas** — "Gerar Perguntas Agora" (ou "Gerar as que faltam (N)", quando já
   há perguntas) gera só o que falta para aprovadas + pendentes chegarem à meta. As
   novas entram como **pendentes**.
5. **Mesa Editorial** — abas Pendentes, Aprovadas e Descartadas:
   - **Aprovar** (com as edições feitas nos campos) e **Aprovar Todas as Pendentes**;
   - **Descartar**;
   - **Pedir Substituta** — gera uma pergunta nova no lugar; a original vai para
     Descartadas só depois que a substituta é gravada;
   - **Devolver para Revisão** — leva uma aprovada ou descartada de volta a pendente,
     na menor posição livre.
6. **Pesos** — cada alternativa recebe um peso de -20 a +20 em cada eixo; é o que
   alimenta o perfil do jogador. "Aprovar Todas" já pede os pesos das que aprova,
   numa chamada só, quando a instância tem matriz de eixos; a aprovação uma a uma
   não pede. "⚖ Pesar os que faltam" cobre o resto, e "reatribuir todos" refaz
   todos — necessário depois de trocar a matriz de eixos. Aprovar ou salvar uma
   pergunta com o texto alterado apaga o peso dela, que passa a faltar.
7. **Status** — calculado sozinho, nunca escolhido:
   - **Rascunho** — falta a matriz de eixos e perfis, ou não há pergunta;
   - **Em revisão** — tem matriz e perguntas, mas ainda não está Pronto;
   - **Pronto** — eixos e perfis completos, aprovadas ≥ perguntas por partida (N)
     e todas as aprovadas com peso: o jogo entrega o veredito completo. A meta do
     conjunto não bloqueia o selo; quando é maior que N e ainda não foi atingida,
     aparece como complemento no "o que falta".

   A listagem e o formulário mostram **o que falta**. O status volta de Pronto
   para Em revisão sozinho se as aprovadas ficarem abaixo das perguntas por
   partida (por exemplo, o editor devolver ou descartar aprovadas até passar do
   limite) ou se o editor aumentar as perguntas por partida acima das aprovadas.
8. **Publicar** — o shortcode `[news-game id="1"]` (ID numérico da instância)
   renderiza o jogo em qualquer página. ⚠️ **O status não bloqueia o shortcode:**
   ele mostra o jogo no estado atual em qualquer página onde estiver, Pronto ou não.

## Fluxo do jogador

1. Responde dilemas apresentados sequencialmente
2. Cada escolha acumula pesos nos 3 eixos da instância
3. Ao final, o próprio navegador calcula o vetor do jogador e escolhe o perfil mais
   próximo por distância euclidiana — sem chamada ao backend nem à IA
4. Exibe: gráfico dos 3 eixos + nome do perfil + descrição do perfil + botões
   "Compartilhar" (copia o endereço) e "Jogar novamente"

Sem eixos, sem perfis ou sem nenhuma resposta com peso, o jogo mostra um fim
genérico, sem perfil.

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
| `API_SECRET_TOKEN` | Sim | — | Token para autenticar chamadas do plugin WP. Obrigatório e sem valor padrão: a aplicação se recusa a subir sem ele |
| `CHROMA_DB_DIR` | Sim | `/data/chroma_db` | Caminho do banco vetorial (ajustar no Windows) |
| `LLM_PROVIDER` | Não | `gemini` | Provedor quando o painel não manda `X-LLM-Provider`: `gemini`, `deepseek`, `openai` ou `claude` |
| `GEMINI_API_KEY` | Não* | — | Chave do Gemini |
| `DEEPSEEK_API_KEY` | Não* | — | Chave do DeepSeek |
| `OPENAI_API_KEY` | Não* | — | Chave da OpenAI |
| `CLAUDE_API_KEY` | Não* | — | Chave do Claude (não `ANTHROPIC_API_KEY`) |
| `LLM_MODEL` | Não | `gemini-3.6-flash` | Modelo do **Gemini** |
| `LLM_MODEL_DEEPSEEK` | Não | `deepseek-v4-flash` | Modelo do DeepSeek |
| `LLM_MODEL_OPENAI` | Não | `gpt-5.6-luna` | Modelo da OpenAI |
| `LLM_MODEL_CLAUDE` | Não | `claude-sonnet-5` | Modelo do Claude |
| `LLM_TEMPERATURE` | Não | `0.3` | Temperatura do modelo |
| `EMBED_MODEL` | Não | `paraphrase-multilingual-MiniLM-L12-v2` | Modelo de embeddings |
| `RAG_FETCH_K` | Não | `20` | Documentos buscados antes do MMR |
| `RAG_K` | Não | `5` | Documentos retornados após MMR |
| `RAG_CONTEXT_WINDOW` | Não | `40000` | Tamanho máximo do contexto RAG em chars |
| `MAX_RETRIES_BATCH` | Não | `5` | Tentativas por dilema antes de desistir |
| `RATE_LIMIT_SLEEP` | Não | `60` | Segundos de espera ao atingir rate limit |

*Todas opcionais no `.env`: a chave pode chegar por header (é o que o painel faz)
ou pelo body. Preencha só a do provedor que o backend deve atender sozinho.

⚠️ O backend **recusa subir** com variável no `.env` que não exista em
`config.py` (`extra='forbid'`). Não acrescente nomes por conta própria.

---

## Contribuição

Veja `CONTRIBUTING.md` para contribuir e `SECURITY.md` para relatar falhas de segurança.
O estado atual e os próximos passos estão em `ROADMAP_OPEN_SOURCE.md`; a dívida técnica,
em `TECH_FLAGS.md`; as decisões de arquitetura e as regras de desenvolvimento, em
`CLAUDE.md`.

---

*Originado no [Jogo da Amazônia](https://github.com/amazonia-real) — jornalismo interativo da maior floresta tropical do mundo.*

## Uso de IA

O Jogo da Amazônia e o News Game Plugin foram inteiramente desenvolvidos pelo jornalista Eduardo Nunomura, em vibe coding com o apoio de ferramentas de IA generativa: o Claude (Claude.ai e Claude Code, da Anthropic) e o Gemini (Google). Cerca de 80% do trabalho foi feito com o Claude e 20% com o Gemini.

Todas as decisões de produto são do autor. O código escrito por IA é apresentado em diff e revisado antes de cada commit, e o que ainda não foi testado em execução está marcado como tal na documentação do projeto (`CLAUDE.md`, §10). A autoria por ferramenta é registrada nesta declaração, e não commit a commit.

As perguntas geradas pelo plugin passam obrigatoriamente pela Mesa Editorial, com revisão humana antes de ir ao ar.
