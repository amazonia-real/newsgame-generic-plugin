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

    /* ── Tela inicial ── */
    function renderStart() {
      inner.innerHTML =
        '<div class="ng-screen">' +
          '<h2>' + esc(title) + '</h2>' +
          '<p>' + dilemas.length + ' dilemas para você responder.</p>' +
          '<button class="ng-btn-cta">Iniciar jogo ›</button>' +
        '</div>';
      inner.querySelector('.ng-btn-cta')
        .addEventListener('click', function () { idx = 0; renderDilema(); });
    }

    /* ── Tela de dilema ── */
    function renderDilema() {
      var d   = dilemas[idx];
      var pct = Math.round(idx / dilemas.length * 100);

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
        '<div class="ng-options">' +
          '<button class="ng-btn-option" data-pick="a">' +
            '<span class="ng-opt-label">Opção A</span>' +
            esc((d.option_a || {}).texto || '') +
          '</button>' +
          '<button class="ng-btn-option" data-pick="b">' +
            '<span class="ng-opt-label">Opção B</span>' +
            esc((d.option_b || {}).texto || '') +
          '</button>' +
        '</div>';

      inner.querySelectorAll('.ng-btn-option').forEach(function (btn) {
        btn.addEventListener('click', function () { renderVeredicto(btn.dataset.pick); });
      });
    }

    /* ── Tela de veredito ── */
    function renderVeredicto(pick) {
      var d        = dilemas[idx];
      var chosen   = pick === 'a' ? (d.option_a || {}) : (d.option_b || {});
      var other    = pick === 'a' ? (d.option_b || {}) : (d.option_a || {});
      var otherKey = pick === 'a' ? 'B' : 'A';
      var pct      = Math.round((idx + 1) / dilemas.length * 100);
      var nextLbl  = idx + 1 < dilemas.length ? 'Próxima pergunta →' : 'Ver resultado final';
      var verd     = d.veredicto || {};

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
        '<div class="ng-options">' +
          '<button class="ng-btn-option is-chosen" disabled>' +
            '<span class="ng-opt-label">Opção ' + pick.toUpperCase() + ' — sua escolha</span>' +
            esc(chosen.texto || '') +
            (chosen.consequencia
              ? '<span class="ng-opt-consequence">' + esc(chosen.consequencia) + '</span>'
              : '') +
          '</button>' +
          '<button class="ng-btn-option is-other" disabled>' +
            '<span class="ng-opt-label">Opção ' + otherKey + '</span>' +
            esc(other.texto || '') +
          '</button>' +
        '</div>' +
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
            'Você respondeu todos os ' + dilemas.length + ' dilemas de <em>' + esc(title) + '</em>.' +
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
