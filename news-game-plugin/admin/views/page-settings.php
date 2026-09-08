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
        <th scope="row"><label for="newsgame_llm_provider">Provedor de IA</label></th>
        <td>
          <?php
          /*
           * O `selected` sai de llm_provider(), ou seja DO BANCO — nunca de uma
           * comparação com PROVEDOR_DEFAULT. É o que faz um save que não toca
           * neste campo reenviar o valor atual e a escrita ser idempotente, em
           * vez de rebaixar o provedor salvo: a família do C11, que o padrão de
           * skins já pagou uma vez.
           *
           * As opções vêm em loop sobre a whitelist, então valor inválido não é
           * oferecido pela tela para começo de conversa. A rede para o que o
           * formulário não cobre (POST forjado, campo `disabled`, exibição
           * condicional futura) é Newsgame_Settings::sanitize_provedor().
           */
          $provedor_atual = Newsgame_Settings::llm_provider();
          ?>
          <select id="newsgame_llm_provider" name="newsgame_llm_provider">
            <?php foreach ( Newsgame_Settings::PROVEDORES as $slug => $meta ) : ?>
              <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $provedor_atual, $slug ); ?>>
                <?php echo esc_html( $meta['rotulo'] ); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="description">
            Define qual provedor gera os dilemas. A escolha vale para <strong>todas</strong> as
            chamadas ao backend: geração de perguntas, pedido de substituta, atribuição de pesos
            e análise do acervo.
            <br>
            Preencha abaixo a chave do provedor escolhido. A chave do outro provedor fica
            guardada e não é enviada.
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="newsgame_gemini_api_key">Gemini API Key</label></th>
        <td>
          <input type="password" id="newsgame_gemini_api_key" name="newsgame_gemini_api_key"
                 value="<?php echo esc_attr( get_option( 'newsgame_gemini_api_key', '' ) ); ?>"
                 class="regular-text" autocomplete="off">
          <p class="description">
            Obtida em <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a>.
            Armazenada nas configurações do WordPress, sem criptografia. Restrito a administradores com permissão de gerenciar opções.
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="newsgame_deepseek_api_key">DeepSeek API Key</label></th>
        <td>
          <input type="password" id="newsgame_deepseek_api_key" name="newsgame_deepseek_api_key"
                 value="<?php echo esc_attr( get_option( 'newsgame_deepseek_api_key', '' ) ); ?>"
                 class="regular-text" autocomplete="off">
          <p class="description">
            Obtida em <a href="https://platform.deepseek.com/api_keys" target="_blank" rel="noopener">DeepSeek Platform</a>.
            Armazenada nas configurações do WordPress, sem criptografia. Restrito a administradores com permissão de gerenciar opções.
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

  <!-- O JS desta tela mora em admin/js/newsgame-admin-settings.js, enfileirado
       por Newsgame_Settings::enqueue_assets(). Ver A3 / FLAG 007. -->
</div>
