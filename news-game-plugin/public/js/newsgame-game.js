(function () {
  'use strict';

  /* ── Escape de HTML para evitar XSS nos dados do DB ── */
  function esc(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function escNl(str) {
    return esc(str).replace(/\n/g, '<br>');
  }

  /* ── Allowlist de esquema para hrefs vindos do DB ──
     esc() neutraliza quebra de atributo mas não valida o esquema —
     "javascript:" sobreviveria intacto. Defesa em profundidade sobre
     o esc_url_raw() do PHP, cobrindo linhas gravadas antes dele. */
  function safeUrl(url) {
    try {
      var u = new URL(String(url), window.location.href);
      if (u.protocol === 'http:' || u.protocol === 'https:') return u.href;
    } catch (e) { /* URL malformada cai no '#' */ }
    return '#';
  }

  /* Neutraliza os caracteres que fechariam o `url('…')` do CSS. Adaptado do
     cssUrlSafe() do JDA (jogo-amazonia.js:298), que é hardening registrado lá.
     Aqui o risco é MENOR — a URL vem de wp_get_attachment_image_url(), ou seja
     do próprio uploads, e já passou por esc_url_raw() no PHP —, mas custa nada
     e cobre linha gravada por versão futura ou por importação de banco. */
  function cssUrlSafe(url) {
    return String(url).replace(/['"()\\\r\n]/g, '');
  }

  /* Trava de leitura do feedback, em segundos. Espelha o LOCK_SEGUNDOS = 8 do
     JDA (jogo-amazonia.js:1313). Fica aqui, e não em configuração por
     instância, porque configuração exige coluna, UI e migração — item à parte. */
  var LOCK_SEGUNDOS = 8;

  /* Espera até a luz começar a contornar o cartão da pergunta. Espelha os
     5000 ms do `startNeonTravel` do JDA (jogo-amazonia.js:514). O JS só marca o
     cartão com `ng-neon`; o desenho e o movimento vivem na folha da skin (hoje
     só a Padrão desenha), e skin sem regra para a classe não muda nada. */
  var NEON_ESPERA_MS = 5000;

  /* O giro anima uma propriedade registrada por `@property`. Onde ela não existe,
     a animação viraria um salto entre dois ângulos — melhor não marcar o cartão e
     deixar o fio estático. `CSS.registerProperty` é da mesma especificação
     (Properties and Values API) e chegou junto com o `@property` nos navegadores. */
  var NEON_SUPORTADO = !!(window.CSS && typeof window.CSS.registerProperty === 'function');

  /* ── Barra de progresso ────────────────────────────────
     Extraída porque os dois estados montavam o mesmo HTML duplicado — e com
     isso os DOIS atributos de estilo de largura viram UM, o que baixa a
     contagem do A5 de 6 para 5 sem fechar nenhum dos 4 grupos (o A5 é decisão,
     não tarefa).

     A frase acima não escreve o nome do atributo de propósito: a auditoria do
     A5 é um `grep`, e citá-lo em comentário inflaria a contagem que ele mede.
     Mesma regra registrada no comentário do crédito da foto, no shortcode.

     Desde 25/09 a largura NÃO vai mais no markup: o HTML leva só o número em
     `data-pct`, e `aplicarProgresso()` a escreve por CSSOM depois da inserção.
     Era o último atributo de estilo inline da superfície do jogador, e o que
     fechou o A5: a CSP `style-src` bloqueia estilo no markup, mas não a
     atribuição de propriedade (`el.style.width`) — mesma técnica do
     `aplicarFundo()`.

     ⚠️ Tem de ser atribuição de PROPRIEDADE. `setAttribute` com o nome do
     atributo, ou `style.cssText`, são bloqueados pela diretiva igual ao markup. */
  function buildProgress(preenchido, atual, total) {
    var pct = Math.round(preenchido / total * 100);
    return '<div class="ng-progress">' +
        '<div class="ng-progress-track">' +
          '<div class="ng-progress-fill" data-pct="' + pct + '"></div>' +
        '</div>' +
        '<span class="ng-progress-label">' + atual + ' / ' + total + '</span>' +
      '</div>';
  }

  /* Chamar logo depois do `innerHTML` que inseriu o `buildProgress()`, no mesmo
     tick: assim a largura já está aplicada no primeiro paint, e a barra aparece
     exatamente como aparecia com o valor no markup. Como antes, não há animação
     entre telas — o elemento é recriado a cada tela, e a transição de `width`
     do CSS não tem valor anterior de onde partir. */
  function aplicarProgresso(raiz) {
    var fill = raiz.querySelector('.ng-progress-fill');
    if (fill) { fill.style.width = fill.getAttribute('data-pct') + '%'; }
  }

  /* Botão (i). Só existe se houver o que mostrar: botão que abre modal vazio é
     pior que botão ausente. */
  function buildInfoBtn(temInfo) {
    if (!temInfo) { return ''; }
    return '<button type="button" class="ng-btn-info" aria-expanded="false"' +
      ' aria-label="Ver contexto do dilema">i</button>';
  }

  /* Modal de contexto: a síntese do dilema e o link para a matéria de origem.
     Os dois vinham empilhados na tela de feedback até aqui, e o link aparecia
     como URL crua na tela. */
  function buildInfoModal(sintese, url) {
    var link = url
      ? '<a class="ng-modal-link" href="' + esc(safeUrl(url)) + '"' +
        ' target="_blank" rel="noopener noreferrer">Aprofundar ↗</a>'
      : '';
    return '<div class="ng-modal" role="dialog" aria-modal="true"' +
        ' aria-hidden="true" aria-label="Contexto do dilema">' +
        '<div class="ng-modal-backdrop"></div>' +
        '<div class="ng-modal-content">' +
          '<button type="button" class="ng-modal-close" aria-label="Fechar">&times;</button>' +
          '<span class="ng-verd-label">Contexto</span>' +
          '<p class="ng-modal-text">' + escNl(sintese) + '</p>' +
          link +
        '</div>' +
      '</div>';
  }

  /* ── Tela cheia ────────────────────────────────────────
     API padrão do navegador, com o prefixo do WebKit antigo como reserva.
     Nenhuma biblioteca. */
  function fullscreenSuportado() {
    return !!(document.fullscreenEnabled || document.webkitFullscreenEnabled);
  }

  function elementoEmTelaCheia() {
    return document.fullscreenElement || document.webkitFullscreenElement || null;
  }

  function entrarTelaCheia(el) {
    var pedir = el.requestFullscreen || el.webkitRequestFullscreen;
    if (!pedir) { return; }
    /* requestFullscreen devolve Promise que REJEITA quando o navegador nega —
       fora de gesto do usuário, ou política de permissão de iframe. Sem o
       catch, isso vira "unhandled rejection" no console do editor. */
    var r = pedir.call(el);
    if (r && r.catch) {
      r.catch(function (e) { console.warn('[news-game] tela cheia negada:', e && e.message); });
    }
  }

  function sairTelaCheia() {
    var sair = document.exitFullscreen || document.webkitExitFullscreen;
    if (!sair) { return; }
    var r = sair.call(document);
    if (r && r.catch) { r.catch(function () { /* já saiu por Esc/F11 */ }); }
  }

  /* Ícone em SVG, com `fill="currentColor"` — a cor vem do `color` do botão, no
     CSS. NÃO usar var() em atributo de apresentação: é a regra 4 do H2, porque
     atributo é parseado por gramática isolada, fora da cascata, e o Chromium não
     resolve. `currentColor` é palavra-chave do próprio SVG e não tem esse
     problema. Os dois desenhos são quatro cantos em L: apontando para fora
     quando está fora da tela cheia, e para dentro quando está dentro. */
  function iconeTelaCheia(dentro) {
    var d = dentro
      ? 'M10 10H4V8h4V4h2v6z M14 10V4h2v4h4v2h-6z M10 14v6H8v-4H4v-2h6z M14 14h6v2h-4v4h-2v-6z'
      : 'M4 4h6v2H6v4H4V4z M20 4v6h-2V6h-4V4h6z M4 20h6v-2H6v-4H4v6z M20 20h-6v-2h4v-4h2v6z';
    return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"' +
      ' focusable="false"><path fill="currentColor" d="' + d + '"/></svg>';
  }

  /* ── Gráfico triangular de perfil (adaptado do JDA) ──── */
  /* Classe de sinal do polígono. Zero conta como positivo (`>= 0`), e não há
     zona neutra — é o comportamento que estava no ternário original. */
  function polySignClass(v) {
    return v >= 0 ? 'is-positive' : 'is-negative';
  }

  /* Classe de sinal do vértice. Aqui há zona morta de ±0.05, que vira neutro —
     também idêntico ao original. Semântica diferente da do polígono de
     propósito: por isso são duas funções e não uma com parâmetro. */
  function dotSignClass(v) {
    if (v > 0.05)  { return 'is-positive'; }
    if (v < -0.05) { return 'is-negative'; }
    return 'is-neutral';
  }

  /* Geometria do triângulo, em unidades do SVG: circunraio 100 e centro na
     origem; topo → eixo1, direita-baixo → eixo2, esquerda-baixo → eixo3. A
     caixa (viewBox) é JUSTA ao triângulo, só com margem para os pontos, e a
     largura do triângulo na tela vem do CSS (`--ng-radar-tri`). Os rótulos
     ficam FORA do SVG, em HTML, ao lado do triângulo (ver buildRadar) — antes
     moravam dentro dele, encolhiam junto com o desenho (cerca de 6 px no
     celular) e os da direita saíam cortados. */
  var RADAR_R   = 100;
  var RADAR_M   = 10;
  var RADAR_ANG = [-90, 30, 150].map(function (a) { return a * Math.PI / 180; });

  function radarPonto(n, i) {
    return [
      +(n * RADAR_R * Math.cos(RADAR_ANG[i])).toFixed(2),
      +(n * RADAR_R * Math.sin(RADAR_ANG[i])).toFixed(2),
    ];
  }

  /* Percentual com sinal, como antes: o sinal sai do valor sem arredondar. */
  function radarPct(n) {
    var pct = Math.min(100, Math.max(-100, n * 100));
    return (pct >= 0 ? '+' : '') + Math.round(pct) + '%';
  }

  /* Só o desenho. Os traços não escalam com o SVG (`non-scaling-stroke`): as
     espessuras são em px na tela, do celular ao desktop. */
  function radarSVG(norms, dotCls) {
    var meia  = RADAR_R * Math.cos(Math.PI / 6) + RADAR_M;
    var caixa = [-meia, -(RADAR_R + RADAR_M), 2 * meia, 1.5 * RADAR_R + 2 * RADAR_M]
      .map(function (v) { return +v.toFixed(2); }).join(' ');
    var fixo  = ' vector-effect="non-scaling-stroke"';
    var grade = [0.25, 0.5, 0.75, 1].map(function (lv) {
      var p = [0, 1, 2].map(function (i) { return radarPonto(lv, i).join(','); }).join(' ');
      return '<polygon points="' + p + '" fill="none"' +
             ' class="' + (lv === 1 ? 'ng-radar-grid-outer' : 'ng-radar-grid') + '"' +
             ' stroke-width="' + (lv === 1 ? 1.2 : 0.7) + '"' + fixo + '/>';
    }).join('');
    var eixos = [0, 1, 2].map(function (i) {
      var v = radarPonto(1, i);
      return '<line x1="0" y1="0" x2="' + v[0] + '" y2="' + v[1] + '"' +
             ' class="ng-radar-axis" stroke-width="0.8"' + fixo + '/>';
    }).join('');
    // Valores negativos cruzam o centro — efeito fratura, como antes
    var pts    = norms.map(radarPonto);
    var media  = (norms[0] + norms[1] + norms[2]) / 3;
    var pontos = pts.map(function (p, i) {
      return '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="7"' +
             ' class="ng-radar-dot ' + dotCls[i] + '" stroke-width="2"' + fixo + '/>';
    }).join('');
    return '<svg class="ng-radar-svg" viewBox="' + caixa + '" aria-hidden="true" focusable="false">' +
      '<g>' + grade + '</g>' +
      '<g>' + eixos + '</g>' +
      '<polygon points="' + pts.map(function (p) { return p.join(','); }).join(' ') + '"' +
               ' class="ng-radar-poly ' + polySignClass(media) + '"' +
               ' stroke-width="2" stroke-linejoin="round"' + fixo + '/>' +
      '<g>' + pontos + '</g>' +
      '<circle cx="0" cy="0" r="4" class="ng-radar-center"/>' +
    '</svg>';
  }

  /* Rótulo de um eixo: nome (pode quebrar em linhas) e percentual com a cor do
     sinal. `aria-hidden`: quem lê é o `aria-label` do bloco. */
  function radarEixo(i, nome, n, cls) {
    return '<div class="ng-radar-eixo ng-radar-eixo-' + (i + 1) + '" aria-hidden="true">' +
        '<span class="ng-radar-label">' + esc(nome) + '</span>' +
        '<span class="ng-radar-pct ' + cls + '">' + radarPct(n) + '</span>' +
      '</div>';
  }

  /* Gráfico de perfil: eixo1 acima do triângulo; eixo3 à esquerda e eixo2 à
     direita, ao lado dos vértices de baixo (grade de três colunas no CSS).
     Para leitor de tela, o bloco é UMA imagem com nomes e percentuais no
     `aria-label`; rótulos e desenho ficam `aria-hidden`, sem leitura duplicada. */
  function buildRadar(vetor, eixos) {
    var norms  = [vetor.eixo1 || 0, vetor.eixo2 || 0, vetor.eixo3 || 0];
    var nomes  = ['eixo1', 'eixo2', 'eixo3'].map(function (k, i) {
      return (eixos[k] && eixos[k].nome) || 'Eixo ' + (i + 1);
    });
    var dotCls = norms.map(dotSignClass);
    var resumo = nomes.map(function (nome, i) { return nome + ' ' + radarPct(norms[i]); }).join(', ');
    return '<div class="ng-radar" role="img" aria-label="Gráfico de perfil: ' + esc(resumo) + '">' +
        radarEixo(0, nomes[0], norms[0], dotCls[0]) +
        radarEixo(2, nomes[2], norms[2], dotCls[2]) +
        radarSVG(norms, dotCls) +
        radarEixo(1, nomes[1], norms[1], dotCls[1]) +
      '</div>';
  }

  /* ── Lição e desfecho num bloco contínuo ───────────────
     Um parágrafo só, no formato [lição] + " " + [desfecho], SEM rótulo: desde
     16/10 a lição abre o texto, e o "Lição: " que o JS punha antes dela saiu.
     Nenhuma quebra de linha: versões anteriores usaram \n\n e depois \n, e as
     duas cortavam a leitura em dois blocos visuais.

     A divisão é por CAMPO, não por texto: `licao` e `desfecho` vêm separados do
     banco (veredicto), então nada aqui procura "Lição:" dentro do texto. Cada
     parte entra só se existir, aparada, e é unida por um espaço só. */
  function textoDesfecho(verd) {
    var partes = [];
    if (verd.licao)    { partes.push(String(verd.licao).trim()); }
    if (verd.desfecho) { partes.push(String(verd.desfecho).trim()); }
    return partes.filter(Boolean).join(' ');
  }

  /* ── Card explicativo sobreposto ───────────────────────
     UM elemento de texto: o bloco contínuo de lição + desfecho.

     A consequência da escolha (`option_x.consequencia`), que era o título em
     destaque do card, saiu por decisão de UX de 16/10. Com ela saiu o único texto
     do card que dependia da alternativa escolhida: lição e desfecho são do
     DILEMA, iguais para qualquer escolha. O campo segue gerado e gravado.

     A linha "Sua escolha" saiu por decisão de UX de 29/08 — poluía o card, e a
     opção escolhida continua visível atrás do vidro. Os badges de impacto nos
     eixos saíram na mesma linha de raciocínio: o peso da escolha reaparece
     somado no radar do fim, e no card eles competiam com o texto sem acrescentar
     decisão ao jogador. */
  function buildFeedback(verd) {
    var desfecho = textoDesfecho(verd);
    return '<div class="ng-feedback">' +
        '<div class="ng-feedback-inner">' +
          (desfecho
            ? '<p class="ng-feedback-text">' + escNl(desfecho) + '</p>'
            : '') +
          '<p class="ng-feedback-hint"></p>' +
          '<button type="button" class="ng-btn-next"></button>' +
        '</div>' +
      '</div>';
  }

  /* ── Sorteio da partida ──────────────────────────────── */

  /* Perguntas por partida (`round_size`), limitadas a 4..15 — a mesma faixa
     que o formulário grava. É defesa: valor ausente ou fora da faixa no JSON
     não pode virar partida de 0 ou de 120 perguntas. Ausente cai em 10, o
     padrão da coluna. */
  function limitarPartida(valor) {
    var n = parseInt(valor, 10);
    if (isNaN(n)) { return 10; }
    return Math.min(15, Math.max(4, n));
  }

  /* Fisher-Yates sobre uma CÓPIA: o conjunto recebido do shortcode nunca é
     reordenado, então cada sorteio parte da mesma lista. */
  function embaralhar(lista) {
    var copia = lista.slice();
    for (var i = copia.length - 1; i > 0; i--) {
      var j   = Math.floor(Math.random() * (i + 1));
      var tmp = copia[i];
      copia[i] = copia[j];
      copia[j] = tmp;
    }
    return copia;
  }

  /* ── Medição (GA4) ───────────────────────────────────────
     O plugin NÃO carrega script externo nem tem ID de GA4 próprio: usa o
     `gtag` do site, ou o `dataLayer` do GTM, se a página tiver um deles; sem
     nenhum, não faz nada. Consentimento e propriedade ficam com o veículo.
     O try/catch garante que a medição nunca quebra o jogo. */
  function track(nome, params) {
    try {
      if (typeof window.gtag === 'function') {
        window.gtag('event', nome, params);
      } else if (Array.isArray(window.dataLayer)) {
        window.dataLayer.push(Object.assign({ event: nome }, params));
      }
    } catch (e) { /* medição falhou: o jogo segue */ }
  }

  /* ── Compartilhar ─────────────────────────────────────────
     Como no JDA (jogo-amazonia.js, "Compartilhar"): o menu nativo do aparelho
     (`navigator.share`) quando existe; sem ele, a cópia do link. A URL é a da
     página do jogo sem parâmetros de rastreio e sem âncora. */
  var PARAMS_RASTREIO = /^(utm_[a-z_]+|fbclid|gclid|gbraid|wbraid|dclid|msclkid|mc_cid|mc_eid|igshid|_ga|_gl)$/i;

  function urlCompartilhavel() {
    var u = new URL(window.location.href);
    Array.from(u.searchParams.keys()).forEach(function (k) {
      if (PARAMS_RASTREIO.test(k)) { u.searchParams.delete(k); }
    });
    u.hash = '';
    return u.href;
  }

  /* A cópia de sempre, com a mesma confirmação no botão. */
  function copiarLink(btn, url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () {
        btn.textContent = '✓ Link copiado!';
      });
    }
  }

  /* Cancelar o menu (AbortError) não faz nada — quem cancelou não pediu cópia.
     Qualquer outro erro cai na cópia do link. */
  function compartilhar(btn, titulo, perfil) {
    var url   = urlCompartilhavel();
    var dados = { title: titulo, text: perfil ? titulo + ' — ' + perfil : titulo, url: url };
    if (typeof navigator.share !== 'function' ||
        (typeof navigator.canShare === 'function' && !navigator.canShare(dados))) {
      copiarLink(btn, url);
      return;
    }
    navigator.share(dados).catch(function (e) {
      if (e && e.name === 'AbortError') { return; }
      copiarLink(btn, url);
    });
  }

  /* ── Instância do jogo ───────────────────────────────── */
  function NgGame(wrap) {
    var gid     = wrap.id.replace('ng-game-', '');
    var el      = document.getElementById('ng-game-data-' + gid);
    var inner   = wrap.querySelector('.ng-game-inner');
    var data    = null;

    /* bloco <script type="application/json"> emitido pelo shortcode (FLAG 007) */
    try {
      data = el ? JSON.parse(el.textContent) : null;
    } catch (e) {
      data = null;   // JSON corrompido cai no mesmo aviso de "nenhum dilema"
    }

    if (!data || !data.dilemas || !data.dilemas.length) {
      inner.innerHTML = '<p class="ng-empty-msg">Nenhum dilema disponível para esta instância.</p>';
      return;
    }

    /*
     * O shortcode entrega o CONJUNTO das aprovadas; a partida é um sorteio de
     * `tamPartida` delas, em ordem embaralhada, refeito no início de cada
     * partida (ver iniciarPartida()). Com menos aprovadas que o N, a partida
     * usa todas. `dilemas` é sempre a partida corrente — barra, fim e fundo
     * leem dele, e não do conjunto.
     */
    var conjunto   = data.dilemas;
    var receita    = data.receita;   // ausente ou null = sorteio de antes
    var tamPartida = tamanhoDaPartida();
    var dilemas    = [];
    /* Ordem das alternativas de cada pergunta da partida, sorteada junto com
       ela em iniciarPartida() e reusada pela tela de contexto — ordens[i] vale
       para dilemas[i]. */
    var ordens     = [];
    var title    = data.title || 'News-Game';
    var idx      = -1;   // -1 = tela inicial
    var creditoEl = wrap.querySelector('.ng-photo-credit');

    /*
     * ── Fundo: função ÚNICA de decisão ────────────────────────
     * Três degraus, cada um caindo para o de baixo, e nenhum estado fica sem
     * fundo:
     *   1. imagem do dilema — dado no banco, escolhido pelo editor. Vai por
     *      CSSOM, e estilo inline vence qualquer folha: é o que faz este degrau
     *      ganhar do 2 sem precisar de `!important`.
     *   2. fundo da skin para o momento do jogo (capa/dilema/resultado) —
     *      IMPLEMENTADO em 29/08, estreado pela skin `pixel`. O JS NÃO CONHECE
     *      IMAGEM NENHUMA aqui: ele só marca o wrap com a classe do momento, e
     *      a folha da skin decide o arquivo. Skin sem fundo declarado não é
     *      afetada — a classe fica sem regra que a case, e o degrau 3 aparece.
     *   3. `--ng-bg`, a cor sólida que o CSS já aplica no wrap. Não precisa de
     *      código: basta não haver imagem inline nem regra de skin.
     *
     * O DEGRAU 2 NÃO TOCOU NENHUM CHAMADOR, que era a promessa registrada no
     * contrato de skins (regra 6 do GEMINI.md) e valia a pena manter. Foi
     * possível porque o momento se LÊ de `idx`, a variável de estado que já
     * vive neste closure — ver momentoDoFundo(). Um parâmetro novo teria
     * obrigado os quatro chamadores a declarar o momento, e a divergir do
     * estado real na primeira distração.
     *
     * A atribuição é `wrap.style.backgroundImage`, propriedade via CSSOM —
     * NÃO é bloqueada por `style-src`. Nunca trocar por `setAttribute('style',
     * …)` nem por `style.cssText`: as duas são bloqueadas, e transformariam
     * isto em mais um item do A5. `classList` também não é alcançada por
     * `style-src` — classe é markup, não estilo inline. Tudo que é estático
     * (`background-size`, `position`, `repeat`, cor de base, véu) vive no CSS.
     */
    var CLASSES_MOMENTO = ['ng-bg-capa', 'ng-bg-dilema', 'ng-bg-fim'];

    /*
     * Momento do jogo, LIDO do estado em vez de recebido por parâmetro. `idx` é
     * -1 na capa (é como nasce, acima), 0..n-1 durante os dilemas, e chega a
     * `dilemas.length` quando avancar() estoura o último e chama renderEnd() —
     * por isso os três momentos saem daqui sem ambiguidade.
     *
     * O FEEDBACK COMPARTILHA O MOMENTO DO DILEMA de propósito: é a mesma tela
     * com o card sobreposto, não uma tela nova, e trocar o fundo no meio
     * quebraria a transição herdada do JDA.
     */
    function momentoDoFundo() {
      if (idx < 0) return 'ng-bg-capa';
      return idx >= dilemas.length ? 'ng-bg-fim' : 'ng-bg-dilema';
    }

    /* Exatamente uma classe de momento no wrap, sempre. `toggle` com segundo
       argumento é declarativo e idempotente: reaplicar não acumula estado, o
       que importa porque aplicarFundo() é chamada de novo no feedback. */
    function marcarMomento() {
      var atual = momentoDoFundo();
      CLASSES_MOMENTO.forEach(function (cls) {
        wrap.classList.toggle(cls, cls === atual);
      });
    }

    function aplicarFundo(imagem) {
      marcarMomento();   /* degrau 2: quem escolhe a arte é a folha da skin */

      if (imagem && imagem.url) {
        var veu = 'linear-gradient(var(--ng-overlay), var(--ng-overlay))';
        wrap.style.backgroundImage = veu + ", url('" + cssUrlSafe(imagem.url) + "')";
        if (creditoEl) {
          creditoEl.textContent = imagem.credito ? 'Foto: ' + imagem.credito : '';
          creditoEl.hidden      = !imagem.credito;
        }
        return;
      }

      /* Limpar o inline REVELA o degrau 2 quando a skin tem fundo para este
         momento, e o degrau 3 quando não tem. É por isso que não existe um
         `else` com URL de skin aqui: a cascata do CSS já é o mecanismo, e
         reimplementá-la em JS obrigaria o JS a conhecer os arquivos de cada
         skin. O crédito é limpo porque arte de skin não tem fotógrafo. */
      wrap.style.backgroundImage = '';
      if (creditoEl) {
        creditoEl.textContent = '';
        creditoEl.hidden      = true;
      }
    }

    var numOptions = data.num_options || 2;
    var optKeys    = ['a', 'b', 'c'].slice(0, numOptions);

    /* acumulador do vetor de eixos — resetado a cada jogo */
    var vetor    = {};   // { eixo1: N, eixo2: N, eixo3: N }
    var pesoCount = 0;   // quantos dilemas tinham axis_weights

    /* Parâmetros de medição. A skin sai da classe do wrap, que o shortcode
       monta; o contador de partidas é por carregamento da página e sobe a cada
       iniciarPartida(), "Jogar novamente" incluído. Nenhum dado do jogador. */
    var skinSlug = (wrap.className.match(/(?:^|\s)ng-skin-([a-z0-9_-]+)/) || [])[1] || '';
    var partidaN = 0;

    function medir(nome, extra) {
      track(nome, Object.assign({ instancia: gid, skin: skinSlug, partida_n: partidaN }, extra));
    }

    function paramsPergunta() {
      var d = dilemas[idx];
      return {
        etapa:         idx + 1,
        total:         dilemas.length,
        dilema_id:     d.id,
        dilema_titulo: String(d.title || '').slice(0, 100)
      };
    }

    /* `alternativa` é a letra interna; `posicao` é o lugar na tela (1 = a de
       cima), que difere dela desde o embaralhamento. Os pesos só entram se a
       escolha tiver `axis_weights`. */
    function paramsResposta(pick, pesos) {
      var p = paramsPergunta();
      p.alternativa = pick;
      p.posicao     = (ordens[idx] || optKeys).indexOf(pick) + 1;
      ['eixo1', 'eixo2', 'eixo3'].forEach(function (eixo, i) {
        if (pesos && typeof pesos[eixo] === 'number') { p['peso_' + (i + 1)] = pesos[eixo]; }
      });
      return p;
    }

    var lockTimer  = null;   /* intervalo do contador de leitura */
    var neonTimer  = null;   /* espera até a luz do cartão começar */
    var escHandler = null;   /* listener de Esc do modal aberto */

    /*
     * Toda troca de tela passa por aqui, ANTES do innerHTML. Um intervalo que
     * sobrevive ao render seguinte fica mexendo em nó já destacado do
     * documento, e um listener de Esc órfão fecha um modal que não existe mais.
     */
    function limparTimers() {
      if (lockTimer) { clearInterval(lockTimer); lockTimer = null; }
      if (neonTimer) { clearTimeout(neonTimer); neonTimer = null; }
      if (escHandler) {
        document.removeEventListener('keydown', escHandler);
        escHandler = null;
      }
    }

    function fecharInfo(modal, btn) {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      if (btn) { btn.setAttribute('aria-expanded', 'false'); btn.focus(); }
      if (escHandler) {
        document.removeEventListener('keydown', escHandler);
        escHandler = null;
      }
    }

    function abrirInfo(modal, btn) {
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      btn.setAttribute('aria-expanded', 'true');
      var fechar = modal.querySelector('.ng-modal-close');
      if (fechar) { fechar.focus(); }
      escHandler = function (ev) {
        if (ev.key === 'Escape') { fecharInfo(modal, btn); }
      };
      document.addEventListener('keydown', escHandler);
    }

    /* Liga o (i): botão, × e backdrop. No-op quando o dilema não tem contexto. */
    function ligarInfo() {
      var btn   = inner.querySelector('.ng-btn-info');
      var modal = inner.querySelector('.ng-modal');
      if (!btn || !modal) { return; }
      btn.addEventListener('click', function () { abrirInfo(modal, btn); });
      modal.querySelector('.ng-modal-close').addEventListener('click', function () {
        fecharInfo(modal, btn);
      });
      modal.querySelector('.ng-modal-backdrop').addEventListener('click', function () {
        fecharInfo(modal, btn);
      });
    }

    /* Trava de leitura: o botão nasce VISÍVEL e desabilitado, com regressivo. */
    function iniciarLock(btn, hint, labelLiberado) {
      var restante = LOCK_SEGUNDOS;
      btn.disabled     = true;
      btn.textContent  = 'Continuar em ' + restante + 's';
      hint.textContent = 'Leia antes de avançar';

      lockTimer = setInterval(function () {
        restante--;
        if (restante > 0) {
          btn.textContent = 'Continuar em ' + restante + 's';
          return;
        }
        clearInterval(lockTimer);
        lockTimer        = null;
        btn.disabled     = false;
        btn.textContent  = labelLiberado;
        hint.textContent = '';
        btn.classList.add('is-released');
      }, 1000);
    }

    function acumularPesos(pesos) {
      if (!pesos) { return; }
      Object.keys(pesos).forEach(function (eixo) {
        vetor[eixo] = (vetor[eixo] || 0) + pesos[eixo];
      });
      pesoCount++;
    }

    function avancar() {
      limparTimers();
      idx++;
      if (idx < dilemas.length) { renderDilema(); } else { renderEnd(); }
      wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* ── Receita da partida (item 58) ──────────────────────────
       Com `receita`, o shortcode já mandou só as gerais e os personagens
       Prontos: a partida é `receita.gerais` gerais + `receita.por_personagem`
       de UM personagem Pronto, sorteado a cada partida — é onde a tela de
       personagem entrará. Sem personagem Pronto, só gerais. Sem `receita`
       (gerais insuficientes), o sorteio de antes, sobre o conjunto inteiro. */
    function doGrupo(slug) {
      return conjunto.filter(function (d) { return (d.grupo || 'geral') === slug; });
    }

    function tamanhoDaPartida() {
      var n = limitarPartida(data.round_size);
      if (!receita) { return Math.min(n, conjunto.length); }
      if (receita.personagens && receita.personagens.length) {
        return receita.gerais + receita.por_personagem;
      }
      return Math.min(n, doGrupo('geral').length);
    }

    function sortearPartida() {
      if (!receita) { return embaralhar(conjunto).slice(0, tamPartida); }
      var gerais = embaralhar(doGrupo('geral'));
      var prontos = receita.personagens || [];
      if (!prontos.length) { return gerais.slice(0, tamPartida); }
      var slug = prontos[Math.floor(Math.random() * prontos.length)];
      return embaralhar(gerais.slice(0, receita.gerais)
        .concat(embaralhar(doGrupo(slug)).slice(0, receita.por_personagem)));
    }

    /* Começo de partida — pela capa e por "Jogar novamente". Zera o acumulador
       do veredito e sorteia de novo, sem recarregar a página. */
    /* A ordem das alternativas é sorteada aqui, uma vez por pergunta, e não a
       cada render: a tela de contexto remonta o palco e tem de mostrar a mesma
       ordem da pergunta. "Jogar novamente" passa por aqui e sorteia tudo de novo. */
    function iniciarPartida() {
      idx = 0; vetor = {}; pesoCount = 0;
      dilemas = sortearPartida();
      ordens  = dilemas.map(function () { return embaralhar(optKeys); });
      partidaN++;
      medir('ng_inicio', { n: dilemas.length, conjunto: conjunto.length });
      renderDilema();
    }

    function jogarNovamente() {
      iniciarPartida();
      wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* Botão de tela cheia. Anexado ao WRAP, e não ao inner: o inner é reescrito
       por innerHTML a cada tela, e o botão morreria com o listener a cada
       avanço. Não é renderizado quando o navegador não suporta tela cheia de
       elemento — é o caso do Safari no iPhone —, pelo mesmo critério do botão
       (i): controle que não pode funcionar não vai para a tela. */
    function montarTelaCheia() {
      if (!fullscreenSuportado()) {
        /* Condição invisível até 29/08: sem este aviso, "o botão não apareceu" e
           "o botão não foi criado" são indistinguíveis na tela. Acontece em
           iframe sem allow="fullscreen", e no Safari do iPhone. */
        console.info('[news-game] tela cheia indisponível neste contexto — botão não montado');
        return;
      }
      /* Idempotente, para poder ser chamada de qualquer render: monta uma vez
         só. O botão é anexado ao WRAP e já sobrevive à troca de telas, que
         reescreve apenas o inner — esta guarda é rede para o dia em que alguém
         reescrever o wrap, e é o que permite que as cinco telas chamem sem
         medo. */
      if (wrap.querySelector('.ng-btn-fullscreen')) { return; }

      var btn = document.createElement('button');
      btn.type      = 'button';
      btn.className = 'ng-btn-fullscreen';
      wrap.appendChild(btn);

      function pintar() {
        var dentro = elementoEmTelaCheia() === wrap;
        var rotulo = dentro ? 'Sair da tela cheia' : 'Jogar em tela cheia';
        btn.innerHTML = iconeTelaCheia(dentro);
        btn.title     = rotulo;
        btn.setAttribute('aria-label', rotulo);
        btn.setAttribute('aria-pressed', dentro ? 'true' : 'false');
      }

      btn.addEventListener('click', function () {
        if (elementoEmTelaCheia() === wrap) { sairTelaCheia(); return; }
        entrarTelaCheia(wrap);
      });

      /* Quem pinta o ícone é o EVENTO, não o clique: o estado muda sem passar
         pelo botão quando o jogador usa Esc ou F11. Com duas instâncias na mesma
         página, cada uma escuta e pinta o seu próprio botão. */
      document.addEventListener('fullscreenchange', pintar);
      document.addEventListener('webkitfullscreenchange', pintar);
      pintar();
    }

    /* ── Tela inicial ── */
    function renderStart() {
      limparTimers();
      montarTelaCheia();
      aplicarFundo(null);
      inner.innerHTML =
        '<div class="ng-screen">' +
          '<h2>' + esc(title) + '</h2>' +
          '<p>' + tamPartida + ' perguntas para você responder.</p>' +
          '<button class="ng-btn-cta">Iniciar jogo ›</button>' +
        '</div>';
      inner.querySelector('.ng-btn-cta').addEventListener('click', iniciarPartida);
    }

    /* Alternativas do palco. Sem `pick`, são os botões da pergunta; com `pick`,
       a escolhida e as outras, desabilitadas, da tela de contexto.

       A ORDEM é a sorteada para esta pergunta (`ordens[idx]`); a letra interna
       segue no `data-pick` e decide pesos, escolhida e veredito — nunca a
       posição. O rótulo "Opção A/B/C" é só para leitor de tela (o CSS o oculta)
       e vem da POSIÇÃO na tela, A é a de cima. */
    function buildOpcoes(d, pick) {
      return (ordens[idx] || optKeys).map(function (key, i) {
        var opt  = d['option_' + key] || {};
        var abre = pick
          ? '<button class="ng-btn-option ' + (key === pick ? 'is-chosen' : 'is-other') + '" disabled>'
          : '<button class="ng-btn-option" data-pick="' + key + '">';
        return abre +
          '<span class="ng-opt-label">Opção ' + 'ABC'.charAt(i) + '</span>' +
          esc(opt.texto || '') +
        '</button>';
      }).join('');
    }

    /* ── Palco: construtor ÚNICO das telas de pergunta e de contexto ──
       CARTÃO ÚNICO desde 16/10, como o `.glass-card` do JDA: pergunta,
       alternativas e painel de contexto moram DENTRO do `.ng-card`. O corpo
       (título, pergunta e o (i)) fica num bloco próprio, `.ng-card-corpo`, que
       é a âncora do (i) — sem ele o botão iria para o pé do cartão, abaixo das
       alternativas. O painel (`painel`) é absoluto dentro do cartão e cobre só
       ele. A tela de contexto troca o (i) pelas alternativas desabilitadas.
       O modal do (i) NÃO entra aqui: fica fora do palco, irmão dele no
       `.ng-game-inner` — ver renderDilema(). */
    function buildPalco(d, pick, temInfo, painel) {
      return '<div class="ng-stage' + (pick ? ' is-feedback' : '') + '">' +
          '<div class="ng-card">' +
            '<div class="ng-card-corpo">' +
              '<h2 class="ng-dilema-title">' + esc(d.title) + '</h2>' +
              '<p class="ng-dilema-body">' + escNl(d.body) + '</p>' +
              (pick ? '' : buildInfoBtn(temInfo)) +
            '</div>' +
            '<div class="ng-options">' + buildOpcoes(d, pick) + '</div>' +
            (painel || '') +
          '</div>' +
        '</div>';
    }

    /* ── Tela de dilema ── */
    function renderDilema() {
      limparTimers();
      montarTelaCheia();
      var d = dilemas[idx];

      aplicarFundo(d.imagem);

      /* Contexto e fonte saíram da tela de feedback e agora vivem no modal do
         (i) — se o dilema não tem nenhum dos dois, o botão nem é renderizado. */
      var verd    = d.veredicto || {};
      var sintese = verd.sintese || '';
      var temInfo = !!(sintese || d.source_url);

      /* O modal é `position: fixed` e fica FORA do palco: elemento com
         `backdrop-filter`, `transform` ou `filter` vira o bloco de contenção dos
         descendentes fixos, e o modal ficaria preso dentro do cartão em vez de
         cobrir a tela. O `.ng-game-inner` e os ancestrais dele não têm nenhum
         desses (conferido em 16/10, nas 7 skins e em tela cheia). */
      inner.innerHTML =
        buildProgress(idx, idx + 1, dilemas.length) +
        buildPalco(d, null, temInfo, '') +
        (temInfo ? buildInfoModal(sintese, d.source_url) : '');

      aplicarProgresso(inner);
      ligarInfo();
      agendarNeon();
      inner.querySelectorAll('.ng-btn-option').forEach(function (btn) {
        btn.addEventListener('click', function () { renderFeedback(btn.dataset.pick); });
      });
      /* Uma vez por exibição: renderDilema() só é chamada ao começar a partida
         e ao avançar — não há render por resize, foco ou teclado. */
      medir('ng_pergunta_vista', paramsPergunta());
    }

    /* A luz do cartão começa NEON_ESPERA_MS depois de a pergunta entrar e vale
       só enquanto a pergunta espera: escolher leva ao renderFeedback(), cujo
       limparTimers() cancela a espera, e o palco remontado nasce sem a classe.
       Por isso a luz para ao escolher e recomeça do zero na pergunta seguinte e
       em "Jogar novamente" — ao contrário do JDA, que a mantém na tela de
       contexto (decisão de 16/10: a tela de contexto é de leitura). */
    function agendarNeon() {
      if (!NEON_SUPORTADO) { return; }
      neonTimer = setTimeout(function () {
        neonTimer = null;
        var card = inner.querySelector('.ng-stage:not(.is-feedback) .ng-card');
        if (card) { card.classList.add('ng-neon'); }
      }, NEON_ESPERA_MS);
    }

    /* ── Tela de feedback ──────────────────────────────────
       O dilema e as opções continuam ATRÁS, desfocados pelo vidro, e o card
       explicativo entra sobreposto. É a transição do JDA, não uma troca de
       tela — e é por isso que o palco sai do mesmo buildPalco() da pergunta. */
    function renderFeedback(pick) {
      limparTimers();
      montarTelaCheia();
      var d       = dilemas[idx];
      var verd    = d.veredicto || {};
      var pesos   = d.axis_weights && d.axis_weights[pick.toUpperCase()];
      var nextLbl = idx + 1 < dilemas.length ? 'Próxima pergunta →' : 'Ver resultado final';

      // Mesmo dilema, mesmo fundo: reaplicar mantém a imagem no feedback, e
      // evita depender de o render anterior ter deixado o estado certo.
      aplicarFundo(d.imagem);
      acumularPesos(pesos);
      medir('ng_resposta', paramsResposta(pick, pesos));

      inner.innerHTML =
        buildProgress(idx + 1, idx + 1, dilemas.length) +
        buildPalco(d, pick, false, buildFeedback(verd));

      aplicarProgresso(inner);
      var feedback = inner.querySelector('.ng-feedback');
      var btn      = inner.querySelector('.ng-btn-next');
      var hint     = inner.querySelector('.ng-feedback-hint');

      /* Dois frames: um para o nó entrar no documento com opacity 0, outro para
         a classe mudar — sem isso a transição não dispara. Padrão do JDA. */
      requestAnimationFrame(function () {
        requestAnimationFrame(function () { feedback.classList.add('is-visible'); });
      });

      iniciarLock(btn, hint, nextLbl);
      btn.addEventListener('click', avancar);
    }

    /* ── Tela final: decide entre veredito narrativo e tela genérica ── */
    function renderEnd() {
      limparTimers();
      montarTelaCheia();
      aplicarFundo(null);
      var temVerdict = data.perfis && data.eixos && pesoCount > 0;

      if (!temVerdict) {
        renderEndGenerico();
        return;
      }

      /* Normaliza o vetor acumulado para o intervalo [-1, 1] */
      var vetorNorm = {};
      Object.keys(vetor).forEach(function (eixo) {
        vetorNorm[eixo] = Math.max(-1, Math.min(1, vetor[eixo] / (pesoCount * 20)));
      });

      /* Seleciona o perfil mais próximo por distância euclidiana */
      var melhor    = null;
      var menorDist = Infinity;
      data.perfis.forEach(function (p) {
        var vap  = p.vetor_aproximado || {};
        var soma = 0;
        ['eixo1', 'eixo2', 'eixo3'].forEach(function (eixo) {
          var diff = (vetorNorm[eixo] || 0) - (vap[eixo] || 0);
          soma += diff * diff;
        });
        var dist = Math.sqrt(soma);
        if (dist < menorDist) { menorDist = dist; melhor = p; }
      });

      if (!melhor) {
        renderEndGenerico();
        return;
      }

      renderVerdictNarrativa(melhor, vetorNorm);
    }

    /* ── Veredito: radar, nome do perfil e síntese ─────────
       O perfil continua sendo escolhido localmente por distância euclidiana em
       renderEnd(); o que saiu daqui foi a narrativa por eixo. */
    function renderVerdictNarrativa(perfil, vetorNorm) {
      var radar = (vetorNorm && data.eixos) ? buildRadar(vetorNorm, data.eixos) : '';
      medir('ng_fim', { tela: 'veredito', perfil: String(perfil.nome || '').slice(0, 100) });

      inner.innerHTML =
        '<div class="ng-verdict-result">' +
          (radar ? '<div class="ng-radar-wrap">' + radar + '</div>' : '') +
          '<h2>' + esc(perfil.nome) + '</h2>' +
          /*
           * Síntese do perfil — desde 29/08 é o ÚNICO texto do veredito.
           *
           * Campo obrigatório do contrato (ProfileSuggestion.descricao,
           * models.py:198), que até 28/08 nunca havia chegado à tela. O ternário
           * cobre a instância cuja matriz veio de versão anterior e talvez não
           * tenha a chave — nesse caso o veredito fica com radar e nome.
           *
           * ⚠️ Ao remover os blocos de "Sua força" e "Ponto cego" (decisão de
           * 29/08), quatro campos do contrato passaram a ser gerados e nunca
           * exibidos: forca_dominante, ponto_cego, polo_positivo e
           * polo_negativo. A personalização do veredito vive agora só no radar,
           * porque o `descricao` é do PERFIL e não das escolhas do jogador.
           */
          (perfil.descricao
            ? '<p class="ng-verdict-summary">' + escNl(perfil.descricao) + '</p>'
            : '') +
          '<p class="ng-verdict-share-text">Compartilhe seu resultado e descubra o perfil dos seus amigos!</p>' +
          '<button class="ng-btn-cta ng-btn-share">Compartilhar</button>' +
          '<button class="ng-btn-cta ng-btn-restart">Jogar novamente</button>' +
        '</div>';

      inner.querySelector('.ng-btn-share').addEventListener('click', function () {
        compartilhar(this, title, perfil.nome);
      });

      inner.querySelector('.ng-btn-restart').addEventListener('click', jogarNovamente);
    }

    /* ── Tela genérica de conclusão (fallback) ── */
    function renderEndGenerico() {
      limparTimers();
      montarTelaCheia();
      medir('ng_fim', { tela: 'generica' });
      inner.innerHTML =
        '<div class="ng-screen">' +
          '<h2>Jogo concluído!</h2>' +
          '<p>' +
            'Você respondeu todas as ' + dilemas.length + ' perguntas de <em>' + esc(title) + '</em>.' +
          '</p>' +
          '<button class="ng-btn-cta">Jogar novamente</button>' +
        '</div>';
      inner.querySelector('.ng-btn-cta').addEventListener('click', jogarNovamente);
    }

    renderStart();
  }

  /* ── Bootstrap: inicializa todos os containers da página ── */
  function boot() {
    document.querySelectorAll('.ng-game-wrap').forEach(function (el) {
      NgGame(el);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
