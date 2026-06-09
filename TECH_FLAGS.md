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
