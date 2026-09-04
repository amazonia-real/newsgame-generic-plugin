# Tech Flags — Dívida Técnica Monitorada
> Consulte este arquivo antes de qualquer alteração no backend ou no plugin.
> FLAGS 001+ documentam decisões do news-game. Arquivo ao final contém FLAGS herdadas do JDA.

---

## FLAG 001 — Padrão LLM: Validação Pydantic + Auto-Reparo em 2 Tentativas

**Status:** ✅ Implementado — aplicado nas três rotas do backend (`/analyze-domain`, `/assign-weights`, `/generate-verdict`)

**Problema:**
O Gemini retorna JSON mal-formado com frequência suficiente para quebrar produção: blocos markdown
(` ```json `), campos faltando, tipos errados. Precisávamos de resiliência automática sem acionar
o editor humano a cada falha de parse.

**Solução implementada:**
Todas as rotas seguem o mesmo padrão de 5 passos:

1. **Resolver chave de API** por prioridade: `header X-Gemini-Api-Key` → `body.gemini_api_key` → `env GEMINI_API_KEY`
2. **Chamar o LLM** com `[SystemMessage, HumanMessage]` via `langchain_google_genai.ChatGoogleGenerativeAI`
3. **Extrair o JSON** com `_extract_json()` — regex que descarta blocos markdown antes do `json.loads()`
4. **Validar via Pydantic** — se falhar, devolve a resposta inválida + mensagem de erro ao Gemini pedindo correção
5. **Levantar HTTP 422** se a tentativa 2 também falhar — nunca retorna dado inválido silenciosamente

```python
# Padrão canônico — replicado nas 3 rotas
try:
    data   = _extract_json(raw_text)
    result = DomainAnalysisResponse(**data)          # validação Pydantic
except Exception as erro:
    retry_messages = messages + [
        AIMessage(content=raw_text),
        HumanMessage(content=f"Sua resposta falhou por: {erro}. Corrija e retorne apenas o JSON.")
    ]
    raw_text2 = llm.invoke(retry_messages).content
    data      = _extract_json(raw_text2)
    result    = DomainAnalysisResponse(**data)       # HTTP 422 se falhar novamente
```

**Rota `/analyze-domain` em detalhe:**
- **Input:** `context_text` (corpus livre do editor) + `perfis_disponiveis` (opcional) + chave Gemini
- **Output:** `DomainAnalysisResponse` com:
  - `eixos_principais` — 3 eixos (`AxisOption`: nome, descrição, polo_positivo, polo_negativo)
  - `eixos_reserva` — 2 opções alternativas por eixo (para troca editorial)
  - `perfis_sugeridos` — 4–6 perfis com `vetor_aproximado: {eixo1, eixo2, eixo3}` em [-1.0, 1.0]
- **Sem RAG:** lê diretamente o `context_text` — não consulta ChromaDB nesta rota
- **Eixos emergem do texto:** o system prompt proíbe categorias pré-fixadas (ex: ambiental/social/econômico)

**Risco remanescente:**
Validação Pydantic verifica estrutura, não semântica. O Gemini pode retornar eixos genéricos que não
emergem do corpus e passar na validação. A responsabilidade de verificar qualidade semântica é do
editor na interface WordPress.

**Onde está:**
- `backend-python/app/main.py` — rotas e padrão de chamada LLM
- `backend-python/app/models.py` — schemas Pydantic (`AxisOption`, `AxisSet`, `DomainAnalysisResponse`, etc.)

---

## FLAG 002 — Complexidade Ciclomática do JavaScript

**Status:** ✅ Implementado — A4, 2026-08-21. **Cobertura completada pelo A3 em
2026-08-28** (`eff1f3f`).

**Cobertura: todo o JS do plugin — 1.982 linhas.** 1.279 em `admin/js/` (6
arquivos) e 703 em `public/js/`. Até 27/08 eram ~33%: a trava alcançava dois
arquivos `.js` (~472 linhas) e as outras ~959 viviam **inline** em blocos
`<script>` dentro dos views PHP, que não são lintáveis porque ESLint não lê JS
dentro de PHP. O A3 tirou os cinco blocos de lá.

> **A ressalva de cobertura não foi apagada, foi substituída** — ver "Limite de
> cobertura" abaixo, que agora descreve o limite que sobrou. Ele é de outra
> natureza: a trava lê arquivos `.js`, então **código novo que nascer inline num
> `<script>` de view PHP continua invisível para ela**. O que mudou é que não há
> mais dívida acumulada atrás disso.

**Contexto:**
O JavaScript do plugin (`public/js/newsgame-game.js` e o inline 
de `page-instance-form.php`) não tinha controle de complexidade 
ciclomática. Durante a fase de UX, o JS vai crescer significativamente.

**Duas premissas da redação original estavam erradas:**
1. *"Adicionar apenas a regra `complexity` na config ESLint existente"* —
   **não havia config ESLint.** Não havia `package.json`, `.eslintrc` nem
   `eslint.config.*` em nenhum lugar do repositório. Executar a flag exigiu
   criar o ferramental do zero (reescopado no roadmap em 2026-08-05)
2. *"Não instalar dependências adicionais"* — o ESLint **é** uma dependência
   (69 pacotes com as transitivas). Fica como `devDependency` e `node_modules/`
   segue fora do git: **não entra no runtime do jogo e não é requisito de
   instalação do plugin**. Quem só quer usar precisa de WordPress + Python,
   como antes. A regra do `CLAUDE.md` §7 ("JS vanilla, sem bibliotecas novas")
   governa o que o navegador do leitor baixa, e isso não mudou

**O que foi implementado:**
- `package.json` na raiz — `eslint` como única `devDependency`, um único
  script (`npm run lint`). Sem build, sem Prettier, sem plugins
- `eslint.config.mjs` — flat config com **uma regra**, `complexity: ['error', 10]`.
  Não é `.eslintrc`: esse formato foi removido no ESLint 9 e não é lido no 10
- `.gitignore` ganhou `!/package.json` e `!/package-lock.json` — a regra
  `/*.json` da raiz (que barra arquivos de acervo) engolia os dois, e o
  ferramental nasceria invisível para quem clonasse

**Auditoria de complexidade — 50 funções medidas, 1 acima do limite:**

| Função | Complexidade |
|---|---|
| `buildRadarSVG` | 12 |
| `NgGame` | 8 |
| `buildNarrativa` | 7 |
| `renderVeredicto` / `renderVerdictNarrativa` | 6 |
| `renderEnd` | 5 |
| (cauda: 43 funções) | 1–4 |

> ⚠️ *A tabela acima é a auditoria de **2026-08-21** e fica como registro: duas das
> funções listadas **não existem mais**. `buildNarrativa` e `renderVeredicto` foram
> removidas em 29/08 (`7d7a64d`) — a primeira por ficar sem chamador com o veredito
> simplificado, a segunda renomeada para `renderFeedback` na anatomia do JDA. O
> `buildRadarSVG` caiu de 12 para **exatamente 10** na fase 1 do H2 e segue no teto,
> com margem zero.*

O limite 10 **não bloqueou nada de saída** — há 2 pontos de folga até a
segunda pior função. `buildRadarSVG` recebeu `eslint-disable-next-line`
com justificativa no código: os 12 não são fluxo de controle (não há um
`if`, `for` ou `case` no corpo), são 9 curtos-circuitos de valor default
mais 2 ternários de cor — a regra cobra `||` com o mesmo peso de um `if`.
Não foi refatorado porque é o código do gráfico triangular validado em
runtime em 13/08 (C7), e mexer nele exigiria nova partida completa.

**Limite de cobertura — reescrito em 2026-08-28, depois do A3:**
A redação anterior dizia que o bloco `<script>` inline de
`page-instance-form.php` não era lintável, que eram 5 blocos e ~959 linhas
contra ~472 cobertas, e que os outros 67% só entrariam com o A3 — que
estava fora do escopo do lançamento. **Os 5 blocos saíram em 28/08** e a
cobertura é integral.

O limite que **sobra** é de outra natureza, e é permanente enquanto a trava
for ESLint: ela lê arquivos `.js`. **JS novo que nascer inline num
`<script>` de view PHP não é medido.** A diferença em relação a antes é que
isso deixou de ser dívida acumulada e passou a ser só um cuidado de
processo — o `npm run lint` "exit 0" não prova que não existe JS inline
novo, prova que o JS **em arquivo** está sob o limite. Conferir com
`grep -c "<script" news-game-plugin/admin/views/*.php` (hoje: 0, 0, 0).

**A medição do A3 encontrou duas funções acima de 10 que estavam
invisíveis** — o que é evidência de que a lacuna de 33% era real e não
teórica:

| Função | Complexidade | Saída |
|---|---|---|
| `buildRow` (`admin/js/newsgame-admin-review.js:141`) | **15** | `eslint-disable-next-line complexity` com justificativa no código, pelo mesmo critério do `buildRadarSVG`: os 15 são 14 ternários e `\|\|` de default montando string de HTML, sem um `if`, `for` ou `case`. Refatoração em 3 helpers fica como **dívida declarada** |
| Handler do "Analisar Acervo" (`newsgame-admin-axis.js`) | **12** | Resolvido: a cadeia de 5 `\|\|` saiu para `acharCampoAcervo()`. Handler **7**, helper **6**. Sem diretiva |

**Validação (regra do ✅ — ver §6 do roadmap):**
Quatro testes executados em 2026-08-21, config nunca aceita por leitura:
1. `npm run lint` na árvore limpa → exit 0
2. Função-sonda com complexidade 11 gravada em `public/js/` → erro
   apontando a sonda, exit 1. **Prova que a regra alcança os arquivos do
   plugin**, e não que passou por não ter sido aplicada
3. `eslint . --no-inline-config` → reexpõe `buildRadarSVG` (12). Prova que
   é o `disable` que suprime, e que a função continua sendo medida
4. `disable` obsoleto numa função trivial → *"Unused eslint-disable
   directive"*. Se alguém refatorar o `buildRadarSVG` no futuro, o
   comentário morto é denunciado (como **warning**, exit 0 — não quebra
   o lint)

Sondas removidas ao fim de cada teste. `eslint . -f json` confirma 3
arquivos percorridos: os 2 do plugin + a própria config — os JS vendorizados
do `.venv` do backend estão excluídos.

**Onde monitorar** — desde o A3, todos cobertos:
- `news-game-plugin/public/js/newsgame-game.js` (703) — ⚠️ `buildRadarSVG` está em
  **exatamente 10**, margem zero
- `news-game-plugin/admin/js/newsgame-admin-review.js` (548) — ⚠️ `buildRow` em
  **15**, com `disable` justificado
- `news-game-plugin/admin/js/newsgame-admin-generation.js` (239)
- `news-game-plugin/admin/js/newsgame-admin-axis.js` (190)
- `news-game-plugin/admin/js/newsgame-admin-form.js` (181)
- `news-game-plugin/admin/js/newsgame-admin-settings.js` (96)
- `news-game-plugin/admin/js/newsgame-admin-instances.js` (25)
- `news-game-plugin/admin/views/*.php` — **não** por linting: por
  `grep -c "<script"`, que deve continuar dando **0** nas três views

---

## FLAG 003 — Segurança: Segredos Fora do Browser e Autenticação Robusta

**Status:** ✅ Implementado — commits `C1+A2`, `C2`, `C3`, `C4` (10/06/2026)

**Problema:**
O JS do painel WordPress chamava `/analyze-domain` e `/assign-weights` diretamente do
backend Python em alguns pontos, arriscando expor segredos ao browser; erros do
backend devolviam traceback ao cliente (e um workaround retornava HTTP 200 mesmo em
falha); o token de autenticação (`api_secret_token`) tinha valor default, permitindo
o servidor subir sem proteção real; e havia 5 ocorrências de verificação TLS
desabilitada (`sslverify`/`CURLOPT_SSL_VERIFYPEER = false`) no plugin.

**Solução implementada:**
- **C1+A2** (`fb2c828`): `/analyze-domain` e `/assign-weights` passam a exigir
  `Depends(_verify_token)`; `handle_analyze_domain()` atua como proxy PHP; header
  `X-Api-Key` adicionado ao `wp_remote_post` de `/assign-weights`. Nenhum fetch direto
  do JS para o backend Python permanece.
- **C2** (`c908da9`): `logging.exception()` no servidor; cliente recebe só `detail` +
  `dilema_invalido`; removido o workaround que retornava HTTP 200 em erro.
- **C3** (`1960a75`): `api_secret_token` deixou de ter default (campo obrigatório);
  comparação via `secrets.compare_digest` (proteção contra timing attack); servidor
  recusa subir (503) sem `API_SECRET_TOKEN` configurado.
- **C4** (`4515f8d`): as 5 ocorrências de TLS desabilitado corrigidas em
  generator/ingestor/admin-instances/review; TLS só é desabilitado em dev local
  (`WP_DEBUG` + localhost).

**Onde está:**
- `backend-python/app/main.py`, `config.py`
- `news-game-plugin/includes/class-newsgame-admin-instances.php`,
  `class-newsgame-generator.php`, `class-newsgame-ingestor.php`,
  `class-newsgame-review.php`
- `news-game-plugin/admin/views/page-instance-form.php`

**Risco remanescente:**
Nenhuma rota nova deve ser exposta ao JS do painel sem seguir este mesmo padrão
(proxy PHP + `X-Api-Key` + `Depends(_verify_token)`).

---

## FLAG 004 — Resiliência e Limpeza de Dívida Técnica

**Status:** ✅ Implementado — commits `A1`, `A3`, `A4`, `A5` (10–11/06/2026)

**Problema:**
Quatro pontos de fragilidade acumulados: geração de veredito sem limite de taxa
(mesmo sendo cálculo local); caminho Windows hardcoded em `rag.py`; duplicação de
chunks no ChromaDB ao reingerir o mesmo artigo; e ~150 linhas de um fluxo de polling
em background que nunca era alcançado no plugin.

**Solução implementada:**
- **A1** (`4af7bec`): throttle de 5 requisições/min por IP em
  `ajax_generate_verdict`; cache de 24h por `instance_id` + vetor arredondado.
  **⚠️ Este código não existe mais — ver correção abaixo.**
- **A3** (`91d357e`): `chroma_dir` passa a vir de `get_settings().chroma_db_dir`, com
  `RuntimeError` claro se o diretório não existir (antes: caminho fixo de máquina de
  desenvolvimento).
- **A4** (`0af2472`): upsert com IDs determinísticos no ingest — elimina duplicação
  de chunks ao reindexar o mesmo artigo.
- **A5** (`c3900d1`): removido fluxo de polling em background inalcançável (~150
  linhas em `class-newsgame-generator.php`).

**Onde está:**
- ~~`news-game-plugin/includes/class-newsgame-shortcodes.php` (A1)~~ — removido
- `backend-python/app/rag.py` (A3)
- `backend-python/app/ingestor.py` (A4)
- `news-game-plugin/includes/class-newsgame-generator.php` (A5)

### Correção do A1 — 2026-08-05

**O throttle do A1 foi deletado um mês depois de ser criado, e esta FLAG
registrou como implementado algo que já não existia.**

O commit seguinte ao `4af7bec` neste mesmo arquivo foi o `dd794aa` ("veredito
dinâmico local"), que moveu o cálculo do veredito para o JS e removeu 94
linhas de `class-newsgame-shortcodes.php` — levando o endpoint AJAX inteiro,
com throttle e cache dentro. A FLAG 004 foi redigida retroativamente em
`cd767eb` (21/07/2026) sem notar a remoção.

Estado medido em 2026-08-05, antes da correção: `grep` por
`set_transient|get_transient|rate.?limit|throttle` no plugin inteiro não
retornava **nenhum** throttle. `class-newsgame-shortcodes.php` tem 127 linhas
e nenhum handler AJAX — o veredito é 100% client-side, sem custo de API por
jogada, o que torna o throttle original desnecessário naquele ponto.

**O throttle novo protege outro alvo: cota da chave Gemini do adotante.**
As rotas que gastam cota são as de geração e análise, não o veredito. Modelo
de ameaça é erro operacional — clique duplo numa UI que parece travada, bug
futuro de JS em loop — e não ataque externo: as quatro rotas já exigem editor
autenticado com `manage_options` atrás de proxy PHP (FLAG 003).

| Rota | Limite/min | Por quê |
|---|---|---|
| `/analyze-domain` | 10 | Um clique por instância |
| `/assign-weights` | 10 | Um clique processa todos os dilemas de uma vez |
| `/regenerate` | 10 | Um botão por dilema — o mais exposto a clique duplo |
| `/generate-one` | 60 | Loop legítimo de até ~15 chamadas + retentativas |

Implementação: `Newsgame_Settings::rate_limited( $bucket, $limit_per_min )`.
Contador de janela fixa em transient, **chaveado por usuário e não por IP** —
dois editores atrás do mesmo NAT não disputam cota. A janela é gravada no
campo `reset` do valor em vez de depender do TTL: incrementar com
`set_transient()` renovaria o TTL a cada chamada e viraria "60 s de silêncio
obrigatório", quebrando o loop legítimo do `/generate-one`.

**Onde está:** `class-newsgame-settings.php` (helper),
`class-newsgame-generator.php`, `class-newsgame-admin-instances.php`,
`class-newsgame-review.php`.

**Risco remanescente:** janela fixa permite rajada de até 2× na virada.
Aceitável para erro operacional; insuficiente se o modelo de ameaça mudar
para abuso deliberado por um editor autenticado. Transients podem ser
não-persistentes sob certos object caches, o que torna o limite mais
permissivo — nunca mais restritivo.

---

## FLAG 005 — Infraestrutura: Proxy Sem CORS, Logging Estruturado, Telemetria

**Status:** ✅ Implementado — commits `M1`, `M2`, `M4`, `M5` (11/06/2026)

**Problema:**
A rota `/generate-one` ainda era chamada diretamente do browser (expondo `api_key`),
o que também obrigava manter `CORSMiddleware` com `allow_origins=["*"]`; os logs
eram `print()` sem estrutura; e a telemetria do ChromaDB (`ANONYMIZED_TELEMETRY`) não
respeitava o `.env` por falta de `load_dotenv` em certos pontos de entrada.

**Solução implementada:**
- **M1** (`4593c11`, `73796f2`): o loop de geração no painel passa a chamar
  `wp_ajax_newsgame_generate_one` (admin-ajax), que repassa o payload
  server-to-server via `wp_remote_post` (timeout 120s), preservando os status
  429/422 que o JS usa para retry/skip; `ajax_start` deixou de devolver `backend_url`
  e `api_key` ao browser. Com isso, nenhum `fetch()` do plugin acessa o backend
  Python direto — `CORSMiddleware` (`allow_origins=["*"]`) e o import correspondente
  foram removidos.
- **M2+M4** (`02d7b39`): `print()` migrado para logging estruturado
  (`logging_config.py` novo) em `generator.py`, `main.py`, `rag.py`,
  `regenerator.py`.
- **M5** (`e829c56`, `60bbe91`): `.gitignore` e `.env.example` revisados, telemetria
  desativada por padrão; garantido `load_dotenv` para que `ANONYMIZED_TELEMETRY`
  chegue ao ChromaDB.

**Onde está:**
- `backend-python/app/main.py`, `logging_config.py`, `generator.py`, `rag.py`,
  `regenerator.py`
- `news-game-plugin/admin/views/page-instance-form.php`,
  `includes/class-newsgame-generator.php`

**Nota:** com o M1, toda a superfície do backend Python passou a ser server-to-server
(nenhuma rota chamada direto do browser) — decisão coerente com e reforçada pela
FLAG 003.

**Risco remanescente do M5 — medido em 2026-08-05:**
O M5 está correto sobre o encanamento e sobre o `.env.example`, mas a afirmação
"telemetria desativada por padrão" merece precisão, porque esta FLAG é citada
como trilha de auditoria no guia de portabilidade:

- **A desativação depende de o `.env` ter a variável.** No `.env` desta máquina
  de desenvolvimento `ANONYMIZED_TELEMETRY` está **ausente** — o
  `load_dotenv()` funciona, só não há o que carregar, e o ChromaDB cai no
  default `True`. O `.env.example:24` traz a linha, então quem seguir o guia
  (`cp .env.example .env`) fica coberto; quem montar o `.env` à mão, não.
- **`posthog` não está pinado no `requirements.txt`.** Com
  `chromadb==0.5.11` (assinatura posicional antiga) e posthog 7.x instalado, a
  tentativa de telemetria falha com
  `capture() takes 1 positional argument but 3 were given`. Observado em
  `POST /generate-one` durante o teste do C1; a rota retorna 200 OK e a geração
  não é afetada.

Nenhum dos dois é vazamento de dado — a telemetria **falha** ao ser enviada.
São questões de reprodutibilidade de install e de precisão da documentação.
Ação registrada como D3 no `ROADMAP_OPEN_SOURCE.md`.

**Atualização 2026-08-07 — `.env` corrigido e efeito colateral no `Settings`:**
`LOG_LEVEL` e `ANONYMIZED_TELEMETRY` foram acrescentados ao `.env` desta máquina,
o que resolve o primeiro item do risco acima (o segundo, o `posthog` não pinado,
continua aberto como D3). O acréscimo, porém, quebrou o boot do uvicorn com
`extra_forbidden`: o `pydantic-settings` lê o mesmo `.env` que o `load_dotenv()` e,
no modo padrão, rejeita chave que não seja campo declarado. Corrigido declarando
os dois campos em `config.py` (`log_level: str = "INFO"`,
`anonymized_telemetry: bool = False`).

Fica registrado que **são dois caminhos de leitura distintos** para as mesmas
variáveis, e é isso que torna o erro pouco intuitivo:

| Variável | Quem consome de fato | Papel do `Settings` |
|----------|----------------------|---------------------|
| `LOG_LEVEL` | `logging_config.py:14` via `os.getenv` | só validar |
| `ANONYMIZED_TELEMETRY` | ChromaDB via ambiente (`load_dotenv` em `main.py:14`) | só validar |

**Limpeza futura (pequena, sem pressa):** fazer o `logging_config.py` consumir
`get_settings().log_level` em vez de `os.getenv("LOG_LEVEL")`, para haver uma
fonte única. Hoje os dois caminhos concordam porque compartilham o default
`"INFO"` — divergiriam se alguém mudasse um dos dois. Não bloqueia nada.

---

## FLAG 006 — Incidente: Contaminação Cruzada JDA → News-Game (RESOLVIDO)

**Status:** ✅ Corrigido em 21/07/2026 — registrado retroativamente (o incidente
ocorreu em 12/06/2026)

**Problema:**
O commit `83f41e1` ("veredito dinâmico local...") misturou, junto com a mudança real
(94 linhas em `shortcodes.php`, 95 em `newsgame-game.js`), **41.635 linhas de
arquivos do Jogo da Amazônia original**: `acervo_amazonia.txt`,
`acervo_amazonia_v2.json`, `dilemas_para_revisao.txt`, e 10 scripts Python
(`build_db.py`, `check_db_count.py`, `clean_identity.py`, `completar_matriz.py`,
`config.py`, `exportar_dilemas.py`, `extrair_acervo_wp.py`,
`gerar_acervo_imagens.py`, `gerar_matriz_crise_climatica.py`,
`inserir_tags_imagem.py`). Viola diretamente a regra absoluta do `CLAUDE.md` §8.

Os commits seguintes (`07b743a`, `86e8ff8`) desindexaram os arquivos de dados
(`.txt`/`.json`) e reforçaram o `.gitignore`, mas os 10 scripts Python continuaram
rastreados até esta auditoria.

**Solução implementada:**
`git rm --cached` nos 10 scripts (commit `2c89798`) — confirmado por busca que
nenhum código atual de `backend-python/app` ou `news-game-plugin` os referencia.
Arquivos físicos mantidos localmente; `.gitignore` (`/*.py`, linha 40) já impede que
arquivos `.py` na raiz voltem a ser rastreados.

**Lição para não repetir:**
Antes de commitar mudanças que tocam múltiplos arquivos, revisar `git status`/
`git diff --stat` para confirmar que só os arquivos pretendidos estão staged —
especialmente ao trabalhar em paralelo nos dois repositórios (`E:\news-game` e
`E:\jogo-da-amazonia`).

**Atualização de 2026-07-31 — o histórico FOI reescrito.** Este parágrafo dizia
que não haveria rewrite porque o conteúdo não era sensível. Deixou de valer: a
auditoria de segurança de 2026-07-29 encontrou **PII de terceiros** (16 CPFs,
114 e-mails, 3 chaves PIX) nos arquivos de acervo commitados no commit inicial
`fcd775b`, o que bloqueava tornar o repositório público. Em 2026-07-31 o
histórico foi reescrito com `git filter-repo` (removendo `acervo_amazonia*`,
`jogo-amazonia-plugin/` herdado e `.claude/`, e redigindo os segredos
`jda2026`/`teste123`), o repositório do GitHub foi **deletado e recriado** — não
foi `push --force`, para não deixar blobs órfãos alcançáveis pela API — e o
histórico limpo foi enviado. Novo HEAD de referência: `387a687`. Pack de ~88 MB
para ~1 MB.

**Consequência prática:** qualquer clone anterior a 2026-07-31 está obsoleto e
não deve receber merge nem push — reclonar. Os históricos antigos, com a PII,
sobrevivem só em espelhos locais na máquina do Eduardo e são material sensível.

**Onde está:** commit `2c89798` (a correção original) e a reescrita de
2026-07-31. O detalhamento completo está em
`E:\jogo-da-amazonia\AUDITORIA_SEGURANCA.md` e
`REAUDITORIA_ZERADA_2026-07-31.md`.

---

## FLAG 007 — Compatibilidade com CSP estrita (pré-requisito de distribuição)

**Status:** 🟡 Parcial — superfície **pública** fechada em 2026-08-08 (passos 1 e 2),
superfície **autenticada** em 2026-08-28 (passo 3, `eff1f3f`). Segue parcial por
causa do **passo 4** (exemplo de CSP no README), que depende do **A5**.
Diagnóstico original de 2026-07-31.

> ⚠️ **A precisão que esta flag tem de carregar depois do passo 3, para não virar
> um ✅ que promete mais do que entrega:** o admin ficou com **zero JS inline de
> LÓGICA**, não com zero JS inline. O `wp_localize_script` emite, por handle, um
> `<script id="…-js-extra">` inline com um `var` — é **dado**, não lógica, e
> continua exigindo `'unsafe-inline'` em `script-src` **na superfície
> autenticada**. A promessa do passo 1 (superfície **pública** sem JS inline)
> segue intacta e é outra coisa. **Não somar as duas.** Quem escrever o cabeçalho
> de exemplo do passo 4 precisa dizer qual das duas superfícies está descrevendo
> — é a mesma classe de defeito de redação que a ressalva do A5 registra para
> `style-src`, logo abaixo.

**Validado em runtime em 2026-08-08** — não marcado ✅ por revisão de código:
- **Passo 1** (`719838c`): jogo carregado do início ao fim, dilemas corretos,
  `Ctrl+U` confirmou o bloco `application/json` e a ausência de
  `window._ngGame_` na página
- **Passo 2** (`75e143f`): `newsgame-admin-instances.js` servido com `200`, caixa
  de confirmação com o nome da instância entre `«»` e a segunda linha separada,
  `Cancelar` mantendo na listagem sem excluir

> Achado durante o teste do passo 1, **sem relação com a CSP**: o veredito e o
> gráfico triangular não aparecem no news-game, embora a §10 do `CLAUDE.md` os
> registre como ✅. Suspeita no gate de `public/js/newsgame-game.js:342`
> (`data.perfis && data.eixos && pesoCount > 0`), que degrada em silêncio nos
> três termos. O transporte dos dados está descartado como causa: todo o objeto
> vem de um único `JSON.parse`, então os dilemas renderizarem prova que
> `perfis`/`eixos` também chegaram. Em diagnóstico.

**Origem:** reauditoria de segurança do Jogo da Amazônia (2026-07-31), que
concluiu: *no JDA a defesa real contra conteúdo malicioso é a revisão humana
das fundadoras — e essa defesa não viaja com o plugin.* O news-game é
justamente o plugin que **vai** viajar.

### O problema, e por que ele é do news-game e não do JDA

**CSP** (`Content-Security-Policy`) é o cabeçalho em que a página declara ao
navegador de onde ele pode executar script. Com `script-src` estrito, um
`<script>` injetado **não roda** — o navegador se recusa, mesmo que a injeção
tenha funcionado. É a rede que segura quando o escape de HTML falha.

No news-game a cadeia de contaminação é `resposta do LLM → banco → HTML`, e
**não há editora humana no meio** na instalação de destino. Um veículo qualquer
instala, aponta o pipeline para o acervo dele e publica. Se o escape falhar uma
vez, em qualquer versão futura editada por terceiros, não há segunda linha de
defesa. Daí a CSP ser requisito de distribuição, não refinamento.

**Princípio:** o plugin **não deve emitir** CSP própria — isso é decisão do site
hospedeiro. O plugin deve ser **compatível** com uma CSP estrita que o site
imponha, sem exigir `'unsafe-inline'` por causa dele.

### Estado medido (2026-07-31)

| Superfície | Bloqueadores de `script-src` | Gravidade |
|---|---|---|
| **Pública** (o que o leitor carrega) | **1** — `wp_add_inline_script` em `includes/class-newsgame-shortcodes.php:90`, que emite `window._ngGame_<id> = {...}` | 🎯 é o único que importa |
| **Admin** (autenticada) | 5 blocos `<script>` inline: `admin/views/page-instance-form.php:387, 601, 819, 1233` e `page-settings.php:108`; mais 1 `onclick` em `page-instances.php:84` | Baixa — só usuário logado |
| Estilos | ~70 atributos `style="` | Ignorar — `style-src` não vale o esforço. *Os ~70 são plugin inteiro; na superfície **pública** são 5 desde 29/08 (eram 6), em 4 grupos — ver a ressalva do passo 4 e o A5* |

**Boa notícia: a página pública está a UMA mudança de ficar limpa.** Isso é
melhor que o JDA, onde há 4 `onerror` inline no template mais 2 `<script>`
inline — lá a CSP exigiria refatorar o template todo.

### Solução recomendada — bloco de dados não-executável

Trocar o `wp_add_inline_script` por um `<script type="application/json">`. A CSP
`script-src` **não governa** script com MIME não-executável: o navegador trata
como bloco de dados, não como código. Resultado: zero JS inline na página
pública, compatível com **qualquer** CSP do site hospedeiro, sem nonce e sem
configuração no destino.

```php
// includes/class-newsgame-shortcodes.php — em vez de wp_add_inline_script()
return '<div id="ng-game-' . $id . '" class="ng-game-wrap">'
     . '<script type="application/json" id="ng-game-data-' . $id . '">'
     . $json   // já sai de wp_json_encode com JSON_HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT
     . '</script>'
     . '<div class="ng-game-inner"></div>'
     . '</div>';
```

```js
// public/js/newsgame-game.js:203 — em vez de window['_ngGame_' + gid]
var el   = document.getElementById('ng-game-data-' + gid);
var data = el ? JSON.parse(el.textContent) : null;
```

O `JSON_HEX_TAG` que já está no `wp_json_encode` continua sendo o que impede
fechar o `</script>` de dentro do JSON — mantê-lo é obrigatório.

### Ordem de execução sugerida

1. ✅ **O par acima** (`719838c`, 2026-08-08) — fecha a superfície pública. É o
   item que habilita a promessa "compatível com CSP estrita" no README.
2. ✅ `onclick="return confirm(...)"` de `page-instances.php:84` →
   `addEventListener` (`75e143f`, 2026-08-08).

   **Custou mais que as ~2 linhas previstas aqui, e vale registrar por quê:** o
   plugin não tinha **nenhum** `admin_enqueue_scripts` — os únicos enqueues
   eram os do frontend. Trocar o `onclick` por um `<script>` inline no próprio
   view teria ganho de CSP **zero** (bloco inline também exige
   `'unsafe-inline'`), então o passo exigiu criar o pipeline de assets do
   admin: arquivo `admin/js/newsgame-admin-instances.js` +
   `Newsgame_Admin_Instances::enqueue_assets()` + mensagem via
   `data-ng-confirm`. Esse pipeline é pré-requisito do passo 3, que agora
   herda a infra.

   Duas armadilhas encontradas no caminho: `esc_js` → `esc_attr` é
   **obrigatório** (o destino deixou de ser string JS e passou a ser atributo
   HTML), e o `\n\n` precisa ser newline real do PHP — `&#10;` não serve,
   porque o `esc_attr` escaparia o `&`.
3. ✅ **Concluído em 2026-08-28** (`eff1f3f`, item A3 do roadmap) — os 5
   `<script>` inline do admin viraram arquivos `.js` enfileirados com
   `wp_enqueue_script`, dados via `wp_localize_script`.
   **`page-instance-form.php` 1529 → 546 linhas; `page-settings.php` 183 → 110.**
   Zero `<script>` nas três views do admin.

   O que a previsão deste passo acertou e errou:
   - **Acertou** que o `enqueue_assets()` do passo 2 seria o ponto de entrada.
   - **A armadilha da guarda** (*"sai fora quando `action` é `new`/`edit`,
     exatamente as telas do passo 3"*) **não custou nada**: foi paga
     antecipadamente no H2 de 21/08, quando o `wp_enqueue_media()` precisou da
     mesma correção.
   - **A armadilha do `esc_js` → `esc_attr`** do passo 2 **nunca mordeu**: nenhum
     dado do passo 3 foi para atributo HTML. Todos passaram por
     `wp_localize_script`, e com isso o `esc_js()` **desapareceu** das 18 linhas
     de action/nonce em vez de mudar de função — o JSON passou a ser gerado e
     escapado pelo WordPress.
   - **Errou o custo relativo:** "trabalho maior, ganho menor (superfície
     autenticada)" descrevia só o ganho de CSP. O ganho que decidiu a execução
     foi o da **FLAG 002** — 1.279 linhas de JS entraram na trava de
     complexidade, e a medição achou duas funções acima do limite que ninguém
     sabia que existiam.
   - **Armadilha NOVA, que a previsão não tinha:** `wp_localize_script()` aplica
     `(string)` em todo escalar de topo (`WP_Scripts::localize()` faz
     `html_entity_decode( (string) $value )` e dá `continue` no que não é
     escalar). Arrays passam intactos. Um dos usos não sobreviveria a isso — a
     trava de troca de alternativas compara com `radio.value`, sempre string —, e
     por isso `num_options` sai do PHP já convertido.
4. **Documentar no README** um exemplo de cabeçalho CSP que o veículo pode
   aplicar, e declarar que o plugin funciona sob ele. Isso é argumento de
   adoção: nenhum plugin de quiz WP oferece isso. Já desbloqueado: a superfície
   pública não exige mais `'unsafe-inline'` **em `script-src`**.

   > **RESSALVA, registrada em 2026-08-21 — a frase acima promete mais do que
   > entrega, e é preciso ler a diretiva.** A superfície pública **ainda exige
   > `style-src 'unsafe-inline'`**, por causa de **5 atributos `style=`, em 4
   > grupos**. Atributo `style` exige `style-src 'unsafe-inline'` pelo mesmo
   > mecanismo que script inline exige `script-src` — não é uma classe
   > diferente de problema, e **as 5 acionam a diretiva**.
   >
   > *Eram 6 até 2026-08-28. O `7d7a64d` (29/08) baixou para 5 por consequência
   > da extração do `buildProgress()`, que fundiu num só os dois atributos de
   > largura da barra de progresso — as duas telas montavam o mesmo HTML. O grupo
   > dinâmico passou de 2 ocorrências para 1. **Nenhum dos 4 grupos foi fechado**,
   > e as referências de arquivo:linha foram reconferidas:
   > `newsgame-game.js:57,287,633,634` e `class-newsgame-shortcodes.php:94`.*
   >
   > | Onde | O quê | Removível? |
   > |---|---|---|
   > | `newsgame-game.js:226` | `color:#f88` — mensagem de erro | Sim |
   > | `newsgame-game.js:406`, `:407` | `display:block;margin:0 auto` — layout dos botões de compartilhar e reiniciar | Sim |
   > | `class-newsgame-shortcodes.php:38` | `font-family:monospace` — erro de instância sem dilemas aprovados | Sim |
   > | `newsgame-game.js:269`, `:320` | `width:N%` — barra de progresso | **Não** — dado dinâmico |
   >
   > **As 6 são removíveis, por dois caminhos diferentes** — e a coluna acima
   > diz "Não" só para *remoção via classe no CSS*, que não serve a valor
   > calculado em tempo de execução.
   >
   > **CSP `style-src` não restringe a CSSOM.** Atribuir propriedade
   > (`el.style.width = pct + '%'`) **não é bloqueado**; o que é bloqueado é
   > `style=` no markup, `el.setAttribute('style', …)` e `el.style.cssText`.
   > Então a barra de progresso sai do alcance da diretiva trocando a montagem
   > por string pela atribuição depois de inserir o elemento — sem perder o
   > dado dinâmico.
   >
   > **Armadilha a registrar antes de alguém tentar:** tem de ser atribuição de
   > *propriedade*. Trocar `style="width:…"` por `setAttribute('style', …)` ou
   > por `cssText` **não resolve nada** — as duas são bloqueadas igual. É o
   > mesmo tipo de detalhe do `esc_js` → `esc_attr` do passo 2.
   >
   > **Consequência para o A5:** a opção "compatibilidade total com `style-src`
   > estrita" **existe** e custa 4 grupos — 3 por classe no CSS, 1 por CSSOM.
   > Eu havia escrito aqui que ela não existia; estava errado, e a correção
   > amplia as opções em vez de reduzi-las.
   >
   > **Isso não reabre a decisão** de "O que NÃO fazer" abaixo: perseguir
   > `style-src` segue fora de escopo, e o argumento de retorno marginal de
   > segurança continua válido. O defeito é de **redação**: dizer "não exige
   > mais `'unsafe-inline'`" sem nomear a diretiva faz a equipe de TI que lê o
   > B1 concluir compatibilidade total, e ela não é total. Um ✅ que promete
   > mais do que entrega é o padrão que a §6 do roadmap existe para pegar.
   >
   > **Dado novo sobre o custo de fechar, se algum dia se quiser:** na
   > superfície **pública** são 5 em 4 grupos, não ~70 — os ~70 da tabela de
   > diagnóstico acima incluem o admin, e essa distinção nunca tinha sido
   > feita. A fase 1 do H2 removeu um sétimo (`style="display:block;margin:0
   > auto;"` do `<svg>` do radar) **sem ter sido planejada para isso**, o que
   > dá a medida do custo: os 3 grupos de dívida saem pelo mesmo caminho,
   > classe no CSS. Rastreado como **A5** no roadmap, como *decisão* a tomar —
   > três saídas, não duas —, não como tarefa de remoção já decidida.

### O que NÃO fazer

- Não emitir `Content-Security-Policy` pelo plugin (conflita com o site).
- Não resolver com nonce de CSP: funciona, mas exige que o site coordene o
  nonce com o plugin. O bloco de dados não exige nada do hospedeiro.
- Não perseguir `style-src` — os ~70 `style="` são cosméticos e a CSP de estilo
  tem retorno de segurança marginal.
  *Decisão mantida em 2026-08-21, com dois números corrigidos: na superfície
  **pública** são **5, em 4 grupos** — eram 6 até 28/08 — e não ~70, que incluem
  o admin. E o
  "cosméticos" merece nuance: um dos grupos é `width:N%` de barra de progresso,
  valor calculado em runtime, que não sai por classe no CSS (sai por CSSOM, que
  a diretiva não alcança). A consequência da decisão é que a promessa de CSP
  tem de ser escrita qualificada por diretiva; ver a ressalva no passo 4 e o A5.*

**Ver também:** FLAG 003 (segredos fora do browser) e a correção de
2026-07-31 que aplicou `esc()` nos nomes de eixo do radar mais
`sanitize_text_field()` no save — a CSP é a terceira camada sobre essas duas.

---

## FLAG 008 — Incidente de ambiente: MariaDB com `force_recovery` ligado e grant tables Aria corrompidas (RESOLVIDO)

**Status:** ✅ Ambiente reconstruído em 2026-08-22. Registrado retroativamente em
2026-08-24.

**Por que isto virou FLAG, sendo defeito de ambiente e não do plugin:** porque três
itens de documentação já apontavam para um episódio que não estava escrito em lugar
nenhum. Ele produziu (a) o banco sobre o qual o **C8** foi validado, (b) um mecanismo
candidato para o **C10**, e (c) o backup de 0 bytes que gerou o aprendizado de método
da §6 do roadmap. Sem este registro, as três referências ficam órfãs.

### Linha do tempo

1. **Junho/2026 — `innodb_force_recovery` ligado, e deixado ligado.** O motivo
   original **não está registrado** e não é mais recuperável. O efeito é que o InnoDB
   rodou cerca de dois meses em modo degradado sem que ninguém soubesse. No nível 1
   (`SRV_FORCE_IGNORE_CORRUPT`) o servidor **ignora página corrompida em vez de
   abortar**: a query volta com menos linhas — ou zero — e **sem erro**. Escrita
   continua permitida nesse nível, o que torna o modo indistinguível do normal em uso
   casual. É deste comportamento que sai o mecanismo candidato do C10.
2. **19/08 — o incidente do C10** (`wp_newsgame_dilemas` vazia) ocorreu **com o
   `force_recovery` ligado**. Isso não era conhecido na sessão de diagnóstico, e é o
   único dado novo que o item recebeu desde então. Ver a atualização de 24/08 lá —
   **sem evidência positiva**, e não reabre o item.
3. **22/08 — erro 1130 ao tentar o `mysqldump`.** `ERROR 1130 (HY000): Host … is not
   allowed to connect to this MariaDB server`. A leitura intuitiva do 1130 é "host
   bloqueado", e está errada aqui: é também o que o servidor devolve quando **não
   conseguiu carregar o cache de ACL**, porque não pôde ler as grant tables. Seis
   grant tables (Aria) do banco `mysql` estavam corrompidas. A única confirmada por
   nome é **`tables_priv`** — ver a correção do `aria_chk` abaixo. **A lista completa
   das seis não foi transcrita na hora e está perdida**; fica registrado que eram
   seis, não quais.
4. **O 1130 é o que criou o backup falso.** O `mysqldump --result-file=…` cria o
   arquivo de destino **antes** de saber se a conexão funciona, então
   `backup-mesa-2026-08-22.sql` nasceu com **0 bytes** e ficou dois dias na raiz do
   repositório. O aprendizado de método está na §6 do roadmap ("Backup não verificado
   é pior que nenhum"), não repetido aqui.
5. **22/08 — reconstrução.** Datadir novo criado com `mysql_install_db`,
   `innodb_force_recovery` **desligado**, e o banco `jda` reimportado de dump
   verificado: 14/14 tabelas conferidas e `CHECK TABLE` OK nas 14. Foi sobre este
   estado que o C8 rodou no mesmo dia.
6. **O que se perdeu, e é definitivo:** o usuário `pma` e o banco `phpmyadmin` — o
   *configuration storage* do phpMyAdmin. Datadir novo significa que só volta o que
   for reimportado, e desses não havia dump. Na prática se foram o histórico de
   queries, os bookmarks, os metadados de relação e as coordenadas do Designer.
   **Não afeta o plugin nem o `jda`.** Recriar o `pma` só é necessário se o
   configuration storage voltar a ser usado — o phpMyAdmin funciona sem ele, com um
   aviso na interface.

### Correção — `aria_chk --check` **não** é read-only

Durante o incidente foi afirmado que rodar `aria_chk --check` era seguro por ser
somente leitura. **Está errado.** Quando a checagem termina sem encontrar problema, o
`aria_chk` **grava o flag de "tabela checada" no header** do arquivo. Um comando
apresentado como diagnóstico escreve, portanto, na tabela que se está tentando
diagnosticar. **Aconteceu em `tables_priv`.**

A opção que suprime exatamente essa escrita existe por esse motivo:
`-T, --read-only` — *"don't mark table as checked"*. Era ela que deveria ter sido
usada.

**Consequência de método, e é a razão de a correção estar aqui e não só numa nota:**
em perícia de corrupção, `aria_chk --check` sem `-T` **altera a evidência**. É a mesma
classe de erro do aprendizado de 19/08 na §6 do roadmap ("em perda de dado, medir antes
de restaurar"), um nível abaixo — lá o reparo destruiu a medição; aqui é a **própria
ferramenta de medição** que mexe no que mede. Copiar o arquivo antes, ou passar `-T`.

### Regras de ambiente que ficam

- **`innodb_force_recovery` é medida de emergência, não configuração.** Ligar exige
  registrar quando e por quê; desligar é parte do reparo, não um passo opcional. Dois
  meses ligado em silêncio é o que torna o C10 indeterminável — não a falta de leitura
  de código, que foi exaustiva.
- **Antes de mexer em banco, dump verificado e fora do repositório.** Verificado =
  tamanho maior que zero **e** última linha `-- Dump completed on …`. Destino:
  `E:\db-backups\`, nunca `E:\news-game\` (§7 do `CLAUDE.md`).
- **Corrupção de grant table se apresenta como 1130**, não como erro de permissão. Não
  gastar tempo procurando bloqueio de host antes de checar as tabelas do banco `mysql`.

**Ver também:** **C10** (o mecanismo candidato que este incidente ofereceu), **C8**
(validado sobre o banco reconstruído) e §6 do roadmap (backup verificado; medir antes
de restaurar).

---

## FLAG 009 — Contraste: dois tokens de alpha baixo que não alcançam o mínimo AA

**Status:** 🔵 Registrado em 2026-09-03, **não corrigido**. Achado durante a remedição
de contraste da troca de arte da skin `pixel` (as três cenas de dia).

**Por que isto é FLAG e não item de bloco:** são **dois valores no arquivo base**
(`public/css/newsgame-game.css`), consumidos pelas **seis** skins. Não é defeito de uma
skin nem regressão de uma troca de asset — é propriedade da camada de tokens, e some
de vista se ficar anotado dentro do relato de uma skin.

### O que foi medido

Metodologia: percentil 5 (pior 5%) da região central da imagem de fundo — onde o
conteúdo do jogo assenta com `background-size: cover` + `background-position: center` —,
blend em espaço sRGB e luminância relativa WCAG. Mesma sonda usada em 2026-09-02 e
2026-09-03, descartada depois de cada uso.

**1. `--ng-muted` — `rgba(var(--ng-on-rgb), 0.55)` (`newsgame-game.css:61`)**

Veste `.ng-screen p` (subtítulo da capa, 16px) e `.ng-progress-label`
(**11,2px** na skin `pixel`). Os dois ficam **fora** de qualquer caixa: a barra de
progresso é irmã de `.ng-stage` (`newsgame-game.js:557-558`), não filha. Ou seja,
assentam direto sobre a imagem de fundo com o véu.

| Série de fundo | véu 0,42 | mínimo AA (texto normal) |
|---|---|---|
| noite/crepúsculo — **em produção de 29/08 a 03/09** | 3,39 – 4,01 | 4,5 ❌ |
| `dia-*` — **em produção desde 03/09** | 3,15 – 3,31 | 4,5 ❌ |
| série `fundo-*` (descartada em 03/09) | 2,33 – 2,37 | 4,5 ❌ |

**A falha é PRÉ-EXISTENTE e independe da arte.** Já não passava com as noturnas. A
troca de 03/09 piora o número (de ~3,7 para ~3,2 de média), não cria o problema.

Elevar o véu **não é a saída**: para levar `--ng-muted` a 4,5 nos três fundos de dia
seria preciso 0,68 — medido: 0,62 dá 4,61 / 4,48 / 4,46, e **falha por 0,02 e 0,04**
em dois dos três. E 0,68 escurece o dia até desfazer a razão da troca.

**2. `--ng-progress-track` — `rgba(var(--ng-on-rgb), 0.12)` (`newsgame-game.css:67`)**

Trilha vazia da barra de progresso. Elemento não-textual, portanto SC 1.4.11, mínimo
**3:1**.

| Véu | contraste da trilha |
|---|---|
| 0,42 | 1,32 – 1,34 ❌ |
| 0,52 | 1,38 – 1,39 ❌ |
| 0,58 | 1,40 – 1,42 ❌ |
| 0,62 | 1,40 – 1,43 ❌ |
| 0,68 | 1,38 – 1,43 ❌ |

⚠️ **É MATEMATICAMENTE INALCANÇÁVEL por véu.** Branco a 12% de opacidade sobre
qualquer fundo não chega a 3:1 — o teto medido é 1,43, e ele não cresce com o véu
porque escurecer o fundo escurece a trilha na mesma proporção. Nenhum valor de
`--ng-overlay` resolve, hoje ou nunca. As noturnas em produção mediam 1,35 – 1,39: mesma
falha, mesma ordem.

O **preenchimento** da barra (`.ng-progress-fill`, `--ng-accent`) mede 5,00 – 5,41 e
passa. Quem indica o estado, portanto, está legível; o que falha é a calha.

### Recomendação — revisar o alpha dos dois tokens no arquivo BASE

Corrigir na origem, e não compensar por escurecimento de fundo. Duas linhas:

- `--ng-muted`: 0,55 → um valor que cumpra 4,5:1 sobre `--ng-bg` **e** sobre fundo com
  imagem. Não medido ainda qual é.
- `--ng-track`: 0,12 → um valor que cumpra 3:1. Alternativa possível: dar borda de 1px
  à trilha em vez de subir o preenchimento dela, que preserva a leitura de "calha vazia".

⚠️ **Afeta as SEIS skins e muda pixel na `default`.** É decisão, não tarefa, e por isso
não foi tomada aqui. Também não é caso de sobrescrever na skin: os dois são tokens
DERIVADOS, e declarar token derivado dentro de skin é o que a regra 1 do contrato de
skins proíbe — quem tentar isso re-deriva à mão o defeito que a fase 1 do H2 gastou
três commits para remover.

**Ver também:** **A5** (a outra dívida de superfície do jogador que é decisão e não
tarefa) e o cabeçalho de `skin-pixel.css`, onde ficam os contrastes medidos por
componente daquela skin.

---

## FLAG 010 — Duas casas de CSS na mesma tela do admin

**Status:** 🔵 Registrado em 2026-09-04, **aceito por ora, não corrigido**. Nasceu com o
seletor visual de skins, que trouxe o primeiro pipeline de CSS do admin.

### O estado, medido

| Casa | Onde | Tamanho | Quem veste |
|---|---|---|---|
| **Enfileirada** | `admin/css/newsgame-admin-skins.css`, por `Newsgame_Admin_Instances::enqueue_form_styles()` | 110 linhas | só o seletor de skins (`.ng-skin-*`) |
| **Inline** | `<style>` no topo de `admin/views/page-instance-form.php:85-190` | 106 linhas | form-table, builder de personagens, barra sticky e a **Mesa Editorial inteira** |

As duas vestem a **mesma tela**. `page-settings.php` e `page-instances.php` não têm bloco
de estilo — conferido por `grep`, e é o que mantém o item restrito a uma tela.

⚠️ **O `wp_enqueue_style` de `enqueue_form_styles()` é o PRIMEIRO do admin em todo o
plugin.** O buraco era exatamente o mesmo que o A2 encontrou no JS em 08/08 (nenhum
`admin_enqueue_scripts` existia), e pela mesma razão histórica: todo estilo do painel
nasceu dentro da view.

### Por que o inline não foi migrado no mesmo passo

1. **Precedente do A3, aplicado literalmente.** Mover 106 linhas de CSS no mesmo commit
   que introduz um componente novo tornaria impossível saber, se a tela quebrasse, qual
   das duas mudanças quebrou. É o argumento que deixou o `buildRow` sem refatoração
   enquanto ele mudava de arquivo em 28/08, e o que deixou `doSave()` duplicando payload
   em 29/08.
2. **As 106 linhas não têm cobertura própria.** Elas vestem a Mesa Editorial, que é a
   maior massa de UI do admin — abas, accordion, tabela de edição, paginação. O teste de
   uma migração dessas é comparação visual da tela inteira, e é trabalho de passo
   próprio, não de passageiro.
3. **CSP não é motivo aqui, e é importante dizer por quê.** O inline exige
   `'unsafe-inline'` em `style-src` — mas na superfície **AUTENTICADA**, que é outra
   coisa da promessa do A1. Migrar as 106 linhas **não** desbloqueia o **B2** nem mexe no
   **A5**: os dois falam da superfície **pública**. Mesma classe de defeito de redação
   que o A5 registra para `style-src` e que a nota de precisão do A3 registra para o
   `<script id="…-js-extra">` do `wp_localize_script` — **dizer qual das duas superfícies
   está sendo descrita**.

### O custo, escrito antes de doer

- **Regra de CSS do admin passa a ter dois lugares possíveis.** Quem procurar `.ng-tab`,
  `.ng-qrow` ou `.ng-char-row` no arquivo novo não acha; quem procurar `.ng-skin-card` no
  inline também não.
- ⚠️ **A CASCATA FAVORECE O INLINE, ao contrário do que a intuição diz.** O arquivo
  enfileirado sai no `<head>`; o `<style>` da view sai no `<body>`, depois. Em empate de
  especificidade, **o inline vence** — inclusive contra uma regra escrita depois no
  arquivo novo. Se um seletor for declarado nas duas casas, quem manda é a view.
- **Ordem de chegada difere.** O CSS do seletor chega no `<head>`, junto com o resto do
  admin; o do bloco antigo chega com o corpo. É a mesma forma do FOUC que o `8428210`
  consertou na superfície do jogador, e aqui **não** foi tratada como defeito: a tela é
  autenticada, não é o produto, e o estado sem CSS dela nunca foi confundido com o final.

### A regra que fica

**CSS novo do admin nasce em arquivo enfileirado em `admin/css/`, um por bloco** — mesmo
critério dos cinco arquivos de JS do A3 (`newsgame-admin-skins.css` é do seletor de skins
e de nada mais; um `newsgame-admin.css` guarda-tudo carregaria regra de componente em
telas sem o componente).

**O inline não recebe acréscimo.** Quem precisar de regra nova para a Mesa Editorial cria
`admin/css/newsgame-admin-review.css` e enfileira com o mesmo método, em vez de somar
linha ao bloco de 106.

### Como sair, quando for a hora

Um passo por bloco, **sem nenhuma outra mudança junto**: `newsgame-admin-form.css`
(form-table, personagens, sticky) e `newsgame-admin-review.css` (Mesa Editorial). O
critério de pronto é comparação visual das duas telas com as três abas abertas, e a
armadilha a olhar é a inversão de cascata descrita acima — regra que dependia de vencer
por estar no `<body>` passa a perder ao virar arquivo no `<head>`.

**Ver também:** **A3** / **FLAG 007** (o mesmo movimento, feito para o JS: 1.254 linhas em
cinco arquivos, e a nota de precisão sobre as duas superfícies) e **A5** (a dívida de
`style-src`, que é da superfície pública e **não** deste item).

---

## FLAG 011 — Ausência de criptografia na chave de API armazenada

**Status:** 🔴 Aberto — **decisão de arquitetura, não implementado**. Registrado em
2026-09-04, no diagnóstico do H1.

**Problema:**
As duas chaves que o plugin persiste ficam em `wp_options` em **texto claro**, com
`autoload = auto` — ou seja, carregadas na memória de todo request do WordPress, não
só das telas que precisam delas. Medido em runtime em 2026-09-04, no banco local:

| `option_name` | tamanho do valor | autoload |
|---|---|---|
| `newsgame_gemini_api_key` | 53 chars | **auto** |
| `newsgame_backend_api_key` | 43 chars | **auto** |

**Não existe nenhuma primitiva de criptografia no plugin.** Verificado por `grep` de
dez termos em todos os `.php` — `openssl_encrypt`, `openssl_decrypt`,
`sodium_crypto`, `wp_salt`, `AUTH_KEY`, `base64_encode`, `base64_decode`,
`hash_hmac`, `encrypt`, `decrypt`: **zero ocorrências**. As options são gravadas por
`register_setting` com `sanitize_callback => 'sanitize_text_field'`, que sanitiza e
não cifra.

⚠️ **A tela de configurações AFIRMAVA O CONTRÁRIO até esta sessão.** O texto de
`page-settings.php` dizia *"Armazenada criptografada no banco WordPress — nunca em
arquivo."* — falso nas duas metades. Corrigido em 2026-09-04 para "Armazenada nas
configurações do WordPress, sem criptografia. Restrito a administradores com
permissão de gerenciar opções." **A correção é de texto: o comportamento não mudou**,
e é por isso que esta FLAG existe.

Duas superfícies de exposição, medidas e distintas da questão do repouso:
- **O valor sai no HTML da própria tela.** `page-settings.php:31` imprime
  `value="<?php echo esc_attr( get_option( 'newsgame_gemini_api_key', '' ) ); ?>"`. O
  input é `type="password"`, o que esconde da vista e **não** do `Ctrl+U`. Exige
  `manage_options` para chegar lá.
- **O backend guarda a chave em arquivo.** `backend-python/.env` contém
  `GEMINI_API_KEY` e `API_SECRET_TOKEN` em texto claro no disco (conferidos por nome;
  valores não lidos). `config.py:35` declara `{"env_file": ".env"}`.

**Onde está:**
- `news-game-plugin/includes/class-newsgame-settings.php:71-83` — registro das options
- `news-game-plugin/admin/views/page-settings.php` — a tela (e o `value` da linha 31)
- `backend-python/.env` — a cópia em arquivo, no lado do backend

**Decisão pendente — três saídas, nenhuma escolhida.** Mesma estrutura do **C14**:
1. **Criptografar em repouso** derivando chave de `wp_salt()`/`AUTH_KEY`. Custo real:
   `AUTH_KEY` vive no `wp-config.php`, então isto move o segredo em vez de eliminá-lo,
   e rotacionar salt (prática recomendada de segurança do WP) passa a **invalidar a
   chave gravada** — precisa de caminho de recuperação, senão a próxima rotação quebra
   a geração em silêncio.
2. **Cofre externo** (variável de ambiente do host, secrets manager). Tira o segredo do
   banco, e tira do alcance do editor — o que muda o modelo de operação do plugin:
   hoje o editor configura sozinho pelo painel, sem tocar em servidor.
3. **Aceitar e documentar formalmente.** É o estado de hoje, com a diferença de estar
   escrito: chave em texto claro, acessível a quem tem `manage_options` ou acesso ao
   banco. Para um plugin open source instalado por redações pequenas, pode ser a
   escolha certa — mas precisa estar no guia de portabilidade (**B1**), não só aqui.

**Ver também:** **FLAG 003**, que fecha uma questão **diferente** e não esta — lá o
tema é o segredo não chegar ao **browser do jogador** (superfície pública, proxy PHP +
`X-Api-Key`), e essa promessa segue de pé. Esta FLAG é sobre **repouso** e sobre a
superfície **autenticada**. Não somar as duas.

---

## FLAG 012 — SDK do Gemini descontinuado (`google-generativeai` → `google-genai`)

**Status:** 🔴 Aberto — **não é urgente por quebra; é dívida com prazo de validade.**
Registrado em 2026-09-04, no diagnóstico do H1.

**Problema:**
`langchain-google-genai 2.0.1` depende de `google-generativeai 0.8.6`, cujo suporte
**terminou**. Confirmado em runtime em 2026-09-04, por `import` direto no `.venv` do
backend — o aviso é emitido pelo próprio pacote:

```
FutureWarning:
All support for the `google.generativeai` package has ended. It will no longer be
receiving updates or bug fixes. Please switch to the `google.genai` package as soon
as possible.
  langchain_google_genai/chat_models.py:52
```

Versões medidas no ambiente (`pip show`): `langchain-google-genai 2.0.1`
(`Requires: google-generativeai, langchain-core, pydantic`) e `google-generativeai
0.8.6`. O pacote novo, `google-genai`, **não está instalado**.

Nada quebra hoje: a biblioteca funciona, e o aviso é `FutureWarning`. O que vence é o
prazo — pacote sem correção de bug inclui **sem correção de segurança**, e a data de
corte não está sob controle do projeto.

**Onde está:**
- `backend-python/requirements.txt:6` — `langchain-google-genai==2.0.1`
- Alcança, por consequência, os dois pontos de instanciação: `main.py:222` e
  `generator.py:426`

**Nota — é migração de superfície de API, não drop-in.** Trocar o pino não basta: o
`google.genai` reorganiza cliente, configuração e tipos, e o `langchain-google-genai`
precisa estar numa versão que já o use. Antes de fechar esta FLAG é obrigatório
**revalidar a FLAG 001** (validação Pydantic + auto-reparo em 2 tentativas) contra o
SDK novo: o auto-reparo depende do formato do texto devolvido e de o `.content` vir
como string, e nenhuma das duas coisas é garantida por contrato entre SDKs.

⚠️ **Isto muda a conta do H1.** A migração é necessária **mesmo que o projeto decida
ficar só no Gemini** — não é custo da abstração de provedor, é custo de manutenção que
já está no caixa. Ver o bloco de correção de 2026-09-04 na §4 (D1) do
`ROADMAP_OPEN_SOURCE.md`.

---

## Arquivo — FLAGS do projeto de origem (E:\jogo-da-amazonia)

> As FLAGS abaixo documentam dívida técnica do **Jogo da Amazônia original** em `E:\jogo-da-amazonia`.
> **Não se aplicam ao news-game.** Preservadas como referência histórica — especialmente as decisões
> de CSS (position:fixed, mobile breakpoints, jQuery vs CSS transitions) que podem informar escolhas
> futuras no plugin universal.
> Para reimplementar qualquer uma dessas decisões no news-game, criar nova FLAG na série 00X acima.

---

### FLAG 1 — Detecção Dinâmica de Ambiente no wp-config.php

**Status:** ✅ Atualizado em 2026-04-17 — eliminada a "chave seletora manual".

**Problema original:**
O Ngrok força HTTPS no túnel externo mas o WordPress servia recursos via HTTP.
Além disso, havia dois blocos `define(WP_HOME/WP_SITEURL)` que precisavam ser comentados/descomentados manualmente para alternar entre localhost e Ngrok.

**Solução implementada:**
Bloco dinâmico no `wp-config.php` que detecta o `HTTP_HOST` por requisição:

```php
// Passo 1: HTTPS do Ngrok
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
    $_SERVER['HTTPS'] = 'on';
}

// Passo 2: URL dinâmica
if ( isset( $_SERVER['HTTP_HOST'] ) && false !== strpos( $_SERVER['HTTP_HOST'], 'ngrok' ) ) {
    define( 'WP_HOME',    'https://' . $_SERVER['HTTP_HOST'] );
    define( 'WP_SITEURL', 'https://' . $_SERVER['HTTP_HOST'] );
} else {
    define( 'WP_HOME',    'http://localhost/jda' );
    define( 'WP_SITEURL', 'http://localhost/jda' );
}
```

**Risco remanescente:**
O bloco de HTTPS (Passo 1) pode causar **redirect loop** em servidor de produção com SSL nativo (Pantheon.io, WP Engine). Remover antes do deploy.

**Onde está:** `C:/xampp/htdocs/jda/wp-config.php` (ambiente local JDA)

---

### FLAG 2 — position: fixed + z-index alto (CSS vs. Tema WordPress)

**Problema original:**
O tema WordPress ativo (Twenty Twenty-Five) impõe um container de **840px de largura** à página. O jogo precisava ocupar 100% da viewport (1200×680px em desktop, 100vw×100vh no mobile). O Claude tentou centralizar/expandir via CSS normal 5 vezes e falhou porque o tema tem prioridade de cascata.

**Solução implementada:**
O wrapper principal do jogo usa `position: fixed` com cobertura total da viewport:

```css
#jda-shell {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    z-index: 999999;
    /* ... */
}
```

**Risco:**
O jogo "flutua" sobre todo o conteúdo do WordPress. O menu de navegação do tema fica oculto atrás do jogo e o scroll da página-mãe fica bloqueado enquanto o jogo está ativo.

**Ação necessária no futuro:**
Para o plugin open source, substituir por uma abordagem de template exclusivo (sem tema) — o plugin já tem o mecanismo de `template_include` para isso.

**Onde está:** `E:/jogo-da-amazonia/jogo-da-amazonia-plugin/assets/jda.css`

---

### FLAG 3 — Cache-Bust com time() no carregamento de assets

**Problema original:**
Durante o desenvolvimento intensivo, o navegador (especialmente mobile Samsung S26 via Ngrok) cacheava versões antigas do CSS e JS.

**Solução implementada:**
No `veredito.php`, os assets são enfileirados com `time()` como versão:

```php
wp_enqueue_style('jda-skin', plugin_dir_url(__FILE__) . 'assets/theme-amazonia.css', [], time());
wp_enqueue_script('jda-js', plugin_dir_url(__FILE__) . 'assets/jda.js', ['jquery'], time(), true);
```

**Risco:**
Em produção, `time()` garante que o navegador **nunca** use cache — cada pageload baixa CSS e JS do zero.

**Ação necessária antes do deploy definitivo:**
Substituir `time()` por número de versão semântico fixo (ex: `'1.0.3'`) ou hash do arquivo.

**Onde está:** `E:/jogo-da-amazonia/jogo-da-amazonia-plugin/veredito.php`

---

### FLAG 4 — CSS Híbrido Mobile/Desktop (Scroll vs. Fixed)

**Status:** ✅ Resolvido em 2026-05-11 — faixa de tablet 768–1024px estabilizada como `Stable`.

**Problema original:**
O `position: fixed` do FLAG 2 resolvia o desktop mas travava o scroll no mobile.

**Resolução implementada (2026-05-11):**

1. Removidos os blocos de frame portrait (`min-width:600 and max-width:1024`).
2. Unificado o breakpoint estrutural de `(max-width: 768px)` para `(max-width: 1024px)` em 9 regras críticas em `jda-mobile.css`.
3. Adicionado bloco `(min-width: 768px) and (max-width: 1024px)` com tipografia tablet calibrada.
4. Adicionado detecção `isTouchDevice` em `jogo-amazonia.js` que aplica `body.jda-touch` ao DOM.
5. Meta viewport injetado via `wp_head` prioridade 0 — sem `maximum-scale` (decisão de acessibilidade WCAG).

**Risco remanescente:**
Híbridos como Surface com mouse + touch entram em `body.jda-touch` mas mantêm layout desktop. Ajustar com `pointer: fine` via `matchMedia` se necessário.

**Onde está:**
- `E:/jogo-da-amazonia/jogo-amazonia-plugin/assets/jda-mobile.css`
- `E:/jogo-da-amazonia/jogo-amazonia-plugin/assets/js/jogo-amazonia.js`

---

### FLAG 5 — Transições jQuery vs. CSS (Dívida de Animação)

**Contexto:**
O `jda.js` usa jQuery (`.fadeIn()`, `.fadeOut()`, `.hide()`, `.slideDown()`) para todas as transições. O código tem comentário interno reconhecendo o problema mas sem resolver completamente.

**Risco documentado:**
`.hide()` + `.fadeIn()` no `loadDilema()` causam "salto" de layout no desktop. Em dispositivos low-end com Samsung Internet/WebKit antigo, jQuery animations consomem mais memória que CSS transitions nativas.

**Solução definitiva (pendente):**
Substituir por CSS puro: `switchState()` com `.addClass('active')` + `@keyframes`; `loadDilema()` com `opacity: 0 → 1` via classe CSS sem alterar `display`.

**Onde está:** `E:/jogo-da-amazonia/jogo-da-amazonia-plugin/assets/jda.js`

---

### FLAG 6 — Sistema de Cores Off-White (Editorial Minimalista)

**O que mudou em 29/03/2026:**
O design do `#jda-state-game` migrou de glassmorphism escuro para o padrão **Editorial Minimalista**.

| Token | Valor | Uso |
|---|---|---|
| Creme Papel | `#FDFCF5` | Fundo do game state, dilema card, área de opções |
| Azul Ardósia | `#2C3E50` | Texto dos dilemas e alternativas |
| Verde Musgo | `#1E4620` | Botões, bordas, headers de contraste |
| Verde Médio | `#2d6a4f` | Hover dos botões |

**Risco de regressão:**
A `theme-amazonia.css` tem seção de **blindagem** (`!important`) no final. Novas regras adicionadas acima sem `!important` são sobrescritas silenciosamente.

**Onde está:** `E:/jogo-da-amazonia/jogo-da-amazonia-plugin/assets/css/skins/theme-amazonia.css`

---

### FLAG 7 — Regra de Ouro contra Redundância CSS

**Problema documentado:**
Seletores duplicados se acumularam silenciosamente. A regra mais antiga sobrescrevia a nova sem gerar erro visível.

**Regra estabelecida:**
> Antes de aplicar qualquer novo estilo a um seletor existente, inspecionar o arquivo CSS completo e remover ou consolidar todas as ocorrências anteriores. Nunca empilhar regras — resolver o conflito na fonte.

**Onde já causou problema:** Consolidação do `style_v3.css` em 04/04/2026.

---

### FLAG 8 — Escalabilidade de Logo: Width Fixa, Height Auto

**Regra estabelecida:**
> Logos em formato retrato devem sempre ser controlados por **`width` fixo + `height: auto`**. A altura é consequência da proporção intrínseca da imagem — nunca deve ser definida explicitamente.

```css
.logo-img {
  width: 100%;
  max-width: 300px;
  height: auto;        /* NUNCA height: Xpx */
  object-fit: contain;
}
```

---

### Registro de Tentativas Fracassadas — JDA (Para Não Repetir)

| Tentativa | O que foi tentado | Por que falhou |
|---|---|---|
| Centralizar jogo via `margin: auto` | CSS padrão de centralização | O tema WP sobrescrevia com seu container de 840px |
| `width: 100%` no wrapper | Herda a largura do container do tema | Resultado: 840px mesmo assim |
| Desativar tema via `remove_action` | Remover header/footer do tema via PHP | Causava erros de JS em scripts dependentes do tema |
| Tentar usar template via interface WP | Selecionar template pela UI do WordPress | WP local não reconhecia os templates do plugin, dava 404 |
| Glassmorphism sobre fundo escuro sem fotos | Caixas translúcidas + backdrop-filter | Sem as fotos ativas, o efeito criava ruído visual sem propósito |
