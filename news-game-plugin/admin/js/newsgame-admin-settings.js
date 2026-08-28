/**
 * News-Game — tela de configurações: sincronizar acervo e indexar no backend.
 *
 * Extraído do <script> inline de admin/views/page-settings.php pelo A3
 * (FLAG 007 passo 3). Dois motivos: JS inline exige 'unsafe-inline' em
 * script-src, e o ESLint não lê JS dentro de PHP — este arquivo passa a entrar
 * na trava de complexidade do `npm run lint`.
 *
 * Os dados dinâmicos chegam em `ngSettingsCfg`, por wp_localize_script em
 * Newsgame_Settings::enqueue_assets(). Nenhum valor de PHP é interpolado aqui,
 * e por isso o esc_js() das quatro linhas de action/nonce desapareceu: o JSON
 * agora é gerado e escapado pelo WordPress.
 */
(function() {
  'use strict';

  var cfg = window.ngSettingsCfg || {};

  /* ── Sincronizar vault local ── */
  var syncBtn    = document.getElementById('newsgame-sync-btn');
  var syncStatus = document.getElementById('newsgame-sync-status');
  var logBox     = document.getElementById('newsgame-sync-log');
  var logPre     = document.getElementById('newsgame-sync-pre');

  // Âncora única da tela: #newsgame-sync-btn só existe aqui. Se ele está no
  // DOM, os outros três elementos também estão — são irmãos no mesmo bloco de
  // markup. A guarda existe porque o enfileiramento é por hook de tela, e um
  // dia em que o markup mudar isto vira no-op em vez de exceção no console.
  if (!syncBtn) { return; }

  syncBtn.addEventListener('click', function() {
    syncBtn.disabled = true;
    syncStatus.style.color = '#666';
    syncStatus.textContent = 'Sincronizando…';
    logBox.style.display = 'none';

    var data = new FormData();
    data.append('action', cfg.actionSync);
    data.append('nonce',  cfg.nonceSync);

    fetch(ajaxurl, { method: 'POST', body: data })
      .then(function(r) { return r.json(); })
      .then(function(r) {
        if (r.success) {
          var d = r.data;
          syncStatus.style.color = '#2d7a2d';
          syncStatus.textContent = '✓ ' + d.saved + ' itens salvos.';
          var log = 'Salvos: ' + d.saved;
          if (d.errors && d.errors.length) log += '\nErros:\n' + d.errors.join('\n');
          logPre.textContent = log;
        } else {
          syncStatus.style.color = '#c00';
          syncStatus.textContent = '✗ Erro: ' + (r.data && r.data.error ? r.data.error : JSON.stringify(r));
          logPre.textContent = JSON.stringify(r, null, 2);
        }
        logBox.style.display = 'block';
      })
      .catch(function(e) {
        syncStatus.style.color = '#c00';
        syncStatus.textContent = '✗ ' + e.message;
      })
      .finally(function() { syncBtn.disabled = false; });
  });

  /* ── Indexar no backend (push) ── */
  var pushBtn = document.getElementById('newsgame-push-btn');
  if (pushBtn) {
    var pushStatus = document.getElementById('newsgame-push-status');
    pushBtn.addEventListener('click', function() {
      pushBtn.disabled = true;
      pushStatus.style.color = '#666';
      pushStatus.textContent = 'Enviando para o backend…';

      var data = new FormData();
      data.append('action', cfg.actionPush);
      data.append('nonce',  cfg.noncePush);

      fetch(ajaxurl, { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(r) {
          if (r.success) {
            pushStatus.style.color = '#2d7a2d';
            pushStatus.textContent = '✓ ' + r.data.sent + ' artigos indexados no ChromaDB.';
          } else {
            pushStatus.style.color = '#c00';
            pushStatus.textContent = '✗ ' + (r.data && r.data.msg ? r.data.msg : JSON.stringify(r));
          }
        })
        .catch(function(e) {
          pushStatus.style.color = '#c00';
          pushStatus.textContent = '✗ ' + e.message;
        })
        .finally(function() { pushBtn.disabled = false; });
    });
  }
}());
