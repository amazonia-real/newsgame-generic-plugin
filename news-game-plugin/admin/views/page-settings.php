<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
  <h1>News-Game &mdash; Configurações</h1>

  <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>Configurações salvas.</p></div>
  <?php endif; ?>

  <form method="post" action="options.php">
    <?php settings_fields( 'newsgame_settings' ); ?>

    <h2>Identidade</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="newsgame_organization_name">Nome da Organização</label></th>
        <td>
          <input type="text" id="newsgame_organization_name" name="newsgame_organization_name"
                 value="<?php echo esc_attr( get_option( 'newsgame_organization_name', 'News-Game' ) ); ?>"
                 class="regular-text">
          <p class="description">Substitui "Amazônia Real" nas labels genéricas do plugin.</p>
        </td>
      </tr>
    </table>

    <h2>IA Generativa</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="newsgame_gemini_api_key">Gemini API Key</label></th>
        <td>
          <input type="password" id="newsgame_gemini_api_key" name="newsgame_gemini_api_key"
                 value="<?php echo esc_attr( get_option( 'newsgame_gemini_api_key', '' ) ); ?>"
                 class="regular-text" autocomplete="off">
          <p class="description">
            Obtida em <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a>.
            Armazenada criptografada no banco WordPress — nunca em arquivo.
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="newsgame_backend_url">URL do Backend Python</label></th>
        <td>
          <input type="url" id="newsgame_backend_url" name="newsgame_backend_url"
                 value="<?php echo esc_attr( get_option( 'newsgame_backend_url', '' ) ); ?>"
                 class="regular-text" placeholder="https://meu-backend.run.app">
          <p class="description">Endpoint Cloud Run do pipeline RAG. Necessário para geração de dilemas.</p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="newsgame_backend_api_key">Token do Backend</label></th>
        <td>
          <input type="password" id="newsgame_backend_api_key" name="newsgame_backend_api_key"
                 value="<?php echo esc_attr( get_option( 'newsgame_backend_api_key', '' ) ); ?>"
                 class="regular-text" autocomplete="off">
          <p class="description">
            Valor da variável <code>API_SECRET_TOKEN</code> no seu backend.
            Deixe em branco se o backend não exigir autenticação.
          </p>
        </td>
      </tr>
    </table>

    <?php submit_button( 'Salvar configurações' ); ?>
  </form>

  <hr>

  <!-- ── Painel do Ingestor ──────────────────────────────────── -->
  <h2>Acervo (Vault)</h2>
  <?php
  $vault_files = Newsgame_Ingestor::vault_index();
  $vault_count = count( $vault_files );
  $upload      = wp_upload_dir();
  $vault_path  = trailingslashit( $upload['basedir'] ) . 'newsgame-vault';
  ?>
  <p>
    <strong><?php echo esc_html( $vault_count ); ?> itens</strong> indexados em
    <code><?php echo esc_html( $vault_path ); ?></code>.
  </p>
  <p>
    Extrai posts e páginas publicados via WP REST API e salva como JSON no vault.
    O vault é a entrada do pipeline RAG (ChromaDB / Gemini).
  </p>

  <button id="newsgame-sync-btn" class="button button-secondary">
    &#8635; Sincronizar Acervo
  </button>
  <span id="newsgame-sync-status" style="margin-left:12px;font-weight:600;"></span>

  <div id="newsgame-sync-log" style="margin-top:1rem;display:none;">
    <h4>Log da última sincronização</h4>
    <pre id="newsgame-sync-pre"
         style="background:#f0f0f0;padding:1rem;max-height:200px;overflow:auto;font-size:12px;"></pre>
  </div>

  <?php if ( get_option( 'newsgame_backend_url' ) ) : ?>
  <hr style="margin:1.5rem 0 1rem">
  <h3 style="margin-bottom:0.25rem">Indexar no Backend (ChromaDB)</h3>
  <p class="description" style="margin-bottom:0.75rem">
    Envia os <?php echo esc_html( $vault_count ); ?> itens do vault para o endpoint <code>/ingest</code> do backend,
    populando o ChromaDB que alimenta a geração via Gemini.
  </p>
  <button id="newsgame-push-btn" class="button button-primary">
    &#8679; Indexar Acervo no Backend
  </button>
  <span id="newsgame-push-status" style="margin-left:12px;font-weight:600;"></span>
  <?php endif; ?>

  <script>
  (function() {
    /* ── Sincronizar vault local ── */
    var syncBtn    = document.getElementById('newsgame-sync-btn');
    var syncStatus = document.getElementById('newsgame-sync-status');
    var logBox     = document.getElementById('newsgame-sync-log');
    var logPre     = document.getElementById('newsgame-sync-pre');

    syncBtn.addEventListener('click', function() {
      syncBtn.disabled = true;
      syncStatus.style.color = '#666';
      syncStatus.textContent = 'Sincronizando…';
      logBox.style.display = 'none';

      var data = new FormData();
      data.append('action', '<?php echo esc_js( Newsgame_Ingestor::AJAX_ACTION ); ?>');
      data.append('nonce',  '<?php echo esc_js( wp_create_nonce( Newsgame_Ingestor::NONCE_KEY ) ); ?>');

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
        data.append('action', '<?php echo esc_js( Newsgame_Generator::AJAX_PUSH ); ?>');
        data.append('nonce',  '<?php echo esc_js( wp_create_nonce( Newsgame_Generator::NONCE_PUSH ) ); ?>');

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
  </script>
</div>
