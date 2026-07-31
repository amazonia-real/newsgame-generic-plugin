<?php
defined( 'ABSPATH' ) || exit;

// Carrega instância existente (edição) ou define defaults (novo)
$id      = (int) ( $_GET['id'] ?? 0 );
$inst    = $id ? Newsgame_Admin_Instances::get( $id ) : null;
$is_edit = (bool) $inst;

$title         = $is_edit ? $inst->title        : '';
$theme         = $is_edit ? $inst->theme_prompt : '';
$density       = $is_edit ? (int) $inst->density : 10;
$num_options   = $is_edit ? (int) ( $inst->num_options   ?? 2 ) : 2;
$enable_levels = $is_edit ? (bool) ( $inst->enable_levels ?? false ) : false;
$nivel_padrao  = $is_edit ? ( $inst->nivel_padrao ?? 'pedagogico' ) : 'pedagogico';
$status        = $is_edit ? $inst->status       : 'draft';
$chars         = $is_edit ? (array) json_decode( $inst->characters ?? '[]', true ) : array();
$axis_matrix   = $is_edit ? ( $inst->axis_matrix ?? '' ) : '';

$form_url = add_query_arg( array( 'action' => 'newsgame_save_instance' ), admin_url( 'admin-post.php' ) );
$list_url = add_query_arg( array( 'page' => 'newsgame-instances' ), admin_url( 'admin.php' ) );
$page_title = $is_edit
    ? 'Editar: ' . esc_html( $title )
    : 'Nova Instância de Jogo';
?>
<style>
/* ── Estilos do formulário de instância ──────────────── */
#ng-form .form-table th    { width: 180px; padding-top: 18px; }
#ng-form .form-table td    { padding-top: 10px; }
.ng-section-title          { margin: 2rem 0 0.25rem; border-bottom: 1px solid #ddd; padding-bottom: 6px; }
/* Builder de personagens */
#ng-chars-list             { margin: 0.75rem 0; }
.ng-char-row               { display: flex; gap: 10px; align-items: flex-start;
                             padding: 12px 14px; background: #f6f7f7;
                             border: 1px solid #dcdcde; border-radius: 4px;
                             margin-bottom: 8px; }
.ng-char-row .ng-col-label { flex: 1 1 200px; }
.ng-char-row .ng-col-desc  { flex: 2 1 300px; }
.ng-char-row .ng-col-rm    { flex: 0 0 auto; padding-top: 22px; }
.ng-char-row label         { display: block; font-size: 12px; font-weight: 600;
                             margin-bottom: 4px; color: #444; }
.ng-char-row input         { width: 100%; box-sizing: border-box; }
#ng-chars-list:empty::after {
    content: 'Nenhum personagem definido. Clique em "+ Adicionar Personagem".';
    display: block; color: #888; font-style: italic; font-size: 13px;
}
/* Barra de ações fixa no rodapé da tela */
#ng-sticky-actions         { position: sticky; bottom: 0; background: #fff;
                             border-top: 1px solid #dcdcde; padding: 12px 0;
                             margin-top: 2rem; display: flex; gap: 10px;
                             align-items: center; z-index: 10; }
#ng-density-display        { font-weight: 600; color: #2d7a2d; }

/* ── Mesa Editorial ─────────────────────────────────────── */
#ng-review-section         { margin-top: 2.5rem; }
.ng-review-tabs            { display: flex; gap: 0; border-bottom: 2px solid #2d7a2d;
                             margin-bottom: 1rem; }
.ng-tab                    { background: #f6f7f7; border: 1px solid #dcdcde;
                             border-bottom: none; padding: 8px 18px; cursor: pointer;
                             font-size: 13px; font-weight: 600; color: #444;
                             border-radius: 4px 4px 0 0; margin-right: 3px;
                             transition: background 0.15s; }
.ng-tab:hover              { background: #e8f5e2; }
.ng-tab.is-active          { background: #2d7a2d; color: #fff; border-color: #2d7a2d; }
.ng-tab-badge              { display: inline-block; background: rgba(0,0,0,.15);
                             border-radius: 10px; padding: 0 7px; font-size: 11px;
                             margin-left: 6px; min-width: 18px; text-align: center; }
.ng-tab.is-active .ng-tab-badge { background: rgba(255,255,255,.3); }
#ng-review-list            { border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; }
.ng-qrow                   { border-bottom: 1px solid #e8e8e8; }
.ng-qrow:last-child        { border-bottom: none; }
.ng-qrow-head              { display: flex; align-items: center; gap: 10px;
                             padding: 10px 14px; cursor: pointer; background: #fff;
                             transition: background 0.12s; user-select: none; }
.ng-qrow-head:hover        { background: #f0f8eb; }
.ng-qrow-head.is-open      { background: #e8f5e2; }
.ng-qrow-num               { flex: 0 0 28px; font-size: 12px; color: #888;
                             font-weight: 700; text-align: right; }
.ng-qrow-title             { flex: 1 1 auto; font-size: 13px; font-weight: 600;
                             color: #1d2327; white-space: nowrap; overflow: hidden;
                             text-overflow: ellipsis; }
.ng-qrow-meta              { flex: 0 0 auto; font-size: 11px; color: #888; }
.ng-qrow-chevron           { flex: 0 0 20px; text-align: center; color: #888;
                             font-size: 16px; transition: transform 0.2s; }
.ng-qrow-head.is-open .ng-qrow-chevron { transform: rotate(90deg); }
.ng-qrow-body              { padding: 0 16px 16px; background: #fafafa;
                             border-top: 1px solid #e8e8e8; display: none; }
.ng-edit-table             { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
.ng-edit-table th          { width: 160px; text-align: right; padding: 6px 12px 6px 0;
                             vertical-align: top; font-size: 12px; color: #555;
                             font-weight: 600; white-space: nowrap; }
.ng-edit-table td          { padding: 4px 0; }
.ng-edit-table textarea,
.ng-edit-table input[type=text],
.ng-edit-table input[type=url] { width: 100%; box-sizing: border-box;
                                 font-size: 13px; padding: 5px 8px; }
.ng-source-check           { font-size: 12px; color: #2271b1; margin-top: 3px;
                             display: inline-block; }
.ng-qrow-actions           { display: flex; gap: 8px; align-items: center;
                             padding-top: 4px; flex-wrap: wrap; }
.ng-action-msg             { font-size: 12px; margin-left: 6px; }
.ng-review-empty           { padding: 1.5rem; color: #888; font-style: italic;
                             text-align: center; }
#ng-review-pagination      { padding: 10px 0; display: flex; gap: 4px;
                             flex-wrap: wrap; }
.ng-page-btn               { padding: 4px 10px; border: 1px solid #dcdcde;
                             background: #f6f7f7; border-radius: 3px; cursor: pointer;
                             font-size: 12px; }
.ng-page-btn:hover         { background: #e8f5e2; border-color: #2d7a2d; }
.ng-page-btn.is-current    { background: #2d7a2d; color: #fff; border-color: #2d7a2d; }
.ng-review-loading         { padding: 1.5rem; color: #888; text-align: center; }
.ng-char-count             { font-size: 11px; color: #888; margin-top: 2px; display: block; }
.ng-char-count.is-over     { color: #c00; font-weight: 600; }
</style>

<div class="wrap">
  <h1><?php echo $page_title; ?></h1>
  <a href="<?php echo esc_url( $list_url ); ?>" style="display:inline-block;margin-bottom:1rem">
    &larr; Voltar para lista
  </a>

  <?php if ( isset( $_GET['error'] ) && 'title_required' === $_GET['error'] ) : ?>
    <div class="notice notice-error"><p>O nome do jogo é obrigatório.</p></div>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url( $form_url ); ?>" id="ng-form">
    <?php wp_nonce_field( 'newsgame_save_instance', 'nonce' ); ?>
    <?php if ( $is_edit ) : ?>
      <input type="hidden" name="instance_id" value="<?php echo (int) $id; ?>">
    <?php endif; ?>
    <input type="hidden" id="ng-status-input" name="status" value="<?php echo esc_attr( $status ); ?>">

    <!-- ── Identificação ─────────────────────────────────── -->
    <h2 class="ng-section-title">Identificação</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row">
          <label for="ng-title">Nome do Jogo <span style="color:#c00" aria-label="obrigatório">*</span></label>
        </th>
        <td>
          <input type="text" id="ng-title" name="title"
                 value="<?php echo esc_attr( $title ); ?>"
                 class="large-text" maxlength="500" required
                 placeholder="Ex: Grandes Estreias do Teatro, Geração Z…">
          <p class="description">
            Aparece no painel e gera o shortcode
            <code>[news-game id="<?php echo $is_edit ? (int) $id : 'X'; ?>"]</code>.
          </p>
        </td>
      </tr>
    </table>

    <!-- ── Contexto de IA ────────────────────────────────── -->
    <h2 class="ng-section-title">Contexto de IA</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row">
          <label for="ng-theme">Descrição do Acervo</label>
        </th>
        <td>
          <textarea id="ng-theme" name="theme_prompt" rows="7" class="large-text"
                    placeholder="Descreva o tema do jogo e o acervo que o alimenta.

Exemplo:
Este jogo é sobre a história do teatro brasileiro, baseado no acervo do portal Exemplo com 800 reportagens. Os dilemas exploram escolhas éticas e artísticas de diretores, atores e produtores ao longo de décadas. O público-alvo são jovens entre 18 e 30 anos."><?php echo esc_textarea( $theme ); ?></textarea>
          <p class="description">
            Este texto é enviado ao Gemini como contexto da geração. Seja específico:
            descreva o tema, o tipo de acervo e quem é o público-alvo.
          </p>

          <button
            type="button"
            id="btn-analisar-eixos"
            class="button button-secondary"
            style="margin-top: 8px;"
          >
            🔍 Analisar Acervo e Propor Eixos
          </button>

          <div id="container-eixos-dinamicos" style="margin-top: 16px;"><?php if ( $axis_matrix ) : ?>
            <div style="background:#f0f7f0; border:1px solid #00a32a; padding:16px; border-radius:4px;">
              <strong style="color:#00a32a; font-size:13px;">✅ Matriz de eixos salva — edite e confirme se necessário</strong>
              <p style="font-size:12px; color:#555; margin:8px 0;">Edite o JSON abaixo se necessário antes de salvar.</p>
              <textarea id="json-eixos-editavel" rows="15" style="width:100%; font-family:monospace; font-size:12px; background:#222; color:#fff; padding:10px; border-radius:4px; border:1px solid #555; box-sizing:border-box;"><?php
                $axis_decoded = json_decode( $axis_matrix, true );
                echo esc_textarea( $axis_decoded
                    ? wp_json_encode( $axis_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
                    : $axis_matrix
                );
              ?></textarea>
              <button type="button" id="btn-salvar-matriz" class="button button-primary" style="margin-top:10px;">✓ Confirmar e Salvar Matriz de Eixos</button>
            </div>
          <?php endif; ?></div>
        </td>
      </tr>
    </table>

    <!-- ── Configuração ──────────────────────────────────── -->
    <h2 class="ng-section-title">Configuração</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row">
          <label for="ng-density">Número de Perguntas/Rodadas</label>
        </th>
        <td>
          <input type="number" id="ng-density" name="density"
                 value="<?php echo (int) $density; ?>"
                 min="4" max="15" step="1"
                 style="width:80px;vertical-align:middle">
          &nbsp;perguntas
          <p class="description">
            Número de dilemas gerados por sessão de jogo.
            Mínimo: 4 &nbsp;|&nbsp; Máximo: 15
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row">Opções de Resposta</th>
        <td>
          <label style="margin-right:1.5rem">
            <input type="radio" name="num_options" value="2"
                   <?php checked( $num_options, 2 ); ?>>
            2 opções &nbsp;<code>A / B</code>
          </label>
          <label>
            <input type="radio" name="num_options" value="3"
                   <?php checked( $num_options, 3 ); ?>>
            3 opções &nbsp;<code>A / B / C</code>
          </label>
          <p class="description">
            Número de alternativas geradas por pergunta. 3 opções aumentam a complexidade narrativa.
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row">Níveis de Dificuldade</th>
        <td>
          <label>
            <input type="checkbox" id="ng-enable-levels" name="enable_levels" value="1"
                   <?php checked( $enable_levels ); ?>>
            Ativar Níveis (Aprendiz / Pro)
          </label>
          <p class="description">
            Se ativo, o Gemini calibra a complexidade em dois níveis: Aprendiz (linguagem acessível)
            e Pro (jornalístico e sistêmico, com 20% das perguntas no nível avançado).
            Se inativo, todas as perguntas têm complexidade uniforme.
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row">
          <label for="ng-nivel-padrao">Perfil do Jogo</label>
        </th>
        <td>
          <select id="ng-nivel-padrao" name="nivel_padrao" style="min-width:200px">
            <option value="ludico"     <?php selected( $nivel_padrao, 'ludico' ); ?>>Lúdico</option>
            <option value="pedagogico" <?php selected( $nivel_padrao, 'pedagogico' ); ?>>Pedagógico</option>
            <option value="complexo"   <?php selected( $nivel_padrao, 'complexo' ); ?>>Complexo</option>
          </select>
          <p class="description">
            Define o tom narrativo das perguntas geradas pelo Gemini.<br>
            <strong>Lúdico:</strong> linguagem leve, dilemas de fácil identificação.<br>
            <strong>Pedagógico:</strong> equilíbrio entre acessibilidade e profundidade (padrão).<br>
            <strong>Complexo:</strong> dilemas sistêmicos, linguagem jornalística densa.
          </p>
        </td>
      </tr>
    </table>

    <!-- ── Personagens ───────────────────────────────────── -->
    <h2 class="ng-section-title">Personagens do Jogo</h2>
    <p class="description" style="margin-bottom:0.75rem">
      Defina quem são as personas desta instância. Cada personagem representa um
      ponto de vista nas perguntas geradas pelo Gemini.<br>
      Exemplo: <em>Diretor de Teatro, Ator, Produtor Executivo, Crítico Cultural.</em><br>
      <strong>Sem personagens?</strong> O motor entra no <strong>Modo Perguntas Gerais</strong>:
      o Gemini gerará perguntas para um jogador genérico, sem perspectiva fixa de personagem.
    </p>

    <div id="ng-chars-list" role="list" aria-label="Personagens"></div>

    <button type="button" id="ng-add-char" class="button button-secondary">
      + Adicionar Personagem
    </button>

    <!-- JSON serializado dos personagens — lido pelo PHP no submit -->
    <input type="hidden" id="ng-chars-json" name="characters_json"
           value="<?php echo esc_attr( wp_json_encode( array_values( $chars ) ) ); ?>">

    <!-- Template de linha de personagem (não renderizado pelo browser) -->
    <template id="ng-char-tpl">
      <div class="ng-char-row" role="listitem">
        <div class="ng-col-label">
          <label>Nome / Label</label>
          <input type="text" class="ng-char-label regular-text"
                 placeholder="Ex: Diretor de Teatro" maxlength="200"
                 aria-label="Nome do personagem">
        </div>
        <div class="ng-col-desc">
          <label>Descrição para a IA</label>
          <input type="text" class="ng-char-desc widefat"
                 placeholder="Ex: Representa a perspectiva artística e a visão de quem concebe o espetáculo"
                 aria-label="Descrição para a IA">
        </div>
        <div class="ng-col-rm">
          <button type="button" class="ng-rm button" style="color:#c00;border-color:#c00"
                  title="Remover personagem" aria-label="Remover">&#x2715;</button>
        </div>
      </div>
    </template>

    <!-- ── Ações ─────────────────────────────────────────── -->
    <div id="ng-sticky-actions">
      <button type="button" class="button button-secondary" id="ng-btn-draft">
        Salvar como Rascunho
      </button>
      <button type="button" class="button button-primary" id="ng-btn-review">
        Salvar e Preparar para Geração ›
      </button>
      <span id="ng-action-feedback" style="color:#555;font-size:13px"></span>
    </div>

  </form><!-- #ng-form -->

  <?php
  $backend_ok = (bool) Newsgame_Settings::backend_url();
  if ( $is_edit && $backend_ok ) :
  ?>
  <!-- ── Seção de Geração ───────────────────────────────── -->
  <hr style="margin:2rem 0 1.5rem">
  <h2>Geração de Perguntas</h2>

  <?php
  $dilema_count = (int) $GLOBALS['wpdb']->get_var(
      $GLOBALS['wpdb']->prepare(
          "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}newsgame_dilemas WHERE instance_id = %d",
          $id
      )
  );
  $approved_count = (int) $GLOBALS['wpdb']->get_var(
      $GLOBALS['wpdb']->prepare(
          "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}newsgame_dilemas WHERE instance_id = %d AND review_status = 'approved'",
          $id
      )
  );
  $deficit = $density - $approved_count;
  ?>
  <?php if ( $dilema_count > 0 ) : ?>
    <div class="notice notice-info inline" style="margin:0 0 1rem">
      <p>Esta instância já possui <strong><?php echo esc_html( $dilema_count ); ?> perguntas</strong> geradas. Gerar novamente adicionará ao conjunto existente.</p>
    </div>
  <?php endif; ?>

  <p class="description" style="margin-bottom:1rem">
    O backend buscará no ChromaDB os trechos relevantes para cada personagem,
    enviará ao Gemini e salvará as perguntas em <code>wp_newsgame_dilemas</code>.
    Meta: <strong><?php echo (int) $density; ?> perguntas</strong>
    com <strong><?php echo (int) $num_options; ?> opções de resposta</strong><?php echo $enable_levels ? ', níveis ativados' : ''; ?>.
    <?php if ( $approved_count > 0 ) : ?>
      Aprovadas até agora: <strong><?php echo $approved_count; ?></strong>.
      Serão geradas apenas as <strong><?php echo max( 0, $deficit ); ?></strong> perguntas em déficit.
    <?php endif; ?>
  </p>

  <button type="button" id="ng-generate-btn" class="button button-primary button-large">
    &#9654; Gerar Perguntas Agora
  </button>
  <span id="ng-gen-status" style="margin-left:12px;font-size:13px;font-weight:600"></span>

  <?php if ( $dilema_count > 0 ) : ?>
  <button type="button" id="ng-reset-btn" class="button"
          style="margin-left:16px;background:#c00;border-color:#9e0000;color:#fff">
    🗑️ Limpar e Resetar Todas as Perguntas
  </button>
  <?php endif; ?>

  <div id="ng-progress-wrap" style="display:none;margin-top:1.5rem;max-width:600px">
    <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px">
      <span id="ng-progress-label">Aguardando backend…</span>
      <span id="ng-progress-pct">0%</span>
    </div>
    <div style="background:#ddd;border-radius:4px;height:12px;overflow:hidden">
      <div id="ng-progress-bar" style="background:#2d7a2d;height:100%;width:0%;transition:width 0.4s;border-radius:4px"></div>
    </div>
    <p id="ng-progress-last" style="font-size:12px;color:#666;margin-top:6px"></p>
  </div>

  <script>
  (function () {
    var genBtn      = document.getElementById('ng-generate-btn');
    var genStatus   = document.getElementById('ng-gen-status');
    var resetBtn    = document.getElementById('ng-reset-btn');
    var wrap        = document.getElementById('ng-progress-wrap');
    var pLabel      = document.getElementById('ng-progress-label');
    var pPct        = document.getElementById('ng-progress-pct');
    var pBar        = document.getElementById('ng-progress-bar');
    var pLast       = document.getElementById('ng-progress-last');
    var instId      = <?php echo (int) $id; ?>;
    var nonce       = '<?php echo esc_js( wp_create_nonce( Newsgame_Generator::NONCE_GENERATE ) ); ?>';
    var reviewNonce = '<?php echo esc_js( wp_create_nonce( Newsgame_Review::NONCE ) ); ?>';

    function setBar(cur, tot) {
      var pct = tot > 0 ? Math.min(100, Math.round(cur / tot * 100)) : 0;
      pBar.style.width = pct + '%';
      pPct.textContent = pct + '%';
    }

    function setError(msg) {
      genStatus.style.color = '#c00';
      genStatus.textContent = '✗ ' + msg;
      genBtn.disabled = false;
    }

    function setDone(count) {
      wrap.style.display = 'block';
      setBar(count, count);
      pLabel.textContent    = '✓ Geração concluída!';
      pLast.textContent     = count + ' dilemas salvos.';
      genStatus.style.color = '#2d7a2d';
      genStatus.textContent = '✓ Concluído. Recarregando…';
      genBtn.disabled = false;
      setTimeout(function () { window.location.reload(); }, 2500);
    }

    /* ── Clique no botão: obtém config do WP e inicia loop ── */
    genBtn.addEventListener('click', function () {
      genBtn.disabled = true;
      genStatus.style.color = '#666';
      genStatus.textContent = 'Iniciando…';
      wrap.style.display = 'none';

      var fd = new FormData();
      fd.append('action',      '<?php echo esc_js( Newsgame_Generator::AJAX_START ); ?>');
      fd.append('nonce',       nonce);
      fd.append('instance_id', instId);

      fetch(ajaxurl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (!r.success) {
            setError(r.data && r.data.msg ? r.data.msg : JSON.stringify(r));
            return;
          }
          var total = r.data.payload.density || 1;
          wrap.style.display = 'block';
          setBar(0, total);
          pLabel.textContent = 'Iniciando geração…';
          genStatus.textContent = 'Conectando ao backend…';
          startBatchLoop(r.data.payload, total);
        })
        .catch(function (e) { setError(e.message); });
    });

    /* ── Loop assíncrono: 1 chamada por dilema, via proxy PHP ── */
    function startBatchLoop(basePayload, total) {
      var dilemas = [];
      var slot    = 0;
      var historico_fontes = [];

      function generateNext() {
        if (slot >= total) {
          pLabel.textContent = 'Salvando no banco de dados…';
          saveMatrix(dilemas);
          return;
        }

        pLabel.textContent = 'Gerando dilema ' + (slot + 1) + ' de ' + total + '…';
        setBar(slot, total);

        // nivel_padrao vem fixo em basePayload; slot_index só rotaciona personagens no RAG
        var payload = Object.assign({}, basePayload, { 
            slot_index: slot,
            fontes_usadas: historico_fontes 
        });

        // Proxy PHP (admin-ajax) → backend Python. action/nonce na query
        // string porque o body carrega o payload JSON cru.
        fetch(ajaxurl + '?action=<?php echo esc_js( Newsgame_Generator::AJAX_GENERATE_ONE ); ?>&nonce=' + encodeURIComponent(nonce), {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify(payload),
        })
        .then(function (response) {
          // 429 — Rate limit: aguarda 60 s e retenta o mesmo slot sem avançar
          if (response.status === 429) {
            pLabel.textContent = 'Rate limit — aguardando 60 s…';
            setTimeout(generateNext, 60000);
            return null;
          }
          // 422 — Gemini não gerou dilema válido: pula slot, continua o lote
          if (response.status === 422) {
            pLast.textContent = 'Dilema ' + (slot + 1) + ' inválido — pulando.';
            slot++;
            setBar(slot, total);
            setTimeout(generateNext, 600);
            return null;
          }
          if (!response.ok) {
            return response.text().then(function (t) {
              throw new Error('HTTP ' + response.status + ': ' + t.substring(0, 200));
            });
          }
          return response.json();
        })
        .then(function (dilema) {
          if (dilema === null) return; // 429 ou 422 já tratados acima
          dilemas.push(dilema);
          if (dilema.titulo) {
            pLast.textContent = 'Último: "' + dilema.titulo + '"';
          }
          if (dilema.info_contextual && dilema.info_contextual.link_original) {
            historico_fontes.push(dilema.info_contextual.link_original);
          } else if (dilema.source_url) {
            historico_fontes.push(dilema.source_url);
          }
          slot++;
          setBar(slot, total);
          // Pausa mínima entre chamadas para não saturar a API Gemini
          setTimeout(generateNext, 600);
        })
        .catch(function (e) {
          // Erro de rede ou HTTP inesperado: loga, pula slot e continua
          console.warn('[generator] slot=' + slot + ' falhou:', e.message);
          slot++;
          setBar(slot, total);
          setTimeout(generateNext, 600);
        });
      }

      generateNext();
    }

    /* ── Salva matriz completa no WordPress via AJAX ──────── */
    function saveMatrix(dilemas) {
      if (!dilemas.length) { setDone(0); return; }

      pLabel.textContent = 'Salvando ' + dilemas.length + ' dilemas…';

      var fd = new FormData();
      fd.append('action',      '<?php echo esc_js( Newsgame_Generator::AJAX_SAVE ); ?>');
      fd.append('nonce',       nonce);
      fd.append('instance_id', instId);
      fd.append('dilemas',     JSON.stringify(dilemas));

      fetch(ajaxurl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (r.success) {
            var saved  = r.data && r.data.saved  != null ? r.data.saved  : dilemas.length;
            var failed = r.data && r.data.failed != null ? r.data.failed : 0;
            if (failed > 0) {
              pLast.textContent = '⚠ ' + failed + ' pergunta(s) não salva(s). Verifique o log do servidor (error_log).';
            }
            setDone(saved);
          } else {
            setError('Erro ao salvar: ' + (r.data && r.data.msg ? r.data.msg : 'verifique o log do servidor.'));
          }
        })
        .catch(function (e) {
          setError('Falha de rede ao salvar: ' + e.message);
        });
    }

    /* ── Botão de Reset ──────────────────────────────────── */
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        if (!confirm('Atenção: Isso apagará permanentemente todas as perguntas (pendentes, aprovadas e descartadas) desta instância. Deseja continuar?')) {
          return;
        }
        resetBtn.disabled = true;
        resetBtn.textContent = 'Limpando…';

        var fd = new FormData();
        fd.append('action',      '<?php echo esc_js( Newsgame_Review::AJAX_CLEAR ); ?>');
        fd.append('nonce',       reviewNonce);
        fd.append('instance_id', instId);

        fetch(ajaxurl, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (r) {
            if (r.success) {
              resetBtn.textContent = '✓ Removidas. Recarregando…';
              resetBtn.style.background    = '#2d7a2d';
              resetBtn.style.borderColor   = '#1a5c1a';
              setTimeout(function () { window.location.reload(); }, 1500);
            } else {
              resetBtn.disabled = false;
              resetBtn.textContent = '🗑️ Limpar e Resetar Todas as Perguntas';
              alert('Erro ao limpar: ' + ((r.data && r.data.msg) || 'Erro desconhecido.'));
            }
          })
          .catch(function (e) {
            resetBtn.disabled = false;
            resetBtn.textContent = '🗑️ Limpar e Resetar Todas as Perguntas';
            alert('Falha de rede: ' + e.message);
          });
      });
    }
  }());
  </script>

  <script>
  var ngAxisNonce    = '<?php echo wp_create_nonce( Newsgame_Admin_Instances::NONCE_AXIS ); ?>';
  var ngAnalyzeNonce = '<?php echo wp_create_nonce( Newsgame_Admin_Instances::NONCE_ANALYZE ); ?>';
  var ngInstanceId   = <?php echo (int) $id; ?>;

  // Anexa o handler de save ao #btn-salvar-matriz.
  // Chamada tanto no DOMContentLoaded (botão pré-renderizado pelo PHP)
  // quanto após o fetch de /analyze-domain criar o botão dinamicamente.
  function ngBindSalvarBtn() {
    var btnSalvar = document.getElementById('btn-salvar-matriz');
    if (!btnSalvar || btnSalvar._ngBound) return;
    btnSalvar._ngBound = true;

    btnSalvar.addEventListener('click', async function () {
      var textarea  = document.getElementById('json-eixos-editavel');

      var feedbackEl = document.getElementById('ng-axis-feedback');
      if (!feedbackEl) {
        feedbackEl = document.createElement('p');
        feedbackEl.id = 'ng-axis-feedback';
        feedbackEl.style.cssText = 'margin:8px 0 0; font-size:13px;';
        btnSalvar.parentNode.insertBefore(feedbackEl, btnSalvar.nextSibling);
      }

      try {
        JSON.parse(textarea.value);
      } catch (parseErr) {
        feedbackEl.innerHTML =
          '<span style="color:#dc3232;">❌ JSON inválido — corrija antes de salvar: ' +
          parseErr.message + '</span>';
        return;
      }

      btnSalvar.disabled    = true;
      btnSalvar.textContent = '⏳ Salvando...';
      feedbackEl.innerHTML  = '';

      var body = new FormData();
      body.append('action',           'newsgame_save_axis_matrix');
      body.append('nonce',            ngAxisNonce);
      body.append('instance_id',      ngInstanceId);
      body.append('axis_matrix_json', textarea.value);

      try {
        var res  = await fetch(ajaxurl, { method: 'POST', body: body });
        var json = await res.json();
        if (json.success) {
          feedbackEl.innerHTML =
            '<span style="color:#00a32a;">✅ Matriz de eixos salva com sucesso.</span>';
        } else {
          feedbackEl.innerHTML =
            '<span style="color:#dc3232;">❌ Erro ao salvar: ' +
            ((json.data && json.data.msg) || 'Erro desconhecido') + '</span>';
        }
      } catch (e) {
        feedbackEl.innerHTML =
          '<span style="color:#dc3232;">❌ Falha de rede: ' + e.message + '</span>';
      } finally {
        btnSalvar.disabled    = false;
        btnSalvar.textContent = '✓ Confirmar e Salvar Matriz de Eixos';
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {

    // Botão pré-renderizado pelo PHP (instância com matriz já salva)
    ngBindSalvarBtn();

    var btn = document.getElementById('btn-analisar-eixos');
    if (!btn) return;

    btn.addEventListener('click', async function () {

      var campoAcervo =
        document.querySelector('#theme_prompt') ||
        document.querySelector('[name="theme_prompt"]') ||
        document.querySelector('#context_text') ||
        document.querySelector('[name="context_text"]') ||
        document.querySelector('#acervo') ||
        document.querySelector('[name="acervo"]');

      var container = document.getElementById('container-eixos-dinamicos');

      if (!campoAcervo || !campoAcervo.value.trim()) {
        container.innerHTML =
          '<div style="' +
            'background:#fff3cd; border:1px solid #ffc107;' +
            'padding:12px; border-radius:4px; margin-top:8px;' +
            'font-family:monospace; font-size:13px;' +
          '">' +
            '⚠️ Preencha o campo Descrição do Acervo antes de analisar.' +
          '</div>';
        return;
      }

      btn.disabled = true;
      btn.textContent = '⏳ Analisando...';
      container.innerHTML = '';

      try {
        var formData = new FormData();
        formData.append('action',       'newsgame_analyze_domain');
        formData.append('nonce',        ngAnalyzeNonce);
        formData.append('instance_id',  ngInstanceId);
        formData.append('context_text', campoAcervo.value.trim());

        var res  = await fetch(ajaxurl, { method: 'POST', body: formData });
        var json = await res.json();
        if (!json.success) {
          throw new Error((json.data && json.data.msg) || 'Erro desconhecido no servidor.');
        }

        var data = json.data;

        container.innerHTML =
          '<div style="' +
            'background:#f0f7f0; border:1px solid #00a32a;' +
            'padding:16px; border-radius:4px; margin-top:8px;' +
          '">' +
            '<strong style="color:#00a32a; font-size:13px;">✅ Eixos propostos pela IA — revise e confirme</strong>' +
            '<p style="font-size:12px; color:#555; margin:8px 0;">Edite o JSON abaixo se necessário antes de salvar.</p>' +
            '<textarea id="json-eixos-editavel" rows="15" style="' +
              'width:100%; font-family:monospace; font-size:12px;' +
              'background:#222; color:#fff; padding:10px;' +
              'border-radius:4px; border:1px solid #555; box-sizing:border-box;' +
            '">' + JSON.stringify(data, null, 2) + '</textarea>' +
            '<button type="button" id="btn-salvar-matriz" class="button button-primary" style="margin-top:10px;">' +
              '✓ Confirmar e Salvar Matriz de Eixos' +
            '</button>' +
          '</div>';

        // Botão criado dinamicamente após o fetch
        ngBindSalvarBtn();

      } catch (err) {
        container.innerHTML =
          '<div style="' +
            'background:#fdf0f0; border:1px solid #dc3232;' +
            'padding:12px; border-radius:4px; margin-top:8px;' +
            'font-family:monospace; font-size:12px;' +
          '">' +
            '❌ Erro ao conectar com o servidor Python:<br><br>' +
            '<strong>' + err.message + '</strong><br><br>' +
            'Verifique se o servidor está rodando em ' +
            'http://127.0.0.1:8000 e se o CORS está habilitado.' +
          '</div>';
      } finally {
        btn.disabled = false;
        btn.textContent = '🔍 Analisar Acervo e Propor Eixos';
      }

    });
  });
  </script>

  <?php endif; // is_edit && backend_ok ?>

<?php
// ── Mesa Editorial HIL ────────────────────────────────────
// Aparece sempre que houver perguntas no banco, independente do status da instância
$review_total = $id ? (int) $GLOBALS['wpdb']->get_var(
    $GLOBALS['wpdb']->prepare(
        "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}newsgame_dilemas WHERE instance_id = %d",
        $id
    )
) : 0;
$weighted_count = (int) $GLOBALS['wpdb']->get_var(
    $GLOBALS['wpdb']->prepare(
        "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}newsgame_dilemas WHERE instance_id = %d AND axis_weights IS NOT NULL",
        $id
    )
);
if ( $id && $review_total > 0 ) :
?>
<hr style="margin:2.5rem 0 2rem">
<div id="ng-review-section">
  <h2>Mesa Editorial
    <span style="font-size:13px;font-weight:400;color:#666;margin-left:8px">
      — revise, edite e aprove as perguntas antes de publicar no jogo
    </span>
  </h2>

  <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:1rem">
    <div class="ng-review-tabs" role="tablist" style="margin-bottom:0">
      <button class="ng-tab is-active" data-tab="pending" role="tab">
        Pendentes <span class="ng-tab-badge" id="ng-count-pending">…</span>
      </button>
      <button class="ng-tab" data-tab="approved" role="tab">
        Aprovadas <span class="ng-tab-badge" id="ng-count-approved">…</span>
      </button>
      <button class="ng-tab" data-tab="discarded" role="tab">
        Descartadas <span class="ng-tab-badge" id="ng-count-discarded">…</span>
      </button>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <?php if ( $axis_matrix ) : ?>
      <button type="button" id="ng-btn-assign-weights" class="button"
              style="white-space:nowrap"
              title="Chama o Gemini para atribuir pesos de eixo a todos os dilemas">
        ⚖ Atribuir Pesos<?php if ( $weighted_count > 0 ) : ?> <span style="font-size:11px;color:#555;">(<?php echo $weighted_count; ?>/<?php echo $review_total; ?>)</span><?php endif; ?>
      </button>
      <span id="ng-assign-msg" style="font-size:12px;"></span>
      <?php endif; ?>
      <button type="button" id="ng-btn-approve-all" class="button button-primary"
              style="margin-bottom:2px;white-space:nowrap">
        ✓ Aprovar Todas as Pendentes
      </button>
    </div>
  </div>

  <div id="ng-review-list" role="list">
    <p class="ng-review-loading">Carregando…</p>
  </div>

  <div id="ng-review-pagination"></div>
</div><!-- #ng-review-section -->

<script>
(function () {
  'use strict';

  var instId        = <?php echo (int) $id; ?>;
  var numOptions    = <?php echo (int) $num_options; ?>;
  var enableLevels  = <?php echo $enable_levels ? 'true' : 'false'; ?>;
  var nonce         = '<?php echo esc_js( wp_create_nonce( Newsgame_Review::NONCE ) ); ?>';
  var assignNonce   = '<?php echo esc_js( wp_create_nonce( Newsgame_Admin_Instances::NONCE_ASSIGN ) ); ?>';
  var totalDilemas  = <?php echo (int) $review_total; ?>;
  var curTab     = 'pending';
  var curPage    = { pending: 1, approved: 1, discarded: 1 };
  var list       = document.getElementById('ng-review-list');
  var pgWrap     = document.getElementById('ng-review-pagination');

  /* ── Escape XSS ─────────────────────────────────────────── */
  function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ── Allowlist de esquema para hrefs — esc() não valida esquema,
     "javascript:" sobreviveria intacto ──────────────────────── */
  function safeUrl(url) {
    try {
      var u = new URL(String(url), window.location.href);
      if (u.protocol === 'http:' || u.protocol === 'https:') return u.href;
    } catch (e) { /* URL malformada cai no '#' */ }
    return '#';
  }

  /* ── Tabs ───────────────────────────────────────────────── */
  document.querySelectorAll('.ng-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.ng-tab').forEach(function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');
      curTab = btn.dataset.tab;
      loadTab(curTab, curPage[curTab]);
    });
  });

  /* ── Carga inicial ──────────────────────────────────────── */
  loadTab('pending', 1);
  refreshCounts();

  /* ── Carrega aba + página ───────────────────────────────── */
  function loadTab(tab, page) {
    list.innerHTML = '<p class="ng-review-loading">Carregando…</p>';
    pgWrap.innerHTML = '';

    ajaxCall({
      action:      '<?php echo esc_js( Newsgame_Review::AJAX_LOAD ); ?>',
      nonce:       nonce,
      instance_id: instId,
      status:      tab,
      page:        page,
    }, function (data) {
      if (!data.success) {
        list.innerHTML = '<p class="ng-review-empty">' + esc(data.data && data.data.msg ? data.data.msg : 'Erro.') + '</p>';
        return;
      }
      var r = data.data;
      document.getElementById('ng-count-' + tab).textContent = r.total;
      renderRows(r.questions, tab);
      renderPagination(r.page, r.pages, tab);
    });
  }

  /* ── Renderiza lista de perguntas ───────────────────────── */
  function renderRows(questions, tab) {
    if (!questions.length) {
      list.innerHTML = '<p class="ng-review-empty">Nenhuma pergunta nesta categoria.</p>';
      return;
    }
    list.innerHTML = questions.map(function (q, i) {
      return buildRow(q, i + 1, tab);
    }).join('');
    list.querySelectorAll('textarea[data-limit]').forEach(initCharCount);
  }

  /* ── Contadores de caracteres ───────────────────────────── */
  function initCharCount(ta) {
    var span = document.createElement('span');
    span.className = 'ng-char-count';
    ta.parentNode.insertBefore(span, ta.nextSibling);
    updateCharCount(ta, span);
  }

  function updateCharCount(ta, span) {
    var limit = parseInt(ta.dataset.limit, 10);
    var len   = ta.value.length;
    span.textContent = len + '/' + limit;
    span.classList.toggle('is-over', len > limit);
  }

  list.addEventListener('input', function (e) {
    var ta = e.target;
    if (ta.tagName !== 'TEXTAREA' || !ta.dataset.limit) return;
    var span = ta.nextElementSibling;
    if (span && span.classList.contains('ng-char-count')) {
      updateCharCount(ta, span);
    }
  });

  /* ── Constrói HTML de uma linha accordion ───────────────── */
  function buildRow(q, num, tab) {
    var hasC     = q.opt_c_texto != null; /* null e undefined → false; string vazia → true (mantém texto já salvo) */
    var optCRows = hasC ? (
      '<tr><th>Opção C — Texto</th><td><textarea name="opt_c_texto" rows="2" class="widefat" data-limit="100">' + esc(q.opt_c_texto) + '</textarea></td></tr>' +
      '<tr><th>Opção C — Consequência</th><td><textarea name="opt_c_cons" rows="2" class="widefat" data-limit="100">' + esc(q.opt_c_cons) + '</textarea></td></tr>'
    ) : '';

    var actions;
    if (tab === 'pending') {
      actions =
        '<button type="button" class="ng-btn-approve button button-primary">✓ Aprovar</button> ' +
        '<button type="button" class="ng-btn-discard button">✕ Descartar</button> ' +
        '<button type="button" class="ng-btn-regen button">🔄 Pedir Substituta</button>';
    } else {
      actions = '<button type="button" class="ng-btn-restore button">↩ Devolver para Revisão</button>';
    }

    var nivel    = q.level || 1;
    var tipo     = q.character_slug || 'geral';
    var metaLbl  = enableLevels ? (nivel === 2 ? 'Pro' : 'Aprendiz') : '';
    var impactoA = JSON.stringify(q.opt_a_impacto || {popularidade: 0, recursos: 0});
    var impactoB = JSON.stringify(q.opt_b_impacto || {popularidade: 0, recursos: 0});
    var impactoC = JSON.stringify(q.opt_c_impacto || {popularidade: 0, recursos: 0});

    return [
      '<div class="ng-qrow" role="listitem" data-id="' + q.id + '" data-nivel="' + nivel + '" data-tipo="' + esc(tipo) + '">',
        '<div class="ng-qrow-head">',
          '<span class="ng-qrow-num">' + num + '</span>',
          '<span class="ng-qrow-title">' + esc(q.title || '(sem título)') + '</span>',
          (metaLbl ? '<span class="ng-qrow-meta">' + esc(metaLbl) + '</span>' : ''),
          (q.axis_weights ? '<span class="ng-qrow-meta" title="Pesos de eixo atribuídos" style="color:#2d7a2d;">⚖</span>' : ''),
          '<span class="ng-qrow-chevron">›</span>',
        '</div>',
        '<div class="ng-qrow-body">',
          '<table class="ng-edit-table">',
            '<tr><th>Título</th><td><input type="text" name="q_title" class="widefat" value="' + esc(q.title) + '"></td></tr>',
            '<tr><th>Enunciado</th><td><textarea name="q_body" rows="3" class="widefat" data-limit="140">' + esc(q.body) + '</textarea></td></tr>',
            '<tr><th>Opção A — Texto</th><td><textarea name="opt_a_texto" rows="2" class="widefat" data-limit="100">' + esc(q.opt_a_texto) + '</textarea></td></tr>',
            '<tr><th>Opção A — Consequência</th><td><textarea name="opt_a_cons" rows="2" class="widefat" data-limit="100">' + esc(q.opt_a_cons) + '</textarea></td></tr>',
            '<tr><th>Opção B — Texto</th><td><textarea name="opt_b_texto" rows="2" class="widefat" data-limit="100">' + esc(q.opt_b_texto) + '</textarea></td></tr>',
            '<tr><th>Opção B — Consequência</th><td><textarea name="opt_b_cons" rows="2" class="widefat" data-limit="100">' + esc(q.opt_b_cons) + '</textarea></td></tr>',
            optCRows,
            '<tr><th>O que ocorreu</th><td><textarea name="verd_desfecho" rows="2" class="widefat">' + esc(q.verd_desfecho) + '</textarea></td></tr>',
            '<tr><th>Lição</th><td><textarea name="verd_licao" rows="2" class="widefat">' + esc(q.verd_licao) + '</textarea></td></tr>',
            '<tr><th>Síntese</th><td><textarea name="verd_sintese" rows="2" class="widefat">' + esc(q.verd_sintese) + '</textarea></td></tr>',
            '<tr><th>URL da Fonte</th><td>',
              '<input type="url" name="source_url" class="widefat" value="' + esc(q.source_url) + '">',
              (q.source_url ? '<a href="' + esc(safeUrl(q.source_url)) + '" target="_blank" rel="noopener" class="ng-source-check">Verificar fonte ↗</a>' : ''),
            '</td></tr>',
          '</table>',
          '<input type="hidden" name="opt_a_impacto" value="' + esc(impactoA) + '">',
          '<input type="hidden" name="opt_b_impacto" value="' + esc(impactoB) + '">',
          (hasC ? '<input type="hidden" name="opt_c_impacto" value="' + esc(impactoC) + '">' : ''),
          '<div class="ng-qrow-actions">',
            actions,
            '<span class="ng-action-msg"></span>',
          '</div>',
        '</div>',
      '</div>',
    ].join('');
  }

  /* ── Delegação de eventos no container ─────────────────── */
  list.addEventListener('click', function (e) {
    var head   = e.target.closest('.ng-qrow-head');
    var row    = e.target.closest('.ng-qrow');
    if (!row) return;

    if (head) { toggleRow(row, head); return; }
    if (e.target.closest('.ng-btn-approve')) { doApprove(row); return; }
    if (e.target.closest('.ng-btn-discard')) { doDiscard(row); return; }
    if (e.target.closest('.ng-btn-restore')) { doRestore(row); return; }
    if (e.target.closest('.ng-btn-regen'))   { doRegen(row);   return; }
  });

  /* ── Toggle accordion ───────────────────────────────────── */
  function toggleRow(row, head) {
    var body   = row.querySelector('.ng-qrow-body');
    var isOpen = body.style.display === 'block';

    list.querySelectorAll('.ng-qrow-body').forEach(function (b) { b.style.display = 'none'; });
    list.querySelectorAll('.ng-qrow-head').forEach(function (h) { h.classList.remove('is-open'); });

    if (!isOpen) {
      body.style.display = 'block';
      head.classList.add('is-open');
    }
  }

  /* ── Coleta campos editados da linha ────────────────────── */
  function rowFields(row) {
    var out = {};
    row.querySelectorAll('[name]').forEach(function (el) { out[el.name] = el.value; });
    return out;
  }

  /* ── Mensagem de status na linha ────────────────────────── */
  function msg(row, text, isErr) {
    var s = row.querySelector('.ng-action-msg');
    if (s) { s.textContent = text; s.style.color = isErr ? '#c00' : '#2d7a2d'; }
  }

  /* ── Remove linha com fade ──────────────────────────────── */
  function fadeOut(row) {
    row.style.transition = 'opacity 0.35s';
    row.style.opacity = '0';
    setTimeout(function () { row.remove(); }, 380);
  }

  /* ── Aprovar ────────────────────────────────────────────── */
  function doApprove(row) {
    msg(row, 'Salvando…', false);
    var data = Object.assign({
      action:      '<?php echo esc_js( Newsgame_Review::AJAX_APPROVE ); ?>',
      nonce:       nonce,
      instance_id: instId,
      question_id: row.dataset.id,
    }, rowFields(row));

    ajaxCall(data, function (r) {
      if (r.success) {
        msg(row, '✓ Aprovada!', false);
        setTimeout(function () { fadeOut(row); refreshCounts(); }, 700);
      } else {
        msg(row, '✗ ' + ((r.data && r.data.msg) || 'Erro.'), true);
      }
    });
  }

  /* ── Descartar ──────────────────────────────────────────── */
  function doDiscard(row) {
    msg(row, 'Descartando…', false);
    ajaxCall({
      action:      '<?php echo esc_js( Newsgame_Review::AJAX_DISCARD ); ?>',
      nonce:       nonce,
      instance_id: instId,
      question_id: row.dataset.id,
    }, function (r) {
      if (r.success) {
        msg(row, '✕ Descartada.', false);
        setTimeout(function () { fadeOut(row); refreshCounts(); }, 700);
      } else {
        msg(row, '✗ ' + ((r.data && r.data.msg) || 'Erro.'), true);
      }
    });
  }

  /* ── Restaurar para pendente ────────────────────────────── */
  function doRestore(row) {
    msg(row, 'Restaurando…', false);
    ajaxCall({
      action:      '<?php echo esc_js( Newsgame_Review::AJAX_RESTORE ); ?>',
      nonce:       nonce,
      instance_id: instId,
      question_id: row.dataset.id,
    }, function (r) {
      if (r.success) {
        msg(row, '↩ Devolvida para revisão.', false);
        setTimeout(function () { fadeOut(row); refreshCounts(); }, 700);
      } else {
        msg(row, '✗ ' + ((r.data && r.data.msg) || 'Erro.'), true);
      }
    });
  }

  /* ── Pedir Substituta ───────────────────────────────────── */
  function doRegen(row) {
    var btns = row.querySelectorAll('button');
    btns.forEach(function (b) { b.disabled = true; });
    msg(row, '🔄 Gerando substituta… (até 120 s)', false);

    ajaxCall({
      action:          '<?php echo esc_js( Newsgame_Review::AJAX_REGEN ); ?>',
      nonce:           nonce,
      instance_id:     instId,
      question_id:     row.dataset.id,
      personagem_tipo: row.dataset.tipo  || 'geral',
      nivel:           row.dataset.nivel || 1,
    }, function (r) {
      btns.forEach(function (b) { b.disabled = false; });
      if (r.success) {
        msg(row, '✓ Substituta criada!', false);
        // Injeta a substituta no topo da lista
        var newHtml = buildRow(r.data.question, '★', 'pending');
        var tmp = document.createElement('div');
        tmp.innerHTML = newHtml;
        var newRow = tmp.firstElementChild;
        newRow.style.opacity = '0';
        list.prepend(newRow);
        newRow.querySelectorAll('textarea[data-limit]').forEach(initCharCount);
        setTimeout(function () {
          newRow.style.transition = 'opacity 0.4s';
          newRow.style.opacity    = '1';
        }, 30);
        setTimeout(function () { fadeOut(row); refreshCounts(); }, 900);
      } else {
        msg(row, '✗ ' + ((r.data && r.data.msg) || 'Erro no backend.'), true);
      }
    });
  }

  /* ── Paginação ──────────────────────────────────────────── */
  function renderPagination(page, pages, tab) {
    if (pages <= 1) { pgWrap.innerHTML = ''; return; }
    var html = '';
    for (var i = 1; i <= pages; i++) {
      html += '<button class="ng-page-btn' + (i === page ? ' is-current' : '') + '" data-p="' + i + '">' + i + '</button>';
    }
    pgWrap.innerHTML = html;
    pgWrap.querySelectorAll('.ng-page-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        curPage[tab] = parseInt(btn.dataset.p, 10);
        loadTab(tab, curPage[tab]);
      });
    });
  }

  /* ── Atualiza contagens nas abas ────────────────────────── */
  function refreshCounts() {
    ['pending', 'approved', 'discarded'].forEach(function (tab) {
      ajaxCall({
        action:      '<?php echo esc_js( Newsgame_Review::AJAX_LOAD ); ?>',
        nonce:       nonce,
        instance_id: instId,
        status:      tab,
        page:        1,
      }, function (r) {
        if (r.success) {
          var el = document.getElementById('ng-count-' + tab);
          if (el) el.textContent = r.data.total;
        }
      });
    });
  }

  /* ── Aprovar Todas as Pendentes ─────────────────────────── */
  var approveAllBtn = document.getElementById('ng-btn-approve-all');
  if (approveAllBtn) {
    approveAllBtn.addEventListener('click', function () {
      var badge = document.getElementById('ng-count-pending');
      var count = badge ? parseInt(badge.textContent, 10) : 0;
      if (!count || isNaN(count)) { alert('Não há perguntas pendentes para aprovar.'); return; }
      if (!confirm('Aprovar todas as ' + count + ' perguntas pendentes de uma vez?')) return;

      approveAllBtn.disabled = true;
      approveAllBtn.textContent = 'Aprovando…';

      ajaxCall({
        action:      '<?php echo esc_js( Newsgame_Review::AJAX_APPROVE_ALL ); ?>',
        nonce:       nonce,
        instance_id: instId,
      }, function (r) {
        approveAllBtn.disabled = false;
        approveAllBtn.textContent = '✓ Aprovar Todas as Pendentes';
        if (r.success) {
          loadTab(curTab, curPage[curTab]);
          refreshCounts();
        } else {
          alert('Erro: ' + ((r.data && r.data.msg) || 'Tente novamente.'));
        }
      });
    });
  }

  /* ── Atribuir Pesos via IA ──────────────────────────────── */
  var assignBtn = document.getElementById('ng-btn-assign-weights');
  var assignMsg = document.getElementById('ng-assign-msg');
  if (assignBtn) {
    assignBtn.addEventListener('click', function () {
      if (!confirm('Isso chamará o Gemini para atribuir pesos de eixo a todos os ' + totalDilemas + ' dilemas desta instância. Pode levar até 60 segundos. Continuar?')) return;

      assignBtn.disabled    = true;
      assignBtn.textContent = '⏳ Atribuindo pesos…';
      if (assignMsg) assignMsg.innerHTML = '';

      ajaxCall({
        action:      'newsgame_assign_weights',
        nonce:       assignNonce,
        instance_id: instId,
      }, function (r) {
        assignBtn.disabled    = false;
        assignBtn.textContent = '⚖ Atribuir Pesos';
        if (assignMsg) {
          if (r.success) {
            assignMsg.innerHTML = '<span style="color:#2d7a2d;">✅ ' + esc(r.data.msg) + '</span>';
          } else {
            assignMsg.innerHTML = '<span style="color:#c00;">❌ ' + esc((r.data && r.data.msg) || 'Erro desconhecido.') + '</span>';
          }
        }
      });
    });
  }

  /* ── Fetch helper ───────────────────────────────────────── */
  function ajaxCall(data, cb) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fetch(ajaxurl, { method: 'POST', body: fd })
      .then(function (res) { return res.json(); })
      .then(cb)
      .catch(function (e) { console.error('[Mesa Editorial]', e); });
  }

}());
</script>
<?php endif; // is_edit && review_total > 0 ?>

</div>

<script>
(function () {
  'use strict';

  /* ── Elementos ──────────────────────────────────────────── */
  var form       = document.getElementById('ng-form');
  var list       = document.getElementById('ng-chars-list');
  var tpl        = document.getElementById('ng-char-tpl');
  var addBtn     = document.getElementById('ng-add-char');
  var jsonInput  = document.getElementById('ng-chars-json');
  var statusInput= document.getElementById('ng-status-input');
  var btnDraft   = document.getElementById('ng-btn-draft');
  var btnReview  = document.getElementById('ng-btn-review');

  /* ── Builder de personagens ─────────────────────────────── */
  // Hidrata personagens existentes (edição)
  var initial = <?php echo wp_json_encode( array_values( $chars ) ); ?>;
  initial.forEach(function (c) { addRow(c.label, c.description); });

  addBtn.addEventListener('click', function () { addRow('', ''); });

  function addRow(label, desc) {
    var clone = tpl.content.cloneNode(true);
    var row   = clone.querySelector('.ng-char-row');

    row.querySelector('.ng-char-label').value = label || '';
    row.querySelector('.ng-char-desc').value  = desc  || '';

    row.querySelector('.ng-rm').addEventListener('click', function () {
      row.remove();
      serialize();
    });

    row.querySelectorAll('input').forEach(function (inp) {
      inp.addEventListener('input', serialize);
    });

    list.appendChild(row);
    serialize();
    // Foco no novo campo para fluxo contínuo de digitação
    row.querySelector('.ng-char-label').focus();
  }

  function serialize() {
    var rows = list.querySelectorAll('.ng-char-row');
    var data = [];
    rows.forEach(function (row) {
      var label = row.querySelector('.ng-char-label').value.trim();
      var desc  = row.querySelector('.ng-char-desc').value.trim();
      if (!label) return;
      data.push({ slug: slugify(label), label: label, description: desc });
    });
    jsonInput.value = JSON.stringify(data);
  }

  function slugify(str) {
    return str.toLowerCase()
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .substring(0, 80);
  }

  /* ── Trava de troca de opções com perguntas existentes ──── */
  <?php if ( $review_total > 0 ) : ?>
  (function () {
    var radios         = document.querySelectorAll('[name="num_options"]');
    var currentOptions = '<?php echo (int) $num_options; ?>';
    radios.forEach(function (radio) {
      radio.addEventListener('change', function () {
        if (radio.value === currentOptions) return;
        var ok = confirm(
          'Atenção: você está trocando de ' + currentOptions + ' para ' + radio.value + ' opções de resposta.\n\n' +
          'Esta instância já possui <?php echo (int) $review_total; ?> pergunta(s) gerada(s) com ' + currentOptions + ' alternativas — elas ficarão incompatíveis com o novo formato.\n\n' +
          'Recomendamos limpar e regenerar todas as perguntas após salvar. Continuar com a troca?'
        );
        if (!ok) {
          radios.forEach(function (r) { r.checked = r.value === currentOptions; });
        } else {
          currentOptions = radio.value;
        }
      });
    });
  }());
  <?php endif; ?>

  /* ── Botões de ação ─────────────────────────────────────── */
  btnDraft.addEventListener('click', function () {
    statusInput.value = 'draft';
    serialize();
    form.submit();
  });

  btnReview.addEventListener('click', function () {
    var titleVal = document.getElementById('ng-title').value.trim();
    if (!titleVal) {
      document.getElementById('ng-title').focus();
      document.getElementById('ng-title').reportValidity();
      return;
    }
    statusInput.value = 'review';
    serialize();
    form.submit();
  });
}());
</script>
