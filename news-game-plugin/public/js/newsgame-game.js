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

  /* ── Monta uma seção do veredito ─────────────────────── */
  function vSection(label, text) {
    if (!text) return '';
    return (
      '<div>' +
        '<span class="ng-verd-label">' + esc(label) + '</span>' +
        '<p class="ng-verd-text">' + escNl(text) + '</p>' +
      '</div>'
    );
  }

  /* ── Instância do jogo ───────────────────────────────── */
  function NgGame(wrap) {
    var gid     = wrap.id.replace('ng-game-', '');
    var data    = window['_ngGame_' + gid];
    var inner   = wrap.querySelector('.ng-game-inner');

    if (!data || !data.dilemas || !data.dilemas.length) {
      inner.innerHTML = '<p style="padding:2rem;color:#f88">Nenhum dilema disponível para esta instância.</p>';
      return;
    }

    var dilemas  = data.dilemas;
    var title    = data.title || 'News-Game';
    var idx      = -1;   // -1 = tela inicial

    var numOptions = data.num_options || 2;
    var optKeys    = ['a', 'b', 'c'].slice(0, numOptions);

    /* ── Tela inicial ── */
    function renderStart() {
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
      var d   = dilemas[idx];
      var pct = Math.round(idx / dilemas.length * 100);

      var optsHtml = optKeys.map(function (key) {
        var opt = d['option_' + key] || {};
        return '<button class="ng-btn-option" data-pick="' + key + '">' +
          '<span class="ng-opt-label">Opção ' + key.toUpperCase() + '</span>' +
          esc(opt.texto || '') +
        '</button>';
      }).join('');

      inner.innerHTML =
        '<div class="ng-progress">' +
          '<div class="ng-progress-track">' +
            '<div class="ng-progress-fill" style="width:' + pct + '%"></div>' +
          '</div>' +
          '<span class="ng-progress-label">' + (idx + 1) + ' / ' + dilemas.length + '</span>' +
        '</div>' +
        '<div class="ng-card">' +
          '<h2 class="ng-dilema-title">' + esc(d.title) + '</h2>' +
          '<p class="ng-dilema-body">' + escNl(d.body) + '</p>' +
        '</div>' +
        '<div class="ng-options">' + optsHtml + '</div>';

      inner.querySelectorAll('.ng-btn-option').forEach(function (btn) {
        btn.addEventListener('click', function () { renderVeredicto(btn.dataset.pick); });
      });
    }

    /* ── Tela de veredito ── */
    function renderVeredicto(pick) {
      var d       = dilemas[idx];
      var pct     = Math.round((idx + 1) / dilemas.length * 100);
      var nextLbl = idx + 1 < dilemas.length ? 'Próxima pergunta →' : 'Ver resultado final';
      var verd    = d.veredicto || {};

      // Renderiza todas as opções: escolhida com consequencia, demais sem
      var optsHtml = optKeys.map(function (key) {
        var opt      = d['option_' + key] || {};
        var isChosen = key === pick;
        var label    = isChosen
          ? 'Opção ' + key.toUpperCase() + ' — sua escolha'
          : 'Opção ' + key.toUpperCase();
        var cls      = isChosen ? 'ng-btn-option is-chosen' : 'ng-btn-option is-other';
        return '<button class="' + cls + '" disabled>' +
          '<span class="ng-opt-label">' + label + '</span>' +
          esc(opt.texto || '') +
          (isChosen && opt.consequencia
            ? '<span class="ng-opt-consequence">' + esc(opt.consequencia) + '</span>'
            : '') +
        '</button>';
      }).join('');

      inner.innerHTML =
        '<div class="ng-progress">' +
          '<div class="ng-progress-track">' +
            '<div class="ng-progress-fill" style="width:' + pct + '%"></div>' +
          '</div>' +
          '<span class="ng-progress-label">' + (idx + 1) + ' / ' + dilemas.length + '</span>' +
        '</div>' +
        '<div class="ng-card">' +
          '<h2 class="ng-dilema-title">' + esc(d.title) + '</h2>' +
        '</div>' +
        '<div class="ng-options">' + optsHtml + '</div>' +
        '<div class="ng-veredicto">' +
          vSection('O que de fato ocorreu', verd.desfecho) +
          vSection('Lição', verd.licao) +
          vSection('Contexto', verd.sintese) +
          (d.source_url
            ? '<div><span class="ng-verd-label">Fonte</span>' +
              '<a class="ng-source-link" href="' + esc(d.source_url) + '" target="_blank" rel="noopener">' +
              esc(d.source_url) + '</a></div>'
            : '') +
        '</div>' +
        '<button class="ng-btn-next">' + esc(nextLbl) + '</button>';

      inner.querySelector('.ng-btn-next').addEventListener('click', function () {
        idx++;
        if (idx < dilemas.length) {
          renderDilema();
        } else {
          renderEnd();
        }
        wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }

    /* ── Tela final ── */
    function renderEnd() {
      inner.innerHTML =
        '<div class="ng-screen">' +
          '<h2>Jogo concluído!</h2>' +
          '<p>' +
            'Você respondeu todas as ' + dilemas.length + ' perguntas de <em>' + esc(title) + '</em>.' +
          '</p>' +
          '<button class="ng-btn-cta">Jogar novamente</button>' +
        '</div>';
      inner.querySelector('.ng-btn-cta').addEventListener('click', function () {
        idx = 0;
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
