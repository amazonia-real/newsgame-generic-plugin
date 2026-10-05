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
causa do **passo 4** (exemplo de CSP no README). A dependência do **A5** caiu em
**2026-09-25**: a superfície pública passou a funcionar sem `'unsafe-inline'` também
em `style-src`, e o passo 4 está desbloqueado.
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
| Estilos | ~70 atributos `style="` | Ignorar — `style-src` não vale o esforço. *Os ~70 são plugin inteiro; na superfície **pública** são 5 desde 29/08 (eram 6), em 4 grupos — ver a ressalva do passo 4 e o A5.* **Atualizado em 2026-09-25: na superfície pública são ZERO** — os 4 grupos foram fechados pelo A5. O admin segue com os seus, e "ignorar" continua valendo para ele |

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
   pública não exige mais `'unsafe-inline'` **em `script-src`** (desde 08/08)
   **nem em `style-src`** (desde 25/09, A5).

   > ✅ **RESSALVA ENCERRADA em 2026-09-25 — `style-src` resolvido na superfície
   > pública.** Os 4 grupos da tabela abaixo foram fechados: 3 por classe no CSS e
   > a barra de progresso por CSSOM, exatamente os dois caminhos que esta ressalva
   > descreveu. O `grep` de auditoria devolve **zero** `style=` em
   > `newsgame-game.js`, `class-newsgame-shortcodes.php` e `news-game.php`.
   > ✅ *Validado em runtime em 25/09: partida completa da instância 2 em Chrome
   > headless, com `Content-Security-Policy: script-src 'self'; style-src 'self'`
   > injetada no documento. A barra aplicou a largura em todas as telas, os botões
   > do veredito ficaram empilhados e centralizados, e nenhuma das 28 violações
   > registradas veio do plugin — todas são de `<style>`/`<script>` do núcleo do
   > WordPress e de atributos do tema Twenty Twenty-Five.* **O que o passo 4
   > precisa dizer ao redigir o exemplo:** a promessa é do **plugin** (o tema do
   > site pode exigir `'unsafe-inline'` por conta própria) e da superfície
   > **pública** (o admin não está coberto — ver a precisão do passo 3, acima).
   > **O texto da ressalva, daqui até o fim do bloco, é de 21/08 e descreve o
   > estado de então**; fica como histórico, com a tabela já atualizada.
   >
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
   > | `newsgame-game.js:307` | `color:#f88` — mensagem de erro | Sim — ✔ **removido em 2026-09-25**: classe `.ng-empty-msg` (`newsgame-game.css:810`) e token `--ng-error-text` (`:116`) |
   > | `newsgame-game.js:716`, `:717` | `display:block;margin:0 auto` — layout dos botões de compartilhar e reiniciar | Sim — ✔ **removido em 2026-09-25**: `.ng-btn-share`/`.ng-btn-restart` sob `.ng-game-wrap` (`newsgame-game.css:797`) |
   > | `class-newsgame-shortcodes.php:237` | `font-family:monospace` — erro de instância sem dilemas aprovados | Sim — ✔ **removido em 2026-09-25**: modificadora `.newsgame-error-vazio` (`newsgame-game.css:819`) |
   > | `newsgame-game.js:67` | `width:N%` — barra de progresso | ✔ **removido em 2026-09-25: convertido para CSSOM** (`aplicarProgresso()`, `:78`, `el.style.width` depois da inserção) |
   >
   > *Referências de linha reconferidas em 2026-09-25, depois da edição: apontam
   > para onde cada grupo está hoje, já sem o atributo. As que a tabela trazia
   > (`:226`, `:406`, `:407`, `:269`, `:320` e `shortcodes.php:38`) eram de antes
   > de 21/08 e não batiam nem com a lista `57,287,633,634`/`:94` do parágrafo de
   > cima, que também já estava defasada.*
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
   > *(Nota de 25/09: a decisão foi revertida para a superfície pública — ver a
   > nota em "O que NÃO fazer". O parágrafo abaixo é o raciocínio de 21/08.)*
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
  **Revertida em 2026-09-25 para a superfície pública; mantida para o admin.** Na
  superfície pública o custo se mostrou pequeno (5 atributos, fechados num passo),
  e o ganho é o argumento de adoção do passo 4, não segurança. O "retorno de
  segurança marginal" continua correto. O A5 fechou os 4 grupos. No **admin**, que
  tem dezenas de atributos e o dado inline do `wp_localize_script`, esta decisão
  fica de pé.

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

⚠️ **Nota factual acrescentada em 2026-09-16 — o `.env` guarda uma chave MORTA, e as
duas cópias DIVERGIRAM.** *(Não corresponde mais ao estado real desde, no máximo,
25/09 — ver a atualização logo depois desta nota. O texto de 16/09 fica preservado.)*
Medido nesta data, por comprimento e `md5[0:8]`, sem leitura de valor:

| onde | comprimento | `md5[0:8]` | estado |
|---|---|---|---|
| painel (`newsgame_gemini_api_key`) | 53 | `eee6baac` | **viva** — `ListModels` 200 |
| `backend-python/.env` (`GEMINI_API_KEY`) | 39 | `dedc9c80` | **bloqueada** — `403 API_KEY_SERVICE_BLOCKED`, medido no mesmo dia |

Em 15/09 as duas eram **idênticas** (o `.env` foi reposto com o valor do painel). Em
16/09 o editor rotacionou a chave **só no painel**, e o `.env` ficou com a anterior —
que, medida horas antes, já vinha sendo **recusada pelo Google**.

**Por que isto NÃO quebra nada hoje, e por que é risco:** o plugin manda a chave em
**header**, e a cascata do `_resolve_api_key()` é `header > body > Settings (.env)` — o
header vence sempre, então o `.env` é um terceiro degrau que nunca é alcançado pelo
caminho normal. O problema é o modo de falha: **se o header algum dia não chegar** (bug
no `provider_headers()`, option apagada por um save malformado — a família do **C13** —,
ou chamador novo que esqueça o header), a cascata cai **silenciosamente** para uma chave
**revogada**, e o sintoma vira "o provedor recusou" em vez de "o plugin não mandou a
chave". Foi exatamente essa a assimetria que a fase 0 do **H1** corrigiu na outra
direção.

**Não é a mesma questão que esta FLAG trata** (repouso e criptografia) — é **higiene de
credencial**: segredo revogado deixado em arquivo. Fica registrado aqui porque é onde o
`.env` como cópia da chave já está documentado, e **não** na FLAG 015, que é sobre o
`mysqld` deste ambiente e não tem relação. **Duas saídas, nenhuma escolhida:** sincronizar
o `.env` a cada rotação (mantém o fallback útil e cria um segundo lugar a lembrar), ou
**esvaziá-lo** de propósito e deixar o 401 de `_resolve_api_key()` falar — o que é
possível desde a fase 0, que tornou `gemini_api_key` opcional em `config.py`.

✅ **Atualização de 2026-09-25 — a divergência NÃO existe mais.** Uma comparação feita
dentro do processo, sem impressão de valores, mostrou o `.env` (`GEMINI_API_KEY`) e o
painel (`newsgame_gemini_api_key`) **já com a mesma chave**, de 53 caracteres. O pedido
era substituir a chave do `.env` por uma nova, e ela era justamente a que já estava lá:
a substituição deixou o arquivo byte-idêntico ao backup. A chave autentica: `ListModels`
com ela deu **HTTP 200** (61 modelos, 44 com `generateContent`).
- ⚠️ **A origem e a data dessa correção anterior são DESCONHECIDAS.** Não há registro
  de quem sincronizou o `.env` nem quando, entre 16/09 e 25/09. **A data de modificação
  do arquivo não serve de evidência:** o `.env` foi regravado na sessão de 25/09, com o
  mesmo conteúdo, e o mtime passou a ser o dessa regravação
- **Na prática foi aplicada a primeira das duas saídas de higiene** (sincronizar o
  `.env`), mas **sem decisão registrada**: o risco de uma próxima rotação feita só no
  painel continua o mesmo. A escolha entre sincronizar e esvaziar segue em aberto
- **A decisão de arquitetura desta FLAG não muda:** criptografia em repouso, com as
  três saídas, continua 🔴 aberta e sem escolha. Esta atualização trata só da cópia em
  arquivo

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

**Status:** 🟡 **PARCIALMENTE RESOLVIDA desde 2026-09-05** (commit `cfb337c`) — status
corrigido em 2026-09-25. A mensagem daquele commit já dizia *"Fecha a FLAG 012
parcialmente"*, mas esta flag nunca havia sido atualizada. *Status original,
preservado:* 🔴 Aberto — **não é urgente por quebra; é dívida com prazo de validade.**
Registrado em 2026-09-04, no diagnóstico do H1.

> ✅ **O que JÁ foi resolvido (05/09, `cfb337c`).** `langchain-google-genai` passou de
> **2.0.1 para 2.1.12**, que importa `google.ai.generativelanguage_v1beta` e **dispensa o
> `google-generativeai`**, o pacote descontinuado. Conferido no venv em 25/09:
> `google-generativeai` **ausente**, `google-ai-generativelanguage 0.12.0` presente, e a
> 2.1.12 pede só `langchain-core>=0.3.75`. Por isso **não saiu da linha 0.3** e o teto
> `<1.0` do `0fcc866` ficou intacto. O `FutureWarning` de fim de suporte transcrito
> abaixo **não aparece mais**. O texto de 04/09 abaixo descreve o estado **anterior** e
> fica como histórico: as versões (`2.0.1`, `0.8.6`) e as linhas citadas em "Onde está"
> não valem mais.
>
> 🔴 **O que FALTA: chegar ao `google-genai`**, a biblioteca nova do Google (**ausente**
> no venv). Isso só vem com a `langchain-google-genai` **4.x**, que exige `langchain-core`
> **1.x**. A 4.x também é a que traz `thinking_level`, a revisão amarrada à **FLAG 017**.
> Subir o core de 0.3 para 1.x arrasta hoje **todos os pacotes com teto `<1.0`**,
> conferidos nos metadados instalados em 25/09:
>
> | pacote | versão fixada | exige de `langchain-core` |
> |---|---|---|
> | `langchain-openai` | 0.3.35 (B8) | `<1.0.0,>=0.3.78` |
> | `langchain-anthropic` | 0.3.22 (B9) | `<1.0.0,>=0.3.78` |
> | `langchain-deepseek` | 0.1.4 | `<1.0.0,>=0.3.70` |
> | `langchain-huggingface` | 0.1.0 | `>=0.3.0,<0.4` |
> | `langchain` | 0.3.27 | `<1.0.0,>=0.3.72` |
>
> Os dois primeiros foram fixados **depois** da estimativa de 05/09, pelo mesmo motivo.
> O `langchain` não estava na lista do pedido desta correção: apareceu na conferência dos
> metadados. `langchain-community` (0.3.31) e `langchain-text-splitters` (0.3.11) aceitam
> `<2.0.0` e **não** bloqueiam a subida. **A estimativa de "19 pacotes" do roadmap (§5,
> "Decisão da árvore de dependências") é anterior ao B8/B9 e está desatualizada**, com
> uma nota lá. Nova contagem **não foi medida**. O pré-requisito continua o mesmo:
> **revalidar a FLAG 001** contra o SDK novo.

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

## FLAG 013 — Armadilha de ambiente: `uvicorn --reload` no Windows aceita bind duplo na mesma porta

**Status:** 🔵 Registrado em 2026-09-04. **Não é defeito do produto** — é
propriedade do ambiente de desenvolvimento, e entra aqui pela mesma razão da
FLAG 008: custou tempo duas vezes no mesmo dia e vai custar de novo sem registro.

**Problema:**
No Windows, dois processos conseguem escutar `:8000` **ao mesmo tempo**, sem erro
de "address already in use" — não há `SO_EXCLUSIVEADDRUSE` por padrão. Quando isso
acontece, quem responde é o bind **mais específico**: um processo em
`127.0.0.1:8000` intercepta o tráfego que um processo em `0.0.0.0:8000` também
está escutando. E o plugin fala com `http://127.0.0.1:8000`
(`newsgame_backend_url`), então é o intruso que atende.

Somam-se duas armadilhas de identificação de processo:

1. **O PID que detém o socket é o PAI, não o worker.** Em `uvicorn --reload` o
   processo que aparece no `netstat`/`Get-NetTCPConnection` sobrevive aos reloads;
   só o filho é substituído. **Comparar esse PID antes e depois de editar um
   arquivo não diz nada sobre o código estar carregado.**
2. **Os workers não têm o caminho do projeto na linha de comando.** Eles são
   spawns de `multiprocessing`:
   `python.exe -c "from multiprocessing.spawn import spawn_main; spawn_main(parent_pid=…)"`.
   Portanto `Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like "*news-game*" }`
   **não os alcança**, e um `taskkill` filtrado assim deixa o worker vivo,
   segurando a porta e servindo código velho.

**Onde custou** — os dois primeiros medidos em 2026-09-04, o terceiro em 2026-09-07, o
quarto em 2026-09-14:
- **Episódio 1.** Um processo meu em `127.0.0.1:8000` ficou de pé por engano e
  interceptou quatro `POST /generate-one` disparados do painel pelo editor. Ele
  havia lido o `.env` 13 minutos antes da troca de chave, o que tornou o teste do
  editor inconclusivo — e a investigação de "qual processo atendeu" consumiu uma
  rodada inteira de perícia de timestamps.
- **Episódio 2.** Na validação do passo 5b da fase 0, três rotas devolveram o
  comportamento **antigo** depois de um restart aparentemente bem-sucedido. Causa:
  o worker anterior sobreviveu ao `taskkill` filtrado por linha de comando e
  continuou ganhando o socket.
- **Episódio 3, em 2026-09-07 — e a CAUSA É DIFERENTE das duas de cima, que é o
  motivo de valer registro próprio.** Nos episódios 1 e 2 a flag veio de **restart
  manual**: alguém religou o servidor e esqueceu de tirar `--reload`. Aqui **não
  houve restart** — a máquina **reiniciou sozinha** (erro crítico do Windows,
  boot às 21:55:24) e o uvicorn voltou na **forma padrão do ambiente**, com a flag,
  às 21:59:49. Ninguém decidiu nada: o defeito reentrou por baixo, num momento em
  que o trabalho da sessão estava todo **não commitado**.
  - **Segunda ocorrência DO DIA, e quarta registrada.** A primeira de 07/09 foi o
    restart no início do B6, que está no `CLAUDE.md` e não aqui.
  - **Custo real desta vez: baixo, e só porque foi checado antes de testar.** A
    árvore de 4 processos apareceu na conferência de estado pós-reinício; foi morta
    pelo topo e o servidor religado sem a flag (3 processos, socket do próprio
    servidor) **antes** de qualquer validação. Se a checagem não tivesse acontecido,
    todo o roteiro de re-validação daquele dia teria rodado contra um servidor cuja
    identidade de código não se pode afirmar por PID — que é exatamente o custo dos
    episódios 1 e 2.
  - ⚠️ **NÃO CONFUNDIR COM A FLAG 015, e os dois defeitos são diferentes.** O
    reinício da máquina em si — e o `crash recovery` do InnoDB que ele provocou —
    é assunto da FLAG 015. Esta flag trata só do **efeito colateral no uvicorn**.
    Um evento, dois defeitos, cada um na sua flag.
  - **Regra que se acrescenta:** depois de qualquer boot inesperado, conferir a
    **forma da árvore** antes de confiar em teste — 3 processos com o socket na
    folha, e não 4 com o socket no *reloader*.
- **Episódio 4, em 2026-09-14 — MESMO MECANISMO do Episódio 1, com os PAPÉIS
  INVERTIDOS.** Registrado na checagem que precedeu o B8.
  - ⚠️ **NÃO é mecanismo novo, e a primeira redação deste item dizia que era.** O
    bind concorrente é o **cabeçalho desta flag** e está descrito no "Problema" acima,
    inclusive a regra do bind mais específico; o Episódio 1 é o mesmo evento. A
    afirmação de "mecanismo diferente dos três anteriores" partiu de mim, na conversa,
    **sem eu ter relido a flag** — e ia ser gravada aqui. Corrigida antes de entrar.
    Fica escrita porque é a família do C11: **referência ou afirmação defasada dentro
    de commit**, e desta vez a fonte do erro foi o meu resumo, não o documento.
  - **O que aconteceu:** duas árvores completas coexistiram em `:8000`. Uma minha, de
    **12:09:48**, `--host 127.0.0.1` e **sem** `--reload` (3 processos, socket na
    folha). Uma do editor, de **13:49:48**, `--host 0.0.0.0 --reload` (**4** processos,
    socket no *reloader* `42556`, worker `26908` como filho). `Get-NetTCPConnection`
    devolveu **dois** `OwningProcess` para a mesma porta — `43312` em `127.0.0.1` e
    `42556` em `0.0.0.0`.
  - **A INVERSÃO, que é o que este episódio acrescenta:** no Episódio 1 o intruso
    servia código/`.env` **velho**, e o dano era o teste inconclusivo. Aqui o intruso
    era o servidor **correto** (sem a flag) e o observado era o que **tinha** a flag.
    Consequência: o editor veria o `--reload` disparar no terminal dele a cada edição
    de `generator.py`, e o **plugin continuaria atendido pelo processo que nunca
    recarrega**, porque `newsgame_backend_url` é `http://127.0.0.1:8000` e o bind mais
    específico ganha. Nos episódios 1-3 a lição operacional é *"o PID não serve de
    prova de que o código está carregado"*; aqui é *"o servidor que responde não é o
    que você está observando"* — e o segundo é pior de perceber, porque o sinal que se
    olha (a linha de reload no terminal) é **verdadeiro**, só não se refere ao processo
    que atende.
  - **Custo real: zero.** A árvore dupla apareceu na conferência de estado feita
    **antes** de começar o B8, que é trabalho em `generator.py` e `main.py` — exatamente
    os arquivos cujo recarregamento estaria em questão. As duas foram mortas pelo topo
    (`taskkill /F /T` em `40076` e `33448`), com os **pais identificados antes**: o meu
    (`13336`) já não existia, e o do editor (`4764`) é o `powershell.exe` **interativo**,
    conferido vivo depois. Porta livre provada por **recusa em 2.051 ms** e não por
    timeout, e um só servidor religado sem a flag (3 processos, 1 bind em `127.0.0.1`,
    `--reload` ausente de toda a árvore).
  - ⚠️ **A minha própria conferência da mesma sessão ficou obsoleta DENTRO da sessão.**
    Às 12:10 eu reportei "3 processos, socket na folha" — verdade naquele instante. O
    editor subiu a segunda árvore 1h40 depois. **Nenhum relato de forma de árvore vale
    além do momento da medição**, e a mesma armadilha do `mysqld` de 12:03 deste dia
    (ver FLAG 015) apareceu aqui em outra roupa: **um serviço "conferido no ar" pode ser
    outro processo minutos depois**.
  - **Regra que se acrescenta:** conferir o **número de binds** na porta, e não só a
    forma da árvore. `Get-NetTCPConnection -LocalPort 8000 -State Listen` devolvendo
    **mais de uma linha** é o sintoma, e é o único que aparece **antes** de o teste
    mentir. `/health` respondendo `200` **não** distingue os dois casos — foi o que
    respondeu bem com as duas árvores no ar.

**Onde está:** ambiente de desenvolvimento local (Windows 11 + `.venv` do
`backend-python`). Nenhum arquivo do projeto.

**Regra de ambiente que fica — verificar por COMPORTAMENTO, não por PID:**
1. depois de reiniciar, exercitar um caminho cujo resultado **mude** com o código
   novo. Foi o 502 aparecendo que provou o carregamento do passo 5a, não o PID;
2. no log, `Started server process` deve aparecer **uma vez por startup**. Um
   `Reloading...` sem `Started server process` depois dele significa que o worker
   **não** foi substituído — aconteceu quando o uvicorn subiu como job de
   background cujo shell pai encerrou. `nohup` resolveu;
3. para derrubar, matar o **dono do socket com a árvore** (`taskkill /T`), e
   depois conferir a porta — não confiar em filtro por linha de comando;
4. antes de qualquer teste, conferir que existe **um** listener na porta:
   `Get-NetTCPConnection -LocalPort 8000 -State Listen` deve devolver **uma**
   linha. Duas linhas com `LocalAddress` diferentes é a armadilha instalada.

**Ver também:** **FLAG 008**, que registra a outra regra de ambiente deste projeto
(`innodb_force_recovery` é medida de emergência, não configuração). As duas têm a
mesma forma: estado de ambiente esquecido ligado, que depois contamina a
interpretação de um teste.

### Nota de 2026-10-05 — o executável `uvicorn.exe` bloqueado pelo Smart App Control

Outra armadilha de subir o uvicorn no Windows, medida nesta máquina, só lendo:
- **Smart App Control ligado** — `HKLM:\SYSTEM\CurrentControlSet\Control\CI\Policy`,
  `VerifiedAndReputablePolicyState = 1`
- **Bloqueio registrado** — log `Microsoft-Windows-CodeIntegrity/Operational`, evento
  **3077** em 02/10/2026 às 17:14: o `powershell.exe` tentou carregar
  `C:\Program Files\Python314\Scripts\uvicorn.exe`, que não atendeu ao nível de
  assinatura exigido
- **A saída é `python -m uvicorn`**: quem roda passa a ser o `python.exe`, que é
  assinado. É o que o README recomenda desde 05/10, sem as medições, que ficam aqui

O bloqueio medido foi do `uvicorn.exe` do Python do **sistema**. O do `.venv` é o mesmo
tipo de executável gerado pelo pip, mas **não foi visto bloqueado** — o README diz
"pode ser bloqueado" por isso.

---

## FLAG 014 — Impacto zerado em 24 de 24 dilemas: restrição declarada onde o LLM não lê

**Status:** 🟡 **Parcial.** A **causa** foi corrigida em 2026-09-04 (commit
`a5f4c52`) e validada nos dois provedores. O **dado histórico** segue zerado: os
24 dilemas que já estão no banco não foram regerados.

**Problema:**
Consulta direta ao MySQL em 2026-09-04: **24 de 24** dilemas de
`wp_newsgame_dilemas` — todos gerados pelo Gemini — tinham os quatro valores de
`impacto` (`popularidade` e `recursos` das duas alternativas) em **zero**.

A causa tem duas metades, e a segunda é a interessante:

1. **O exemplo do schema no prompt usava zeros.** `generator.py`, nas três
   alternativas do `USER_PROMPT`: `"impacto": {"popularidade": 0, "recursos": 0}`.
   O modelo copiava literalmente.
2. ⚠️ **A faixa −10/+10 existia APENAS como `description` de campo Pydantic**
   (`models.py:67,71`), e **`description` não chega ao modelo**. O projeto extrai
   JSON de texto e não usa structured output — é a **FLAG 001**. Ou seja: a única
   declaração da restrição vivia num lugar que o LLM nunca lê. `grep` por menção
   à faixa em `generator.py` devolvia **zero**.

**A REGRA GENERALIZÁVEL, que é o que vale guardar:** neste projeto, restrição que
só existe como `Field(description=…)` é **invisível para o modelo**. Enquanto a
FLAG 001 (JSON de texto, sem structured output) valer, toda regra que o LLM
precisa obedecer tem de estar **no texto do prompt**. Vale para qualquer campo
novo, não só para o `impacto`.

**Correção aplicada (`a5f4c52`):** instrução em texto logo depois do schema,
declarando a faixa e proibindo escala 0–100; e os três exemplos passaram a ter
valores diferenciados (`-3/5`, `7/-4`, `2/1`), com forma de trade-off, para que a
cópia literal não volte a zerar.

**Validação:** Gemini **3/3** na faixa e **0/3** zeradas, zero retentativas;
DeepSeek **5/5** na faixa com o prompt corrigido, contra **2/5** sem a instrução.

**Onde está:**
- `backend-python/app/generator.py` — o `USER_PROMPT`, com o comentário de código
  que explica por que os exemplos são diferenciados
- `backend-python/app/models.py:67,71` — a `description` que não chega ao modelo
- `wp_newsgame_dilemas` — as 24 linhas com zeros

**O que sobra, e por que não é urgente:**
- ⚠️ **Os 24 dilemas históricos continuam zerados.** Se o campo voltar a ser
  exibido, é **regeração ou edição manual** — não há migração possível, porque o
  valor nunca existiu para ser recuperado.
- **Hoje nada exibe o campo.** Ele é gravado (`class-newsgame-generator.php:316`)
  e editável na Mesa Editorial (`class-newsgame-review.php:506`), mas os badges de
  impacto saíram da superfície do jogador em 2026-08-29 — `newsgame-game.js:253`
  guarda só o comentário disso. O radar do veredito usa `axis_weights`, que vem de
  `/assign-weights` e **não** deste campo.
- **A cópia literal não desapareceu:** 1 das 3 gerações do Gemini devolveu
  exatamente os valores de exemplo (`-3/5`, `7/-4`). A diferença é que agora a
  cópia produz números na escala e com forma de trade-off, em vez de zeros.
  **Nenhuma validação de faixa detecta isso** — valor copiado é valor válido. Se o
  campo voltar a importar, considerar exemplos propositalmente implausíveis para o
  domínio, de modo que copiar fique visível.
- **`_is_valid_dilema` não valida faixa**, e isso segue como **defesa em
  profundidade pendente**, não como bloqueio. Ele checa título, situação e
  contagem de alternativas. A validação pegaria o caso fora de faixa
  (`15`, `60/80`) que o prompt sozinho não garante — e **não** pegaria os zeros,
  porque zero está dentro da faixa. Por isso o prompt é o conserto principal e o
  código é a rede.

⚠️ **Evidência de que instrução de prompt não é garantia:** o `SYSTEM_PROMPT` já
proíbe cerca de markdown em maiúsculas (*"TERMINANTEMENTE PROIBIDO: aspas triplas
(```json, ```)"*), e o Gemini enviou cerca em **3/3** gerações. O pipeline remove
antes do parse e nada quebrou, mas é a razão pela qual a rede em código continua
valendo a pena.

**Ver também:** **FLAG 001** (a validação Pydantic + auto-reparo que explica por
que não há structured output) e a subseção de POC do DeepSeek na §5 do
`ROADMAP_OPEN_SOURCE.md`, onde o achado apareceu.

---

## FLAG 015 — Incidente de ambiente: `mysqld` abortando por leitura de página que volta com zero bytes

**Status:** 🔴 **ABERTA, sem causa determinada.** Servidor restabelecido manualmente
em 2026-09-07 às 20:25:58 e estável desde então. Registrada no mesmo dia.

**Por que virou FLAG, sendo defeito de ambiente e não do plugin:** pelo mesmo critério
da **FLAG 008** — o episódio bloqueou os testes de um passo de desenvolvimento (o B5),
e produziu um dado que o **C10** carrega desde 24/08 como *mecanismo candidato
inverificável*. Sem registro, a referência do C10 fica órfã e a próxima recorrência
começa do zero.

### Linha do tempo, medida

| hora (07/09) | evento |
|---|---|
| 16:59:05 | MariaDB 10.4.32 sobe em `C:/xampp/mysql/data-novo`, **já com crash recovery** do LSN 23326306 |
| 17:48:44 | **5× `LiveKernelEvent`** no log Application do Windows |
| 18:10:07 | falha de I/O do InnoDB (mensagem abaixo) |
| 18:10:08 | **`mysqld.exe` APPCRASH, código de exceção `0x80000003`** |
| 18:20:52 | sobe de novo, sem intervenção, recuperando **8 páginas do redo log** |
| 18:20:53 | "ready for connections" |
| ≤18:22:50 | **morre outra vez, sem uma linha de log e sem nenhuma query em curso** |
| 20:25:58 | restabelecido manualmente pelo editor |
| 21:55:24 | ⚠️ **a MÁQUINA reinicia sozinha** — erro crítico do Windows, no meio da sessão de trabalho |
| 21:58:55 | `mysqld` sobe com **crash recovery** do LSN 23681504, **sem corrupção**: nenhuma linha de `Tried to read`, `innodb_force_recovery = 0`, e os dados do plugin íntegros (24 dilemas, nenhum aprovado sem peso) |

⚠️ **O REINÍCIO DAS 21:55 É UM SEGUNDO EVENTO DO MESMO DIA, e também sem causa
determinada** — o Windows derrubou a máquina, não uma ação do editor nem um comando de
sessão. Não há evidência de que seja o mesmo defeito das 18:10: ali o `mysqld` abortou
sozinho com a máquina de pé; aqui foi a máquina inteira. Ficam registrados juntos porque
**esta flag é o lugar do que este ambiente faz sozinho**, e separá-los criaria duas
flags para um dia. O que o reinício **não** produziu foi dano: o `crash recovery` das
21:58:55 completou limpo, o que é o **oposto** do que se viu às 18:10.

⚠️ **O EFEITO COLATERAL NO UVICORN NÃO É DESTA FLAG.** O boot trouxe o servidor de
volta com `--reload`, e isso é **defeito diferente**, registrado como **Episódio 3 da
FLAG 013**. Um evento, dois defeitos — não somar os dois aqui.

A mensagem que matou a primeira instância:

```
InnoDB: Retry attempts for reading partial data failed.
InnoDB: Tried to read 16384 bytes at offset 6242304, but was only able to read 0
InnoDB: Operating system error number 203 in a file operation.
InnoDB: File (unknown): 'read' returned OS error 403. Cannot continue operation
```

⚠️ **ESSAS QUATRO LINHAS NÃO ESTÃO NO `mysql_error.log`** — só no log Application do
Windows (provider `MariaDB`). O arquivo salta de `16:59:05 Server socket created` para
`18:20:52 Starting MariaDB`, sem nada no meio: o `abort()` levou o buffer do arquivo
junto. **Consequência de método: em crash de MariaDB no Windows, o `mysql_error.log`
pode ser incompleto, e o Event Viewer é a fonte a consultar** — foi ele que deu a causa
proximal. `Get-WinEvent -FilterHashtable @{LogName='Application'; ProviderName='MariaDB'}`.

⚠️ **RUÍDO CONHECIDO NO `mysql_error.log`, GRAVADO POR MIM EM 2026-09-14 — NÃO É
RECORRÊNCIA DESTA FLAG.** Às **12:07:39 de 14/09** o arquivo recebeu sete linhas
terminando em `[ERROR] Aborting`, precedidas de
`[ERROR] InnoDB: The innodb_system data file 'ibdata1' must be writable` (duas vezes) e
`[ERROR] Unknown/unsupported storage engine: InnoDB`. **Causa conhecida e benigna:
segunda instância de `mysqld` sobre o mesmo datadir.** Na checagem de estado daquele dia
o servidor estava fora, o `xampp-control.exe` subiu o PID 44108 às 12:03:18 — segundos
depois da medição — e o `mysqld` que eu lancei às 12:07:39 (PID 3468) encontrou o
`ibdata1` já travado. **O file locking do InnoDB fez o que devia:** abortou **antes** de
rodar crash recovery e sem tocar o datadir, que é o modo de falha seguro. Dados
conferidos íntegros depois (24 dilemas, 3 instâncias, 20 aprovados, **0** aprovados sem
peso, schema 1.6, `innodb_force_recovery = 0`).
- **Como distinguir das linhas que importam:** este bloco **não** tem `Tried to read`,
  `got signal`, `Assertion` nem APPCRASH no Event Viewer, e o `Aborting` vem **na
  mesma linha de tempo** de um `Starting MariaDB` que nunca chega a
  `Server socket created`. Falha de bind não é falha de I/O
- **Lição de método, um nível abaixo da do `aria_chk` (FLAG 008):** lá a ferramenta de
  medição alterou o que media; aqui a ferramenta de **reparo** contaminou o log que
  serve de evidência a esta flag e à 008. Antes de subir serviço deste ambiente,
  confirmar o dono do socket — "listener respondeu na primeira tentativa" é sinal de
  que **outro** processo já o detinha, não de partida bem-sucedida

### O que foi descartado por medição

- **Espaço em disco** — 334 GB livres em `C:` (60,9%)
- **Memória** — 10,25 GB livres de 31,56 GB
- **SMART e volume** — `Get-PhysicalDisk` devolve `HealthStatus: Healthy` para o único
  disco físico (KINGSTON SNV3S1000G, SSD), e `Get-Volume -DriveLetter C` devolve
  `Healthy`/`OK`. ⚠️ **Isto NÃO absolve a camada de armazenamento:** o SSD não expõe
  `ReadErrorsTotal`/`ReadErrorsUncorrected` (vieram vazios), então o contador que
  importaria não pôde ser lido
- **O trabalho do B5** — a segunda morte é o que exonera: aconteceu **sem nenhum
  acesso ao banco**, e a operação que falhou é **leitura**, não a escrita que estava em
  curso na primeira

### O que sobra, e não é conclusão

- **Este `mysqld` nunca desliga limpo.** Todos os boots registrados desde a
  reconstrução de 22/08 (FLAG 008) começam com *crash recovery*: 27/08, 29/08, 03/09 e
  os dois de 07/09
- O log de **27/08** ainda trazia `InnoDB: Page [page id: space=1, page number=3] log
  sequence number 14046149 is in the future!` — tablespace e redo dessincronizados,
  anterior a este episódio
  - **Quantificado em 14/09, e é maior do que esta linha sugeria: 136 linhas**, todas
    do **mesmo instante** (`2026-08-27 13:36:17`) e de um boot só. São
    `[Warning] InnoDB: Ignoring a doublewrite copy of page [page id: space=N, page
    number=N] with future log sequence number …` seguidas de
    `[ERROR] InnoDB: Your database may be corrupt or you may have copied the InnoDB
    tablespace but not the InnoDB log files`. **O servidor recuperou e seguiu servindo**
    — a sessão do mecanismo de skins rodou naquele dia
  - **Nota de BAIXA PRIORIDADE, não tarefa.** Fica registrada para não se perder: é o
    único evento de dano no `data-novo` **anterior** a 07/09, e é a origem provável do
    "nunca desliga limpo" acima. Não há linha de investigação viva e não se pede uma —
    o critério é o do C10, e a evidência de 27/08 já foi sobrescrita pelos boots
    seguintes
- Os `LiveKernelEvent` de 17:48:44, 22 minutos antes da falha de I/O, **não foram
  investigados** e são a única pista que aponta para fora do MariaDB

### Nota de 2026-09-25 — o reinício das 21:55 tem causa, e ela é o driver Warsaw

⚠️ **NÃO FECHA A FLAG.** Explica **um** dos dois eventos de 07/09 e parte do "nunca
desliga limpo". **Não** explica o abort de I/O das 18:10.

- **O reinício das 21:55:24 de 07/09 foi tela azul do `wsddpp.sys`** — o driver Warsaw,
  módulo de segurança bancária da Topaz OFD. O minidump `090726-17656-01.dmp`, analisado
  com `cdb -z … -c "!analyze -v"`, dá `BUGCHECK_CODE 0x50`, `SYMBOL_NAME wsddpp+0x2d9c`
  e `FAILURE_ID_HASH {735da6ee-5c56-de2d-255c-3359921f37d9}`. As duas telas azuis de
  25/09 (17:34 e ~21:36) dão **o mesmo offset e o mesmo hash**. Detalhe e recomendação
  no bloco "Aberto em 25/09" da §10 do `CLAUDE.md`
- **Pelo menos 3 crash recoveries têm essa tela azul como causa provável**, e não falha
  própria do MySQL: 07/09 às 21:58:55, 25/09 às 17:39:43 e 25/09 às 21:38:34. Os três
  vêm de 2 a 5 minutos depois de um Kernel-Power 41 com bugcheck do `wsddpp`, e o
  `mysql_error.log` não tem nenhuma linha entre o boot anterior e o crash recovery
- **Contagem medida em 25/09 no `mysql_error.log` do `data-novo`: 16 boots desde 22/08,
  14 com crash recovery.** Os 2 sem crash recovery corrigem a frase "todos os boots" do
  item acima:
  - o de **24/08 às 15:01:46** subiu **limpo**, sem `Starting crash recovery`
  - o de **14/09 às 12:07:39** é a segunda instância benigna, que abortou antes de
    recuperar (ver o ruído conhecido acima)
- **O abort de I/O das 18:10 de 07/09 continua SEM causa.** Foi com a máquina de pé, com
  APPCRASH do próprio `mysqld.exe` e "Tried to read … only able to read 0" — nada disso
  é tela azul. Os crash recoveries das 18:20 e das 20:25 do mesmo dia vêm **dessa**
  falha e da morte seguinte (≤18:22), e não de queda da máquina. O das **16:59**, o
  primeiro do dia, é **anterior** a tudo isso e segue sem causa atribuída
- **O que falta para fechar a flag:** cruzar **cada** um dos 14 boots com os eventos
  Kernel-Power 41 / 6008 / BugCheck do System. Isso diz se todo crash recovery vem de
  máquina que caiu, ou se há também desligamentos de máquina de pé em que o `mysqld` não
  fecha limpo (serviço morto no shutdown do Windows, por exemplo) — **mais de uma causa**
  é a hipótese a descartar. É consulta de leitura, e **não foi feita**
- ⚠️ **Desvio a declarar, pelo item 4 do roteiro abaixo:** no levantamento de 25/09 eu
  rodei `CHECK TABLE` em `wp_options`, `wp_newsgame_instances` e `wp_newsgame_dilemas`
  (os três `OK`) **antes** de ter a causa. Não era recorrência desta flag — a queda foi da
  máquina, e o boot já tinha feito crash recovery limpo —, mas o roteiro pede para não
  fazer isso antes de decidir

### Relação com o C10 — eleva o candidato, NÃO reabre o item

O C10 (`wp_newsgame_dilemas` vazia em 19/08) recebeu em 24/08 um **mecanismo
candidato**: com `innodb_force_recovery` no nível 1, leitura de página corrompida
**volta com zero linhas, sem erro e sem log**. Estava anotado como **inverificável**.

Este episódio mostra o mesmo mecanismo — leitura devolvendo zero bytes — só que
**ruidoso**, porque o `force_recovery` está **desligado** desde 22/08 e o InnoDB
aborta em vez de ignorar. É o contraste exato que a FLAG 008 descreve.

⚠️ **O que isto NÃO é:** evidência positiva do que aconteceu em 19/08. O C10 continua
sem hipótese sustentável e **não reabre**. O que muda é o status do candidato — de
"mecanismo sem demonstração possível" para **"mecanismo demonstrado nesta máquina, com
data e log"**.

### Se recorrer — medir antes de restaurar

Na ordem, e as três primeiras são grátis:

1. `Get-WinEvent -FilterHashtable @{LogName='Application'; ProviderName='MariaDB'}` —
   é onde a causa aparece quando o `mysql_error.log` não a tem
2. `SHOW VARIABLES LIKE 'innodb_force_recovery'` (a primeira medição do C10, e continua
   valendo: precisa estar em **0**)
3. Cronologia dos `LiveKernelEvent` e `Application Error` em volta do horário
4. **Não rodar `aria_chk`, nem `CHECK TABLE`, antes de decidir** — pela correção
   registrada na FLAG 008, `aria_chk --check` **grava** o flag de "tabela checada" no
   header, e a ferramenta de medição altera o que mede. `-T, --read-only` suprime a
   escrita
5. Só então reiniciar. **Cada boot faz crash recovery e ESCREVE no datadir** — reerguer
   o servidor é, ele mesmo, alteração de evidência

**Ver também:** **FLAG 008** (mesma máquina, mesmo datadir, e a regra de que
`force_recovery` é medida de emergência e não configuração), o **C10** e o aprendizado
de método da §6 do `ROADMAP_OPEN_SOURCE.md` — *em perda de dado, medir antes de
restaurar*.

---

## FLAG 016 — Cobertura do classificador de erro de provedor: o `422` é o destino de tudo o que ele não pega

**Status:** ✅ **RESOLVIDA em 2026-09-25** para os dois casos medidos (403 e 404) — ver
a seção "Resolução" no fim desta flag. *Status original, preservado:* 🔴 **ABERTA —
decisão, não tarefa.** Registrada em 2026-09-16, a partir de **dois casos medidos no
mesmo dia**, os dois no Gemini.

**O que acontece.** As rotas de geração devolvem **422 "Não foi possível gerar um dilema
válido para este slot"** quando `generate_one_dilema()` não produz dilema. Desde o passo
5b da fase 0 do **H1**, recusa de provedor deixou de cair nesse 422 e passa a **502 com
causa nomeada** — mas só a recusa que o classificador **reconhece**. Tudo o mais continua
saindo como 422, inclusive erro que **retentar nunca resolve**.

### O mecanismo, lido no código e não suposto

`erro_fatal_de_provedor()` (`generator.py:556`) decide em dois degraus:

1. **Status HTTP tem precedência** — `STATUS_CHAVE = (401, 403)`, `STATUS_CREDITO = (402,)`.
2. **Texto como reserva** — `MARCADORES_CHAVE` (8 substrings, entre elas
   `"permission denied"` e `"permission_denied"`) e `MARCADORES_CREDITO`
   (`"prepayment"`, `"credits are depleted"`, `"billing"`).

⚠️ **Para o Gemini o degrau 1 NUNCA dispara, e isso é por desenho.** `_status_http()`
(`:495`) só lê `status_code`, `http_status` e `response.status_code` — e **não** percorre
`__cause__` nem consulta `.code`, com motivos registrados na própria docstring (no gRPC
`.code` é método; na família OpenAI é string). As exceções do `google.api_core` não
expõem nenhum dos três atributos lidos, então `_status_http()` devolve `None` e **o
Gemini sempre cai no texto**.

### Os dois casos medidos em 2026-09-16

| caso | erro real do provedor | por que o classificador não pegou | o que o editor viu |
|---|---|---|---|
| **1** | `403 PERMISSION_DENIED`, `reason: API_KEY_SERVICE_BLOCKED` — *"Requests to this API generativelanguage.googleapis.com method …GenerateContent are blocked."* | status `None` (Gemini) e **nenhum marcador casa**: `"permission denied"` está no **nome da classe**, não na mensagem, e a mensagem diz *"are blocked"* | **422** "não foi possível gerar um dilema válido" |
| **2** | `404 NotFound` — *"This model models/gemini-2.5-flash is no longer available to new users. Please update your code to use models/gemini-3.6-flash"* | **não é erro de credencial nem de crédito** — é erro de **configuração**, e não há degrau para ele | **422**, idem |

Em ambos o log do backend registrou a causa verdadeira
(`[generator] generate_one tentativa=1 slot=0 is_rate=False erro=…`), então **o dado
existe e é o classificador que o descarta**.

**Por que importa, e não é preciosismo:** no caso 1 o editor é convidado a retentar uma
geração que **nunca** vai passar, gastando cota a cada tentativa; no caso 2 a mensagem
culpa a IA por um defeito de **arquivo de configuração** — o `LLM_MODEL` apontando para
um modelo retirado do ar. É a mesma mensagem enganosa que o passo 5b eliminou para
401/402, com a diferença de que ali a **cobertura** foi o limite, não a intenção.

⚠️ **ATUALIZAÇÃO DA MESMA DATA, À TARDE: o GATILHO do caso 2 foi corrigido; o DEFEITO
DESTA FLAG, NÃO.** O `LLM_MODEL` passou a apontar para `gemini-3.6-flash` (commit
`3ecdf14`), então aquele 404 específico não ocorre mais. Mas isso é **remoção da causa a
montante**, não conserto da classificação: um 404 de modelo inexistente — por typo no
`.env`, por outro modelo ser retirado do ar, ou na próxima instalação que copie um
`LLM_MODEL` inválido — **continua saindo como 422 "não foi possível gerar um dilema
válido"**. Os dois casos seguem válidos como descrição do mecanismo, e o **caso 1
(403 `API_KEY_SERVICE_BLOCKED`) não tem nem gatilho corrigido**: basta uma chave com
restrição de API para reproduzi-lo. **A flag permanece 🔴 ABERTA, com as três saídas
intactas.**

⚠️ **ESTA FLAG NÃO ACUSA O DESENHO DE ESTAR ERRADO.** O escopo estreito está declarado
na docstring de `erro_fatal_de_provedor()`: *"trata 'o provedor recusou', e não constrói
taxonomia de erro"*, e o `break` que devolve 422 em erro transitório é decisão
consciente — num lote de 15 slots, pular um é melhor que abortar o lote. O que a flag
acrescenta é o **custo medido** desse escopo, que antes era hipotético.

### Três saídas, nenhuma escolhida

1. **Acrescentar marcadores de texto** para os dois casos (`"are blocked"`,
   `"no longer available"`). É a saída de uma linha — e é exatamente a fragilidade que o
   comentário de `MARCADORES_CHAVE` já descreve: substring de mensagem de provedor
   envelhece a cada mudança de redação do fornecedor, e `"billing"` já é o precedente de
   marcador largo demais.
2. **Ler o status do Gemini por caminho próprio e tipado** — `isinstance` de
   `GoogleAPICallError` e então `.code`, que nesse SDK **é** inteiro (teria pegado o caso
   1). Custa **acoplar o classificador a um SDK específico**, que é justamente o que a
   fase 0 do H1 desfez ao tirar o import de provedor do `main.py`. E **não** resolve o
   caso 2, que não é credencial.
3. **Separar "erro de configuração" de "falha de geração"** — 404/400 de modelo
   inexistente viram resposta própria com causa, e o 422 volta a significar só *"o LLM
   não produziu dilema válido"*. É a maior das três e a única que resolve a **classe** em
   vez dos dois casos. Pede uma exceção irmã da `ErroDeProvedor`.

### Resolução (2026-09-25)

**A escolha não foi exatamente nenhuma das três saídas acima.** Ficou entre a 2 e a 3: lê
o status do Gemini, mas **sem acoplar ao SDK**; e trata o 404 como erro de configuração,
mas **dentro** da `ErroDeProvedor` (502), sem exceção irmã.

**Causa raiz, medida e não suposta.** Nos dois casos o wrapper `langchain-google-genai`
**não embrulha** a exceção: só `InvalidArgument` vira `ChatGoogleGenerativeAIError`, e o
resto cai num `except Exception: raise` (`chat_models.py:228`). Chegam **cruas** as
exceções do `google.api_core` — `PermissionDenied` e `NotFound` —, que **não** têm
`status_code`, `http_status` nem `response.status_code`, os três atributos que
`_status_http()` lê. Por isso ela devolvia `None` e o Gemini caía no texto. Mas essas
exceções **têm `.code` inteiro**: `HTTPStatus.FORBIDDEN` (403) e `HTTPStatus.NOT_FOUND`
(404), e `HTTPStatus` é `IntEnum`.

**A correção** (`generator.py`):
- **`_status_por_code()`** — leitor **adicional** de `.code`, aceito só quando é inteiro.
  O `isinstance(int)` é o que dispensa importar o SDK: na família OpenAI o `.code` é
  string (`"invalid_api_key"`), e no gRPC é método — os dois são recusados. Era esse o
  motivo de `_status_http()` não ler `.code`, e ela continua sem ler
- ⚠️ **Só soma casos fatais, NÃO decide sozinho.** Ao contrário de `_status_http()`,
  cair num código não-fatal **não** encerra a classificação: segue para os marcadores de
  texto como antes. Se decidisse, um 429 do Gemini com "prepayment" na mensagem — crédito
  esgotado, fatal pelo texto — passaria a ser retentado como rate limit, porque os laços
  consultam `erro_fatal_de_provedor()` **antes** do teste de `is_rate`
- **`STATUS_CONFIG = (404,)`**, fatal em `erro_fatal_de_provedor()` para **todos** os
  provedores (pelo status_code nos três que o expõem, pelo `.code` no Gemini). O 403 já
  estava em `STATUS_CHAVE`; faltava só ser **lido** no Gemini, e `erro_de_chave()` passou
  a lê-lo
- **Mensagem nova para o 404** em `detail_de_provedor()`: diz "HTTP 404", aponta o modelo
  configurado, nomeia a variável do provedor pela tabela (`LLM_MODEL`,
  `LLM_MODEL_DEEPSEEK`…) e anexa o texto do provedor truncado — que no caso 2 nomeava o
  substituto

**O status HTTP devolvido ao plugin continua 502, não 403/404 literais**, por decisão:
o plugin repassa o status do backend ao navegador (`class-newsgame-generator.php:177`),
onde 403 se confunde com nonce/permissão do WordPress e 404 com rota inexistente. É o
mesmo critério que fez a fase 0 escolher 502 em vez de 401. O código real do provedor
vai **na mensagem**.

✅ *Testes em 2026-09-25:*
- **Unidade, 15/15**, com as classes de exceção **reais** dos SDKs instalados: os dois
  casos desta flag; as regressões que **não** podiam mudar (429 de quota não-fatal, 429
  com "prepayment" fatal, 500 com "billing" não-fatal, chave inválida do Gemini pelo
  texto); DeepSeek 401/402/404; `openai.AuthenticationError` com `.code` string;
  `openai.NotFoundError` real; `.code` como método; timeout; slug desconhecido
- **Chamada real ao Gemini com modelo inexistente** (chave de produção, zero cota):
  `google.api_core.exceptions.NotFound`, `_status_http` = `None`, `_status_por_code` =
  **404**, fatal, mensagem nova citando `LLM_MODEL`
- **Chamada real ao Gemini com chave restrita** (fornecida pelo editor em arquivo
  temporário, lida sem impressão e apagada depois; modelo inexistente de propósito, para
  que chave **não** bloqueada desse 404 e discriminasse): `PermissionDenied` com
  `API_KEY_SERVICE_BLOCKED`, `_status_http` = `None`, `_status_por_code` = **403**,
  `erro_de_chave` e fatal `True`. Zero cota

⚠️ **Não exercitado:** a passagem pela rota HTTP com o uvicorn no ar. Que "fatal" vira
502 em `/generate-one` e `/regenerate` e `fatal: true` no SSE está provado desde o passo
5b da fase 0, e foi conferido por leitura, não reexecutado aqui.

⚠️ **O que continua fora, e é por isso que a classe não fechou por inteiro:** um **400**
de modelo inválido (a saída 3 citava "404/400") segue sem degrau próprio — 400 não
distingue modelo inválido de payload malformado. E qualquer erro sem status e sem
marcador continua caindo no 422, que segue sendo o destino padrão.

### Atualização de 2026-10-02 — cota separada de crédito (`852daaf`)

**O falso positivo do `"billing"` aconteceu de verdade.** Em 02/10, às 22:13, no
`uvicorn.log`: a cota diária do plano gratuito do Gemini — 429, *"You exceeded your
current quota, please check your plan and **billing** details"*,
`generate_content_free_tier_requests, limit: 20`, *"Please retry in 22h46m"* — chegou
ao editor como **falta de crédito**, porque `"billing"` estava em `MARCADORES_CREDITO`.
Era o marcador "largo demais" que a saída 1 desta flag já citava como precedente.

**A correção:**
- `"billing"` **saiu** de `MARCADORES_CREDITO`. Entrou `"insufficient_quota"`, o código
  da OpenAI para conta **sem crédito**, que vem com status 429 e com o mesmo texto da
  cota do Google — sem ele, crédito esgotado na OpenAI seria lido como limite que
  renova. Vale também no ramo de status (`_credito_em_429()`).
- Cota ganhou classificação própria, separada de crédito: `MARCADORES_COTA`
  (`"exceeded your current quota"`, `"quota exceeded"`, `"resource_exhausted"`,
  `"resourceexhausted"`) e `STATUS_COTA = (429,)`.
- Cota só é **fatal** quando não renova durante o lote (`cota_longa()`): a mensagem
  traz `perday`/`per_day`, ou o provedor pede espera maior que `TETO_ESPERA_COTA_S`
  (**120 s**), lida do "retry in …". Limite por minuto continua no caminho de espera e
  retentativa do laço.
- Mensagem própria em `detail_de_provedor()`: *"recusou a chamada por limite de uso
  (cota) da chave de API, e não por falta de crédito"*, com "O limite é do plano
  gratuito" quando o texto traz `free_tier`, e o prazo de renovação quando o provedor
  o informa.

✅ *Teste unitário:* `tests/test_classificador_cota.py`, **5 testes, OK** (rodados de
novo em 05/10), com as exceções **reais** de `google.api_core` e `openai`. Contra o
`generator.py` anterior ao `852daaf`, 3 dos 5 falham. **Rótulo de cada texto, como
está no teste:** só a mensagem de cota do Gemini é **real** (copiada do log de 02/10); a
de crédito do Gemini é **fragmento** (o único registro é o commit `06449b0`); as duas da
OpenAI são **sintéticas**.

⚠️ **NÃO REPRODUZIDO em runtime depois da correção.** Nenhuma chamada real com cota
esgotada passou pelo classificador novo: a chave trocada às 22:38 do mesmo dia não bateu
no limite. A mensagem nova nunca foi vista pelo editor. E a prova dependeria do log do
backend, que não é escrito em arquivo desde 03/10 (ver a FLAG 019).

**Onde está:**
- `backend-python/app/generator.py` — `_status_http()` (`:495`), `MARCADORES_CHAVE`
  (`:472`), `MARCADORES_CREDITO` (`:485`), `erro_de_chave()` (`:540`),
  `erro_fatal_de_provedor()` (`:556`), `detail_de_provedor()` (`:581`) — linhas de
  16/09, deslocadas pela resolução; desde 25/09 também `_status_por_code()` e
  `STATUS_CONFIG`
- `backend-python/app/main.py` — as rotas que traduzem `ErroDeProvedor` para 502

**Ver também:** **FLAG 001** (é o laço de validação + auto-reparo que produz o 422, e é
por isso que ele é o destino padrão), **FLAG 012** (o SDK descontinuado é o que hoje
decide qual caminho de exceção existe para o Gemini), e o item 4 do bloco de 16/09 na
§10 do `CLAUDE.md`, que tem a evidência de log dos dois casos.

---

## FLAG 017 — Raciocínio do Gemini 3 desligado por parâmetro de compatibilidade retroativa

**Status:** 🟡 **CORREÇÃO APLICADA, dívida residual registrada.** Não é "risco sem
correção proposta": a linha existe, está em produção e foi calibrada. O que fica é a
**natureza retrocompatível** do parâmetro e a revisão futura. Aberta em 2026-09-16
(commit `3ecdf14`).

**O problema, medido.** O `gemini-3.6-flash` vem com raciocínio **LIGADO por default do
servidor**, e o `_cliente_gemini()` era o único dos quatro construtores sem controle
disso — porque o `gemini-2.5-flash` anterior não tinha o modo. Medição com
`usage_metadata` da resposta real, mesma instância e mesmo prompt, mudando só o
parâmetro:

| | sem o parâmetro | com `thinking_budget=0` |
|---|---|---|
| tokens de raciocínio | **2.339** (84,9% da saída) | **0** |
| tokens de saída | 2.754 | **448** |
| latência do `invoke` | 56,04 s | **4,04 s** |
| texto visível | 1.464 chars | **1.552 chars** |

Consequências que o número quantifica: token de raciocínio é **cobrado como saída**,
então 415 tokens de dilema custavam **2.754** — 6,6× o texto entregue; e os 56 s eram
**47% do teto de 120 s** do `wp_remote_post`, com lote de 15 slots levando ~12 min.

**Correção aplicada:** `thinking_budget=0` em `_cliente_gemini()`, alinhando o Gemini ao
padrão explícito dos outros três (`extra_body` no DeepSeek, `reasoning_effort="none"` na
OpenAI, `thinking={"type": "disabled"}` no Claude) e à regra já escrita no código —
*nunca confiar no default*. ✅ *Calibrado com 3 gerações reais: reasoning 0/0/0, `invoke`
4,04/3,96/5,31 s, 1 invocação cada (sem auto-reparo da FLAG 001), `_is_valid_dilema()`
True nas três.*

### A dívida que sobra — e é o motivo desta flag existir

⚠️ **`thinking_budget` é CAMINHO DE COMPATIBILIDADE RETROATIVA, e é a única linha dos
quatro construtores que depende disso.** A doc oficial
(`ai.google.dev/gemini-api/docs/generate-content/thinking`) o declara *"aceito por
compatibilidade retroativa"* nos modelos Gemini 3; a ressalva de *"performance
inesperada"* é declarada para o **3 Pro** e **não** para o Flash — testado limpo no
`gemini-3.6-flash`, zero erro.

⚠️ **O MODO DE FALHA É SILENCIOSO.** Se o Google remover a retrocompatibilidade, o
parâmetro passa a ser ignorado, o raciocínio volta ao default **ligado**, e **não há
erro, aviso ou log** — só a latência subindo de ~4 s para ~56 s e a conta de saída
sextuplicando. Nenhum teste automatizado cobre isso; o sintoma observável é a latência.

⚠️ **O parâmetro recomendado para a linha 3 é `thinking_level`, e hoje é INEXPRESSÁVEL
aqui** — não é questão de estilo, é ausência nas duas camadas:

| camada instalada | conhece `thinking_level`? |
|---|---|
| `langchain-google-genai 2.1.12` (wrapper) | ❌ zero ocorrências nos 11 `.py` do pacote |
| `google-ai-generativelanguage 0.12.0` (proto) | ❌ `ThinkingConfig` só tem `include_thoughts` e `thinking_budget` |

Como o proto também não tem o campo, **não há contorno** por `generation_config` nem por
`model_kwargs`.

**A revisão está amarrada ao upgrade da FLAG 012.** Na `langchain-google-genai 4.4.0` o
campo existe como `reasoning_effort` com alias `thinking_level`,
`Literal["minimal","low","medium","high"]` (`chat_models.py:3230`), e lá o
`thinking_budget` está marcado *deprecated para Gemini 3+*. Duas ressalvas para quem
executar:

1. ⚠️ **O piso é `minimal`, que REDUZ e NÃO ZERA** — a troca **não** é equivalente e
   precisa ser **remedida** com `usage_metadata`, não assumida.
2. ⚠️ **O upgrade é de dois majors** (2.1.12 → 4.4.0) e mexe na mesma árvore que o
   `0fcc866` pagou para segurar: o `langchain-core` está preso na linha 0.3 por conflito
   declarado. **Não é ajuste de parâmetro, é decisão de dependência.**

### Lição de método que esta flag carrega

⚠️ **"Deprecated no pacote" não é "rejeitado na API", e confundir os dois quase custou o
conserto.** A recomendação inicial desta sessão foi **descartar** o `thinking_budget` e
tratar o upgrade de dois majors como pré-requisito — conclusão tirada de ler o *wheel* da
4.4.0, onde o parâmetro aparece como depreciado. A depreciação é do **wrapper**; a
aceitação é do **provedor**. A verificação na documentação do Google desfez a conclusão e
o conserto saiu sem upgrade nenhum, ao custo de **1 dilema**.
**Regra:** para saber o que o provedor aceita, a fonte é a documentação do provedor —
não o código do wrapper. É a mesma família da regra de confirmar nome de modelo na
fonte, com a precisão extra de que *fonte certa* também importa.

**Onde está:**
- `backend-python/app/generator.py` — `_cliente_gemini()`, com todo este raciocínio no
  comentário encostado na função
- `backend-python/app/config.py` — `llm_model`, cujo comentário registra a procedência do
  `gemini-3.6-flash`

**Ver também:** **FLAG 012** (o upgrade que destrava o `thinking_level`), **FLAG 001** (o
auto-reparo que a calibração exercitou sem acionar), **FLAG 016** (o 404 que expôs o
modelo morto saiu como 422 enganoso) e o item 9 do bloco de 16/09 na §10 do `CLAUDE.md`.

---

## FLAG 018 — `ajax_approve()` descarta `alternativa_correta` ao remontar o veredito

**Status:** 🔴 **ABERTA, sem correção aplicada.** Registrada em 2026-09-25, a partir da
aprovação do dilema 68 (instância 3, `jogo-teste-skin`) pelo handler real.

**O que acontece.** `Newsgame_Review::ajax_approve()` (`class-newsgame-review.php:137`)
grava `edited_fields()` inteiro junto com `review_status = 'approved'`. E
`edited_fields()` (`:512`) **reconstrói** o JSON do `veredicto` do zero, só com as três
chaves que o formulário da Mesa Editorial edita — `desfecho`, `licao` e `sintese`
(`:529-533`). Qualquer outra chave que existisse no veredito gravado some, e a única
que existe hoje é `alternativa_correta`.

- O dado **chega** ao navegador: `row_to_array()` expõe `alternativa_correta` (`:600`).
  Mas o `buildRow()` do `newsgame-admin-review.js` não cria campo para ele, então o
  `rowFields()` não o recolhe e o POST não o traz de volta
- O botão **`💾 Salvar alterações`** das linhas aprovadas usa a **mesma action**, então
  salvar uma edição num aprovado também passa por aqui (e o campo já não existe mais)

**Medido em 2026-09-25, no banco `jda` local:**

| status | total | com `alternativa_correta` |
|---|---|---|
| `approved` | 23 | **0** |
| `discarded` | 2 | **0** |
| `pending` | 5 | **5** |

- **24 dilemas passaram pela aprovação e perderam o campo:** os 23 aprovados hoje, mais o
  68, que foi aprovado e depois descartado na mesma sessão (o descarte só muda o status
  e não devolve nada). O outro descartado, o 58, também está sem o campo, o que é
  coerente: ele foi aprovado antes de ser descartado pelo `/regenerate` em 16/09
- **Nenhum pendente foi afetado** — os cinco ainda têm o campo intacto
- **Evidência direta da perda no 68:** antes da aprovação o veredito tinha
  `"alternativa_correta":"B"`, e depois não tinha mais. Título, enunciado, as três opções
  e a fonte ficaram iguais

**Severidade.** O **AGENTS.md** trata o campo como **obrigatório** (`:114`, `:170`), e o
backend aplica fallback `"B"` quando o modelo não o preenche (`generator.py`,
`_LETRAS_VALIDAS`). Mas **o jogo não o lê em runtime**: zero ocorrências em
`public/` e no `class-newsgame-shortcodes.php`. Hoje, portanto, **não há sintoma para o
jogador** — é perda silenciosa de um campo do contrato, que passa a doer no dia em que
algo voltar a consumi-lo.

**Não confundir com a FLAG 014.** Lá a restrição nunca chegava ao modelo; aqui o dado é
gerado e gravado corretamente e **se perde depois**, na revisão editorial.

**Onde está a referência da medição:** o backup da linha do dilema 68 **antes** da
aprovação está em `%TEMP%\ng-bk-dilema68-20260925-161613.sql` (3.399 bytes, com o
veredito original). ⚠️ **`%TEMP%` é volátil:** mover para fora de lá se precisar dele.

**Ver também:** item "substituta id 68" no bloco de 16/09 da §10 do `CLAUDE.md`, e a
família das escritas que regravam campo não enviado — o `image_id` já é tratado com
`isset()` em `edited_fields()` justamente para não apagar o que o chamador não mandou.

---

## FLAG 019 — Gemini devolve `.content` como LISTA, e não como string, de forma intermitente

**Status:** 🟡 **Mitigado no código, NÃO REPRODUZIDO depois da mitigação.** Registrado
em 2026-10-05, sobre medições de 2026-10-02. O helper `texto_da_resposta()` entrou no
`852daaf` e **nunca foi exercitado em runtime contra uma lista real**.

### O que foi medido (02/10, `uvicorn.log`)

| Rota | Quando | Erro | Resposta |
|---|---|---|---|
| `/analyze-domain` | 17:27:12 → 17:28:04 (**52 s**), e a tentativa 2 às 17:28:11 | `'list' object has no attribute 'strip'` nas duas tentativas | **422** — o erro saiu do `try` de validação da rota (o `text.strip()` do `_extract_json`), e não de dentro do `llm.invoke` |
| `/generate-one` | 17:47:05, 17:50:34 e 17:52:15 — **3 de 8 chamadas** | `AttributeError("'list' object has no attribute 'replace'")` | **422** cada. Não é rate limit, então cai no `break`: o slot teve **uma** tentativa, embora `max_retries_batch=5` |

**Na fonte instalada** (`langchain-google-genai` 2.1.12, `chat_models.py:572-588` e
`:600-618`, conferido de novo em 05/10), o `content` vira lista em dois casos: resposta
com **mais de uma parte de texto** (lista de `str`) ou com uma parte `thought` (dict
`{"type": "thinking"}`). **Qual dos dois aconteceu em 02/10 NÃO está medido.**

### A mitigação (`852daaf`)

`generator.texto_da_resposta()`, aplicado nos três pontos que leem `.content`:
`generate_one_dilema`, `regenerator.generate_one` e `main._invoke_llm` (o ponto único
das rotas de `main.py`, auto-reparo incluído). Para lista, junta os itens `str` e os
blocos `type == "text"`, descarta o resto e registra **um** warning
`[texto_da_resposta]` com os **tipos** de bloco, nunca o texto.

✅ *Teste unitário:* `tests/test_texto_da_resposta.py`, 6 testes. O controle sem o
helper reproduz o defeito (5 tentativas e `None`); com o helper, 1 tentativa e o dilema
válido. *Isto é teste de unidade com `content` montado à mão, não resposta real do
provedor.*

### Não reproduzido

Sonda em runtime em 02/10, às 22:38, **com outra chave e outro projeto do Google**: 8
`/generate-one` e 2 `/analyze-domain` direto no backend, **10/10 com HTTP 200**,
`/generate-one` em 3–4 s e `/analyze-domain` em 9 s, e **zero** linhas
`[texto_da_resposta]` — todas as respostas vieram como string.

- **A causa não foi isolada:** a chave e o projeto mudaram juntos.
- **Hipótese, não medição:** os ~50 s com lista à tarde, contra 3–4 s com string à
  noite, batem com a latência de raciocínio ligado da **FLAG 017** (~56 s contra ~4 s).
  Raciocínio ligado apesar do `thinking_budget=0` explicaria a parte `thought` e, com
  ela, a lista. Nada disso foi medido.

### Como reabrir

A linha `[texto_da_resposta]` no log do backend traz os tipos de bloco e decide sozinha
entre "várias partes de texto" (`str`) e "raciocínio" (`thinking`).

⚠️ **O sinal de reabertura depende de um log que hoje não existe.** O
`backend-python/uvicorn.log` não é escrito desde 03/10, às 00:08: o uvicorn em execução
loga só no terminal. Um warning `[texto_da_resposta]` emitido agora **se perde** quando
o terminal fecha. Um log próprio do backend em arquivo foi pedido em 05/10 como
proposta, e ainda não foi entregue nem aplicado.

**Onde está:**
- `backend-python/app/generator.py` — `texto_da_resposta()` e o ponto de leitura em
  `generate_one_dilema()`
- `backend-python/app/regenerator.py` — `generate_one()`
- `backend-python/app/main.py` — `_invoke_llm()`
- `backend-python/tests/test_texto_da_resposta.py`

**Ver também:** **FLAG 001** (o auto-reparo reenvia a resposta anterior e o
`_extract_json` faz `.strip()` sobre ela: é o laço que quebrava, e o helper fica
**antes** dele), **FLAG 012** (o formato de `.content` não é garantido por contrato
entre SDKs, e o upgrade para `google-genai` precisa revalidar este helper junto com a
FLAG 001), **FLAG 017** (a hipótese do raciocínio ligado).

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
