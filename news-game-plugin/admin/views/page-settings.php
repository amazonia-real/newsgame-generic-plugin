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
            Preencha abaixo a chave do provedor escolhido. As chaves dos outros provedores
            ficam guardadas e não são enviadas.
            <br>
            A chave pode ficar no campo abaixo (gravada no banco do WordPress, em texto simples,
            visível a administradores) ou no arquivo <code>.env</code> do servidor do backend.
            Se as duas existirem, <strong>vale a do campo</strong>; a do <code>.env</code> só é
            usada quando o campo está vazio.
            <br>
            O texto do acervo é enviado ao provedor de IA escolhido, e planos gratuitos têm
            limite diário de uso.
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
        <th scope="row"><label for="newsgame_openai_api_key">OpenAI API Key</label></th>
        <td>
          <input type="password" id="newsgame_openai_api_key" name="newsgame_openai_api_key"
                 value="<?php echo esc_attr( get_option( 'newsgame_openai_api_key', '' ) ); ?>"
                 class="regular-text" autocomplete="off">
          <p class="description">
            Obtida em <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">OpenAI Platform</a>.
            Armazenada nas configurações do WordPress, sem criptografia. Restrito a administradores com permissão de gerenciar opções.
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="newsgame_claude_api_key">Claude API Key</label></th>
        <td>
          <input type="password" id="newsgame_claude_api_key" name="newsgame_claude_api_key"
                 value="<?php echo esc_attr( get_option( 'newsgame_claude_api_key', '' ) ); ?>"
                 class="regular-text" autocomplete="off">
          <p class="description">
            <?php /* URL conferida em 2026-09-15: console.anthropic.com REDIRECIONA para
                     platform.claude.com, então a canônica é esta. Não escrever a antiga
                     de memória — mesma regra do nome do modelo. */ ?>
            Obtida em <a href="https://platform.claude.com/settings/keys" target="_blank" rel="noopener">Claude Platform</a>.
            Armazenada nas configurações do WordPress, sem criptografia. Restrito a administradores com permissão de gerenciar opções.
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="newsgame_backend_url">URL do Backend Python</label></th>
        <td>
          <input type="url" id="newsgame_backend_url" name="newsgame_backend_url"
                 value="<?php echo esc_attr( get_option( 'newsgame_backend_url', '' ) ); ?>"
                 class="regular-text" placeholder="https://backend.seuveiculo.com.br">
          <p class="description">Endereço do backend Python (o servidor que gera as perguntas e guarda o índice do acervo), em HTTPS, informado pela equipe de TI que instalou o backend (por exemplo <code>https://backend.seuveiculo.com.br</code>). <code>localhost</code> só serve em desenvolvimento. Necessário para a geração de perguntas e a indexação.</p>
          <p id="newsgame-backend-status" role="status" aria-live="polite" style="font-weight:600">
            <?php echo esc_html( Newsgame_Settings::texto_do_estado( Newsgame_Settings::backend_status()['estado'] ) ); ?>
          </p>
          <p>
            <button type="button" id="newsgame-test-btn" class="button">Testar conexão</button>
            <span class="description">Testa o endereço e o token que já estão salvos.</span>
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="newsgame_backend_api_key">Token do Backend</label></th>
        <td>
          <input type="password" id="newsgame_backend_api_key" name="newsgame_backend_api_key"
                 value="<?php echo esc_attr( get_option( 'newsgame_backend_api_key', '' ) ); ?>"
                 class="regular-text" autocomplete="off">
          <p class="description">
            É o valor de <code>API_SECRET_TOKEN</code> no arquivo <code>.env</code> do servidor
            do backend, informado por quem instalou o backend.
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
    <strong><span id="newsgame-vault-count"><?php echo esc_html( $vault_count ); ?></span> itens</strong> indexados em
    <code><?php echo esc_html( $vault_path ); ?></code>.
  </p>
  <p>
    Extrai posts e páginas publicados via WP REST API e salva como JSON no vault.
    O vault é a entrada do pipeline RAG (indexação no ChromaDB).
  </p>

  <button id="newsgame-sync-btn" class="button button-secondary">
    &#8635; Sincronizar Acervo
  </button>
  <span id="newsgame-sync-status" style="margin-left:12px;font-weight:600;"></span>

  <?php if ( get_option( 'newsgame_backend_url' ) ) : ?>
  <hr style="margin:1.5rem 0 1rem">
  <h3 style="margin-bottom:0.25rem">Indexar no Backend (ChromaDB)</h3>
  <p class="description" style="margin-bottom:0.75rem">
    Envia os <span id="newsgame-vault-count-2"><?php echo esc_html( $vault_count ); ?></span> itens do vault para o endpoint <code>/ingest</code> do backend,
    populando o ChromaDB que alimenta a geração de dilemas.
  </p>
  <button id="newsgame-push-btn" class="button button-primary">
    &#8679; Indexar Acervo no Backend
  </button>
  <span id="newsgame-push-status" style="margin-left:12px;font-weight:600;"></span>
  <?php endif; ?>

  <!-- O JS desta tela mora em admin/js/newsgame-admin-settings.js, enfileirado
       por Newsgame_Settings::enqueue_assets(). Ver A3 / FLAG 007. -->
</div>
