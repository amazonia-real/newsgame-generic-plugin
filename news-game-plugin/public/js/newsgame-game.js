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

  /* ── Barra de progresso ────────────────────────────────
     Extraída porque os dois estados montavam o mesmo HTML duplicado — e com
     isso os DOIS atributos de estilo de largura viram UM, o que baixa a
     contagem do A5 de 6 para 5 sem fechar nenhum dos 4 grupos (o A5 é decisão,
     não tarefa).

     A frase acima não escreve o nome do atributo de propósito: a auditoria do
     A5 é um `grep`, e citá-lo em comentário inflaria a contagem que ele mede.
     Mesma regra registrada no comentário do crédito da foto, no shortcode. */
  function buildProgress(preenchido, atual, total) {
    var pct = Math.round(preenchido / total * 100);
    return '<div class="ng-progress">' +
        '<div class="ng-progress-track">' +
          '<div class="ng-progress-fill" style="width:' + pct + '%"></div>' +
        '</div>' +
        '<span class="ng-progress-label">' + atual + ' / ' + total + '</span>' +
      '</div>';
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

  function buildRadarSVG(vetor, eixos) {
    var W = 480, H = 230, cx = 240, cy = 132, r = 83;

    var norms  = [vetor.eixo1 || 0, vetor.eixo2 || 0, vetor.eixo3 || 0];
    var pcts   = norms.map(function (n) { return Math.min(100, Math.max(-100, n * 100)); });
    var labels = [
      (eixos.eixo1 && eixos.eixo1.nome) || 'Eixo 1',
      (eixos.eixo2 && eixos.eixo2.nome) || 'Eixo 2',
      (eixos.eixo3 && eixos.eixo3.nome) || 'Eixo 3',
    ];
    // Topo → eixo1, direita-baixo → eixo2, esquerda-baixo → eixo3
    var angRad = [-90, 30, 150].map(function (a) { return a * Math.PI / 180; });

    // Pontos do polígono (valores negativos cruzam o centro — efeito fratura)
    var pts = norms.map(function (n, i) {
      return [
        +(cx + n * r * Math.cos(angRad[i])).toFixed(2),
        +(cy + n * r * Math.sin(angRad[i])).toFixed(2),
      ];
    });

    // Grid: triângulos concêntricos em 25/50/75/100% do raio (paleta escura)
    var gridSVG = [0.25, 0.5, 0.75, 1.0].map(function (lv) {
      var gPts = angRad.map(function (a) {
        return (cx + lv * r * Math.cos(a)).toFixed(2) + ',' +
               (cy + lv * r * Math.sin(a)).toFixed(2);
      }).join(' ');
      return '<polygon points="' + gPts + '" fill="none"' +
             ' class="' + (lv === 1.0 ? 'ng-radar-grid-outer' : 'ng-radar-grid') + '"' +
             ' stroke-width="' + (lv === 1.0 ? 1.3 : 0.7) + '"/>';
    }).join('');

    // Eixos do centro aos vértices
    var axesSVG = angRad.map(function (a) {
      return '<line x1="' + cx + '" y1="' + cy + '"' +
             ' x2="' + (cx + r * Math.cos(a)).toFixed(2) + '"' +
             ' y2="' + (cy + r * Math.sin(a)).toFixed(2) + '"' +
             ' class="ng-radar-axis" stroke-width="1"/>';
    }).join('');

    // Polígono de dados: a classe de sinal governa a cor, que vive no CSS
    var avgNorm = (norms[0] + norms[1] + norms[2]) / 3;
    var polyCls = polySignClass(avgNorm);
    var polyPts = pts.map(function (p) { return p.join(','); }).join(' ');

    // Círculos nos vértices, com classe de sinal própria por eixo
    var dotCls  = norms.map(dotSignClass);
    var dotsSVG = pts.map(function (pt, i) {
      return '<circle cx="' + pt[0] + '" cy="' + pt[1] + '" r="6"' +
             ' class="ng-radar-dot ' + dotCls[i] + '" stroke-width="2"/>';
    }).join('');

    // Rótulos: nome do eixo + percentual com sinal
    var labelOff  = 24;
    var labelsSVG = angRad.map(function (a, i) {
      var lx     = +(cx + (r + labelOff) * Math.cos(a)).toFixed(1);
      var ly     = +(cy + (r + labelOff) * Math.sin(a)).toFixed(1);
      var pctTxt = (pcts[i] >= 0 ? '+' : '') + Math.round(pcts[i]) + '%';
      var anchor = Math.cos(a) > 0.15 ? 'start' : Math.cos(a) < -0.15 ? 'end' : 'middle';
      return '<text x="' + lx + '" y="' + (ly - 4) + '"' +
               ' text-anchor="' + anchor + '" font-size="11" font-weight="700"' +
               ' letter-spacing="0.5" class="ng-radar-label">' +
               esc(labels[i].toUpperCase()) + '</text>' +
             '<text x="' + lx + '" y="' + (ly + 14) + '"' +
               ' text-anchor="' + anchor + '" font-size="17" font-weight="800"' +
               ' class="ng-radar-pct ' + dotCls[i] + '">' + pctTxt + '</text>';
    }).join('');

    return '<svg viewBox="0 0 ' + W + ' ' + H + '" width="100%" class="ng-radar"' +
           ' role="img" aria-label="Gráfico de perfil: ' + esc(labels.join(', ')) + '">' +
      '<g>' + gridSVG + '</g>' +
      '<g>' + axesSVG + '</g>' +
      '<polygon points="' + polyPts + '" class="ng-radar-poly ' + polyCls + '"' +
               ' stroke-width="2.5" stroke-linejoin="round"/>' +
      '<g>' + dotsSVG + '</g>' +
      '<circle cx="' + cx + '" cy="' + cy + '" r="3.5" class="ng-radar-center"/>' +
      '<g>' + labelsSVG + '</g>' +
    '</svg>';
  }

  /* ── Desfecho e lição num bloco contínuo ───────────────
     Eram duas seções rotuladas empilhadas (a antiga vSection(), que saiu junto
     por não ter mais nenhum chamador). Viram um parágrafo só, no formato
     [desfecho] + " Lição: " + [lição]. Nenhuma quebra de linha: as duas versões
     anteriores usaram \n\n e depois \n, e as duas ainda cortavam a leitura em
     dois blocos visuais. Agora é um parágrafo só, encadeado por espaço.

     Cada parte entra só se existir: dilema sem lição mostra apenas o desfecho,
     sem o rótulo "Lição:" pendurado, e vice-versa. */
  function textoDesfecho(verd) {
    var partes = [];
    if (verd.desfecho) { partes.push(String(verd.desfecho).trim()); }
    if (verd.licao)    { partes.push('Lição: ' + String(verd.licao).trim()); }
    return partes.join(' ');
  }

  /* ── Card explicativo sobreposto ───────────────────────
     DOIS elementos: a consequência da escolha em destaque e o bloco contínuo de
     desfecho + lição.

     A linha "Sua escolha" saiu por decisão de UX de 29/08 — poluía o card, a
     opção escolhida continua visível atrás do vidro, e o texto da consequência
     já a identifica. Os badges de impacto nos eixos saíram na mesma linha de
     raciocínio: o peso da escolha reaparece somado no radar do fim, e no card
     eles competiam com o texto sem acrescentar decisão ao jogador. */
  function buildFeedback(opt, verd) {
    var desfecho = textoDesfecho(verd);
    return '<div class="ng-feedback">' +
        '<div class="ng-feedback-inner">' +
          (opt.consequencia
            ? '<p class="ng-feedback-consequence">' + escNl(opt.consequencia) + '</p>'
            : '') +
          (desfecho
            ? '<p class="ng-feedback-text">' + escNl(desfecho) + '</p>'
            : '') +
          '<p class="ng-feedback-hint"></p>' +
          '<button type="button" class="ng-btn-next"></button>' +
        '</div>' +
      '</div>';
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
      inner.innerHTML = '<p style="padding:2rem;color:#f88">Nenhum dilema disponível para esta instância.</p>';
      return;
    }

    var dilemas  = data.dilemas;
    var title    = data.title || 'News-Game';
    var idx      = -1;   // -1 = tela inicial
    var creditoEl = wrap.querySelector('.ng-photo-credit');

    /*
     * ── Fundo: função ÚNICA de decisão ────────────────────────
     * Três degraus, cada um caindo para o de baixo, e nenhum estado fica sem
     * fundo:
     *   1. imagem do dilema — dado no banco, escolhido pelo editor
     *   2. fundo da skin para o momento do jogo (capa/dilema/resultado) — NÃO
     *      IMPLEMENTADO, é o degrau 2 do H2. O ponto de entrada é aqui, e é por
     *      isso que esta decisão vive numa função só: o degrau 2 entra neste
     *      `else` sem tocar em nenhum dos chamadores.
     *   3. `--ng-bg`, a cor sólida que o CSS já aplica no wrap. Não precisa de
     *      código: basta não haver imagem inline.
     *
     * A atribuição é `wrap.style.backgroundImage`, propriedade via CSSOM —
     * NÃO é bloqueada por `style-src`. Nunca trocar por `setAttribute('style',
     * …)` nem por `style.cssText`: as duas são bloqueadas, e transformariam
     * isto em mais um item do A5. Tudo que é estático (`background-size`,
     * `position`, `repeat`, cor de base, véu) vive no CSS.
     */
    function aplicarFundo(imagem) {
      if (imagem && imagem.url) {
        var veu = 'linear-gradient(var(--ng-overlay), var(--ng-overlay))';
        wrap.style.backgroundImage = veu + ", url('" + cssUrlSafe(imagem.url) + "')";
        if (creditoEl) {
          creditoEl.textContent = imagem.credito ? 'Foto: ' + imagem.credito : '';
          creditoEl.hidden      = !imagem.credito;
        }
        return;
      }

      wrap.style.backgroundImage = '';   // degrau 3: revela --ng-bg
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

    var lockTimer  = null;   /* intervalo do contador de leitura */
    var escHandler = null;   /* listener de Esc do modal aberto */

    /*
     * Toda troca de tela passa por aqui, ANTES do innerHTML. Um intervalo que
     * sobrevive ao render seguinte fica mexendo em nó já destacado do
     * documento, e um listener de Esc órfão fecha um modal que não existe mais.
     */
    function limparTimers() {
      if (lockTimer) { clearInterval(lockTimer); lockTimer = null; }
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
          '<p>' + dilemas.length + ' perguntas para você responder.</p>' +
          '<button class="ng-btn-cta">Iniciar jogo ›</button>' +
        '</div>';
      inner.querySelector('.ng-btn-cta')
        .addEventListener('click', function () { idx = 0; renderDilema(); });
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

      var optsHtml = optKeys.map(function (key) {
        var opt = d['option_' + key] || {};
        return '<button class="ng-btn-option" data-pick="' + key + '">' +
          '<span class="ng-opt-label">Opção ' + key.toUpperCase() + '</span>' +
          esc(opt.texto || '') +
        '</button>';
      }).join('');

      inner.innerHTML =
        buildProgress(idx, idx + 1, dilemas.length) +
        '<div class="ng-stage">' +
          '<div class="ng-card">' +
            '<h2 class="ng-dilema-title">' + esc(d.title) + '</h2>' +
            '<p class="ng-dilema-body">' + escNl(d.body) + '</p>' +
            buildInfoBtn(temInfo) +
          '</div>' +
          '<div class="ng-options">' + optsHtml + '</div>' +
          (temInfo ? buildInfoModal(sintese, d.source_url) : '') +
        '</div>';

      ligarInfo();
      inner.querySelectorAll('.ng-btn-option').forEach(function (btn) {
        btn.addEventListener('click', function () { renderFeedback(btn.dataset.pick); });
      });
    }

    /* ── Tela de feedback ──────────────────────────────────
       O dilema e as opções continuam ATRÁS, desfocados pelo vidro, e o card
       explicativo entra sobreposto. É a transição do JDA, não uma troca de
       tela — e é por isso que o markup do palco é o mesmo do renderDilema(). */
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

      var optsHtml = optKeys.map(function (key) {
        var opt = d['option_' + key] || {};
        var cls = key === pick ? 'ng-btn-option is-chosen' : 'ng-btn-option is-other';
        return '<button class="' + cls + '" disabled>' +
          '<span class="ng-opt-label">Opção ' + key.toUpperCase() + '</span>' +
          esc(opt.texto || '') +
        '</button>';
      }).join('');

      inner.innerHTML =
        buildProgress(idx + 1, idx + 1, dilemas.length) +
        '<div class="ng-stage is-feedback">' +
          '<div class="ng-card">' +
            '<h2 class="ng-dilema-title">' + esc(d.title) + '</h2>' +
            '<p class="ng-dilema-body">' + escNl(d.body) + '</p>' +
          '</div>' +
          '<div class="ng-options">' + optsHtml + '</div>' +
          buildFeedback(d['option_' + pick] || {}, verd) +
        '</div>';

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
      var radar = (vetorNorm && data.eixos) ? buildRadarSVG(vetorNorm, data.eixos) : '';

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
          '<button class="ng-btn-cta ng-btn-share" style="display:block;margin:0 auto 0.75rem">Compartilhar</button>' +
          '<button class="ng-btn-cta ng-btn-restart" style="display:block;margin:0 auto">Jogar novamente</button>' +
        '</div>';

      inner.querySelector('.ng-btn-share').addEventListener('click', function () {
        var btn = this;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(window.location.href).then(function () {
            btn.textContent = '✓ Link copiado!';
          });
        }
      });

      inner.querySelector('.ng-btn-restart').addEventListener('click', function () {
        idx = 0; vetor = {}; pesoCount = 0;
        renderDilema();
        wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }

    /* ── Tela genérica de conclusão (fallback) ── */
    function renderEndGenerico() {
      limparTimers();
      montarTelaCheia();
      inner.innerHTML =
        '<div class="ng-screen">' +
          '<h2>Jogo concluído!</h2>' +
          '<p>' +
            'Você respondeu todas as ' + dilemas.length + ' perguntas de <em>' + esc(title) + '</em>.' +
          '</p>' +
          '<button class="ng-btn-cta">Jogar novamente</button>' +
        '</div>';
      inner.querySelector('.ng-btn-cta').addEventListener('click', function () {
        idx = 0; vetor = {}; pesoCount = 0;
        renderDilema();
        wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
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
