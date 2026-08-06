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

## FLAG 002 — Complexidade Ciclomática do JavaScript (Pendente)

**Status:** ⏳ Pendente — a ser executado antes da fase de UX

**Contexto:**
O JavaScript do plugin (`public/js/newsgame-game.js` e o inline 
de `page-instance-form.php`) ainda não tem controle de complexidade 
ciclomática. Durante a fase de UX, o JS vai crescer significativamente.

**Ação planejada:**
Configurar a regra `complexity` no ESLint com limite máximo de 10:
- Adicionar apenas a regra `complexity` na config ESLint existente
- Não instalar dependências adicionais
- Não alterar código de negócio
- Auditar funções que já ultrapassam o limite e refatorar antes 
  de adicionar nova lógica de UX

**Quando executar:**
Após o commit do `/assign-weights` e antes de iniciar a fase de UX.

**Onde monitorar:**
- `news-game-plugin/public/js/newsgame-game.js`
- `news-game-plugin/admin/views/page-instance-form.php` 
  (bloco `<script>` inline)

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

**Status:** 🟡 Pendente — diagnóstico feito em 2026-07-31, correção não aplicada

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
| Estilos | ~70 atributos `style="` | Ignorar — `style-src` não vale o esforço |

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

1. **O par acima** (~10 linhas) — fecha a superfície pública. É o item que
   habilita a promessa "compatível com CSP estrita" no README.
2. `onclick="return confirm(...)"` de `page-instances.php:84` → `addEventListener`.
3. Os 5 `<script>` inline do admin → arquivos `.js` enfileirados com
   `wp_enqueue_script`, dados via `wp_localize_script`. Trabalho maior, ganho
   menor (superfície autenticada).
4. **Documentar no README** um exemplo de cabeçalho CSP que o veículo pode
   aplicar, e declarar que o plugin funciona sob ele. Isso é argumento de
   adoção: nenhum plugin de quiz WP oferece isso.

### O que NÃO fazer

- Não emitir `Content-Security-Policy` pelo plugin (conflita com o site).
- Não resolver com nonce de CSP: funciona, mas exige que o site coordene o
  nonce com o plugin. O bloco de dados não exige nada do hospedeiro.
- Não perseguir `style-src` — os ~70 `style="` são cosméticos e a CSP de estilo
  tem retorno de segurança marginal.

**Ver também:** FLAG 003 (segredos fora do browser) e a correção de
2026-07-31 que aplicou `esc()` nos nomes de eixo do radar mais
`sanitize_text_field()` no save — a CSP é a terceira camada sobre essas duas.

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
