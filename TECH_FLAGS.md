# Tech Flags — Dívida Técnica Monitorada
> Consulte este arquivo antes de qualquer alteração em CSS, JS ou configuração de servidor.
> Cada item aqui é uma "gambiarra necessária" com risco de efeito colateral documentado.

---

## FLAG 1 — Detecção Dinâmica de Ambiente no wp-config.php

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

O plugin `jogo-amazonia.php` tem um filtro `option_home`/`option_siteurl` como fallback para outras instalações WP (sem o wp-config.php customizado). Os filtros agora são registrados condicionalmente (`if ( ! defined('WP_HOME') )`) — quando o wp-config.php define as constantes, os filtros não disparam, evitando conflito de URL e loop de redirecionamento.

**Risco remanescente:**
O bloco de HTTPS (Passo 1) pode causar **redirect loop** em servidor de produção com SSL nativo (Pantheon.io, WP Engine). Remover antes do deploy.

**Ação necessária antes do deploy definitivo:**
Remover todo o bloco (Passos 1 e 2) do `wp-config.php` — o servidor de produção tem SSL nativo e URLs corretas no banco de dados.

**Onde está:** `C:/xampp/htdocs/jda/wp-config.php` (ambiente local)

---

## FLAG 2 — position: fixed + z-index alto (CSS vs. Tema WordPress)

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
O jogo "flutua" sobre todo o conteúdo do WordPress. Consequências:
- O menu de navegação do tema fica oculto atrás do jogo (não é problema enquanto o jogo ocupa a página inteira, mas seria se precisarmos de um header WP visível simultaneamente).
- O scroll da página-mãe fica bloqueado enquanto o jogo está ativo.
- Em contextos futuros onde o jogo for um widget parcial (não full-page), essa abordagem precisará ser completamente reescrita.

**Ação necessária no futuro:**
Para o plugin open source, substituir por uma abordagem de template exclusivo (sem tema) — o plugin já tem o mecanismo de `template_include` para isso. Desenvolver uma página WordPress "sem cabeçalho" dedicada ao jogo elimina a necessidade do `position: fixed`.

**Onde está:** `E:/jogo-da-amazonia/jogo-da-amazonia-plugin/assets/jda.css`

---

## FLAG 3 — Cache-Bust com time() no carregamento de assets

**Problema original:**
Durante o desenvolvimento intensivo, o navegador (especialmente mobile Samsung S26 via Ngrok) cacheava versões antigas do CSS e JS, fazendo parecer que alterações não tinham efeito.

**Solução implementada:**
No `veredito.php`, os assets são enfileirados com `time()` como versão:

```php
wp_enqueue_style('jda-skin', plugin_dir_url(__FILE__) . 'assets/theme-amazonia.css', [], time());
wp_enqueue_script('jda-js', plugin_dir_url(__FILE__) . 'assets/jda.js', ['jquery'], time(), true);
```

**Risco:**
Em produção, `time()` garante que o navegador **nunca** use cache para esses arquivos — cada pageload baixa CSS e JS do zero. Isso é prejudicial para a performance e para o consumo de dados do usuário mobile.

**Ação necessária antes do deploy definitivo:**
Substituir `time()` por um número de versão semântico fixo (ex: `'1.0.3'`) ou usar o hash do arquivo. Atualizar esse número manualmente a cada deploy que altere o CSS/JS.

**Onde está:** `E:/jogo-da-amazonia/jogo-da-amazonia-plugin/veredito.php`

---

## FLAG 4 — CSS Híbrido Mobile/Desktop (Scroll vs. Fixed)

**Status:** ✅ Resolvido em 2026-05-11 — faixa de tablet 768–1024px estabilizada como `Stable`.

**Problema original:**
O `position: fixed` do FLAG 2 resolvia o desktop mas travava o scroll no mobile. O jogador ficava preso sem conseguir clicar em "Avançar" em telas pequenas.

**Solução inicial (29/03/2026):**
CSS condicional via media query:

```css
/* Desktop: fixed para cobrir viewport */
@media (min-width: 769px) {
    #jda-shell { position: fixed; height: 100vh; overflow: hidden; }
}

/* Mobile: absolute com scroll habilitado */
@media (max-width: 768px) {
    #jda-shell { position: absolute; min-height: 100vh; overflow-y: auto; }
}
```

**Pendência identificada em 2026-04-XX:**
Em tablets na faixa 769–1024px (iPad em 810/820/834/1024px), o comportamento ficava inconsistente: o bloco `(min-width:600) and (max-width:1024)` aplicava um frame portrait 9:16 com `max-width: 430px` centralizado, criando uma coluna estreita no meio da tela em vez de fullscreen. Diversas regras estruturais (isolamento `#jda-game-engine`, stories-bar fixa, modal z-index, touch-action, layout vertical dos botões) estavam restritas a `(max-width: 768px)` e não disparavam em iPads, deixando o tablet num "limbo de breakpoints" entre mobile e desktop.

**Resolução implementada (2026-05-11) — `Stable`:**

1. **Removidos** os blocos de frame portrait (`min-width:600 and max-width:1024` com `max-width:430px`, e `min-width:900 and max-width:1024` com border-radius/box-shadow).
2. **Unificado** o breakpoint estrutural de `(max-width: 768px)` para `(max-width: 1024px)` em 9 regras críticas em `jda-mobile.css` (isolamento do engine, splash phase-active, hide header tema, stories-bar fixa, z-index dos modais, touch-action, info-modal, consequencia-toast, layout `.nivel-grid`).
3. **Adicionado** bloco `(min-width: 768px) and (max-width: 1024px)` com tipografia tablet calibrada: `.dilema-situacao 17px`, `.opcao-texto 15px`, `.btn-nivel min-height:100px` (vs. 120 do celular), `.glass-card max-width:560px`.
4. **Adicionado** detecção `isTouchDevice` em `jogo-amazonia.js` (`ontouchstart` || `navigator.maxTouchPoints > 0`) que aplica `body.jda-touch` ao DOM. CSS replica regras de isolamento sob `body.jda-touch` (sem media query) como rede de segurança para o caso "Request Desktop Site" do Safari iPadOS, onde o UA é mascarado como Mac.
5. **Meta viewport** injetado via `wp_head` prioridade 0 com `width=device-width, initial-scale=1` — sem `maximum-scale` (decisão de acessibilidade WCAG, ver memória `feedback_acessibilidade_viewport`).

**Risco remanescente:**
Híbridos como Surface com mouse + touch entram em `body.jda-touch` mas mantêm o layout 50/50 do desktop (escopo intencionalmente conservador). Caso futuro relate problema, ajustar a estratégia para checar `pointer: fine` via `matchMedia` antes de aplicar a classe.

**Onde está:**
- `E:/jogo-da-amazonia/jogo-amazonia-plugin/assets/jda-mobile.css`
- `E:/jogo-da-amazonia/jogo-amazonia-plugin/assets/js/jogo-amazonia.js`
- `E:/jogo-da-amazonia/jogo-amazonia-plugin/jogo-amazonia.php` (action `jda_force_viewport`)

---

## FLAG 5 — Transições jQuery vs. CSS (Dívida de Animação)

**Contexto:**
O `jda.js` usa jQuery (`.fadeIn()`, `.fadeOut()`, `.hide()`, `.slideDown()`) para todas as transições de estado e revelação de conteúdo. O código já tem um comentário interno: `"Remove jQuery animations to prevent memory/DOM garbage on mobile"` — reconhecendo o problema mas sem resolver completamente.

**Risco documentado:**
- `.hide()` + `.fadeIn()` no `loadDilema()` causam o "salto" de layout no desktop: quando `$container.hide()` é chamado, o container colapsa temporariamente, fazendo o `.jda-progresso-wrapper` perder sua âncora visual. O `min-height: 540px` (FLAG adicionado na sessão de 29/03) atenua o sintoma mas não resolve a causa.
- Em dispositivos low-end com Samsung Internet/WebKit antigo, jQuery animations consomem mais memória do que as CSS transitions nativas.

**Solução definitiva (pendente para pós-MVP):**
Substituir as transições jQuery por CSS puro:
- `switchState()`: trocar `.addClass('active')` + `.css({display, opacity})` por classes CSS com `@keyframes` já definidas no `jda.css` (`jdaFadeIn`)
- `loadDilema()`: substituir `.hide().fadeIn()` por `opacity: 0 → 1` via classe CSS, sem alterar o `display`

**Impacto no layout:**
Quando a transição não alterar `display: none`, o `min-height` da âncora será desnecessário e pode ser removido.

**Onde está:** `E:/jogo-da-amazonia/jogo-da-amazonia-plugin/assets/jda.js`

---

## FLAG 6 — Sistema de Cores Off-White (Editorial Minimalista)

**O que mudou em 29/03/2026:**
O design do `#jda-state-game` migrou de glassmorphism escuro para o padrão **Editorial Minimalista**. A paleta do game state agora é:

| Token | Valor | Uso |
|---|---|---|
| Creme Papel | `#FDFCF5` | Fundo do game state, dilema card, área de opções |
| Azul Ardósia | `#2C3E50` | Texto dos dilemas e alternativas |
| Verde Musgo | `#1E4620` | Botões, bordas, headers de contraste |
| Verde Médio | `#2d6a4f` | Hover dos botões |

**Risco de regressão:**
A `theme-amazonia.css` tem uma seção de **blindagem** (`!important`) no final do arquivo. Se novas regras forem adicionadas acima dela sem os `!important` correspondentes, serão sobrescritas silenciosamente. **Sempre verificar a seção de blindagem ao adicionar estilos para `#jda-state-game`.**

**O que NÃO mudar sem discussão:**
- Splash (`#jda-state-splash`) e Seleção (`#jda-state-selection`) mantêm o verde escuro `#0d2b1f` — são a "entrada" imersiva antes do editorial
- O sistema de fotos de fundo está **comentado** na skin, não deletado — pode ser reativado para uma skin fotográfica futura

**Onde está:** `E:/jogo-da-amazonia/jogo-da-amazonia-plugin/assets/css/skins/theme-amazonia.css`

---

## FLAG 7 — Regra de Ouro contra Redundância CSS

**Problema documentado:**
Durante o desenvolvimento iterativo do `style_v3.css`, seletores duplicados se acumularam silenciosamente. A regra mais antiga sobrescrevia a nova sem gerar erro visível no browser — o bug só aparecia inspecionando o painel de estilos do DevTools e verificando qual regra estava riscada.

**Regra estabelecida:**
> Antes de aplicar qualquer novo estilo a um seletor existente, **inspecionar o arquivo CSS completo** e remover ou consolidar todas as ocorrências anteriores do mesmo seletor. Nunca empilhar regras — resolver o conflito na fonte.

**Quando se aplica:**
Em qualquer alteração de `style_v3.css`, `jda.css` ou `theme-amazonia.css`. Especialmente crítico em sessões de ajuste mobile, onde a tentação é adicionar um bloco `@media` novo em vez de revisar o existente.

**Onde já causou problema:** Consolidação do `style_v3.css` em 04/04/2026.

---

## FLAG 8 — Escalabilidade de Logo: Width Fixa, Height Auto

**Problema documentado:**
Logos em formato retrato (portrait), quando controlados por altura fixa (`height: Xpx`), distorcem em viewports menores ou maiores do que o breakpoint de referência. O efeito é especialmente visível no mobile quando o logo aparece comprimido horizontalmente.

**Regra estabelecida:**
> Logos e imagens editoriais em formato retrato devem sempre ser controlados por **`width` fixo + `height: auto`**. A altura é consequência da proporção intrínseca da imagem — nunca deve ser definida explicitamente.

**Implementação correta:**
```css
.logo-img {
  width: 100%;
  max-width: 300px;
  height: auto;        /* NUNCA height: Xpx */
  object-fit: contain;
}
```

**Quando se aplica:**
Qualquer imagem de logo, ícone editorial ou asset de identidade visual carregado via `<img>` ou `background-image` em formato portrait.

---

## Registro de Tentativas Fracassadas (Para Não Repetir)

| Tentativa | O que foi tentado | Por que falhou |
|---|---|---|
| Centralizar jogo via `margin: auto` | CSS padrão de centralização | O tema WP sobrescrevia com seu container de 840px |
| `width: 100%` no wrapper | Herda a largura do container do tema | Resultado: 840px mesmo assim |
| Desativar tema via `remove_action` | Remover header/footer do tema via PHP | Causava erros de JS em scripts dependentes do tema |
| Tentar usar template via interface WP | Selecionar template pela UI do WordPress | WP local não reconhecia os templates do plugin, dava 404 |
| Botão amarelo como prova visual de cache | `#jda-start-game { background: #FFD600 }` no mobile | Útil para confirmar carregamento do CSS novo; removido na mesma sessão após confirmação |
| Glassmorphism sobre fundo escuro sem fotos | Caixas translúcidas + backdrop-filter | Sem as fotos ativas, o efeito criava ruído visual sem propósito; substituído pelo Editorial Minimalista |

---

## Ambiente de Desenvolvimento Atual

| Item | Detalhe |
|---|---|
| Servidor local | XAMPP — Apache + MySQL |
| URL local | `http://localhost/wordpress` (ou `amazonia.local` via VHost) |
| Código fonte | `E:/jogo-da-amazonia/jogo-da-amazonia-plugin/` |
| Link simbólico | `C:/xampp/htdocs/wordpress/wp-content/plugins/jogo-da-amazonia-plugin` → `E:/jogo-da-amazonia/jogo-da-amazonia-plugin/` |
| Acesso externo | Ngrok (túnel HTTPS temporário) |
| Deploy cloud | Pantheon.io (via SFTP + chaves SSH RSA 4096) |
