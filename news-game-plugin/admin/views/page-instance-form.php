<?php
defined( 'ABSPATH' ) || exit;

// Carrega instância existente (edição) ou define defaults (novo)
$id      = (int) ( $_GET['id'] ?? 0 );
$inst    = $id ? Newsgame_Admin_Instances::get( $id ) : null;
$is_edit = (bool) $inst;

$title   = $is_edit ? $inst->title        : '';
$theme   = $is_edit ? $inst->theme_prompt : '';
$density = $is_edit ? (int) $inst->density : 20;
$status  = $is_edit ? $inst->status       : 'draft';
$chars   = $is_edit ? (array) json_decode( $inst->characters ?? '[]', true ) : array();

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
        </td>
      </tr>
    </table>

    <!-- ── Configuração ──────────────────────────────────── -->
    <h2 class="ng-section-title">Configuração</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row">
          <label for="ng-density">Número de Perguntas</label>
        </th>
        <td>
          <input type="range" id="ng-density" name="density"
                 value="<?php echo (int) $density; ?>"
                 min="20" max="120" step="5"
                 style="width:220px;vertical-align:middle">
          &nbsp;<span id="ng-density-display"><?php echo (int) $density; ?></span> dilemas
          <p class="description">
            Cada dilema tem 2 alternativas + veredito.
            Mais dilemas = mais tempo de geração pelo Gemini.
            Mínimo: 20 &nbsp;|&nbsp; Máximo: 120
          </p>
        </td>
      </tr>
    </table>

    <!-- ── Personagens ───────────────────────────────────── -->
    <h2 class="ng-section-title">Personagens do Jogo</h2>
    <p class="description" style="margin-bottom:0.75rem">
      Defina quem são as personas desta instância. Cada personagem representa um
      ponto de vista nos dilemas gerados pelo Gemini.<br>
      Exemplo: <em>Diretor de Teatro, Ator, Produtor Executivo, Crítico Cultural.</em>
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
    $dilema_count = (int) $GLOBALS['wpdb']->get_var(
        $GLOBALS['wpdb']->prepare(
            "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}newsgame_dilemas WHERE instance_id = %d",
            $id
        )
    );
  ?>
  <!-- ── Seção de Geração ───────────────────────────────── -->
  <hr style="margin:2rem 0 1.5rem">
  <h2>Geração de Dilemas</h2>

  <?php if ( $dilema_count > 0 ) : ?>
    <div class="notice notice-info inline" style="margin:0 0 1rem">
      <p>Esta instância já possui <strong><?php echo esc_html( $dilema_count ); ?> dilemas</strong> gerados. Gerar novamente adicionará ao conjunto existente.</p>
    </div>
  <?php endif; ?>

  <p class="description" style="margin-bottom:1rem">
    O backend buscará no ChromaDB os trechos relevantes para cada personagem,
    enviará ao Gemini e salvará os dilemas em <code>wp_newsgame_dilemas</code>.
    Densidade: <strong><?php echo (int) $density; ?> dilemas</strong>.
  </p>

  <button type="button" id="ng-generate-btn" class="button button-primary button-large">
    &#9654; Gerar Dilemas Agora
  </button>
  <span id="ng-gen-status" style="margin-left:12px;font-size:13px;font-weight:600"></span>

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
    var genBtn    = document.getElementById('ng-generate-btn');
    var genStatus = document.getElementById('ng-gen-status');
    var wrap      = document.getElementById('ng-progress-wrap');
    var pLabel    = document.getElementById('ng-progress-label');
    var pPct      = document.getElementById('ng-progress-pct');
    var pBar      = document.getElementById('ng-progress-bar');
    var pLast     = document.getElementById('ng-progress-last');
    var instId    = <?php echo (int) $id; ?>;
    var nonce     = '<?php echo esc_js( wp_create_nonce( Newsgame_Generator::NONCE_GENERATE ) ); ?>';

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

    /* ── Clique no botão: pede config ao WP e abre stream ── */
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
          genStatus.textContent = 'Conectando ao backend…';
          startStream(r.data.backend_url, r.data.api_key, r.data.payload);
        })
        .catch(function (e) { setError(e.message); });
    });

    /* ── Stream SSE direto do backend Python ─────────────── */
    function startStream(backendUrl, apiKey, payload) {
      var dilemas  = [];
      var total    = payload.density || 20;
      var saved    = false;

      wrap.style.display = 'block';
      pLabel.textContent = 'Aguardando backend…';
      setBar(0, total);

      fetch(backendUrl.replace(/\/$/, '') + '/generate', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-Api-Key': apiKey },
        body:    JSON.stringify(payload),
      })
      .then(function (response) {
        if (!response.ok) {
          return response.text().then(function (t) {
            throw new Error('HTTP ' + response.status + ': ' + t.substring(0, 200));
          });
        }

        var reader  = response.body.getReader();
        var decoder = new TextDecoder('utf-8');
        var buf     = '';

        function pump() {
          return reader.read().then(function (chunk) {
            if (chunk.done) {
              // Stream encerrado antes do evento "done" (conexão caiu)
              if (!saved && dilemas.length > 0) saveMatrix(dilemas);
              return;
            }

            buf += decoder.decode(chunk.value, { stream: true });

            // SSE separa eventos com \n\n
            var parts = buf.split('\n\n');
            buf = parts.pop(); // fragmento incompleto fica no buffer

            parts.forEach(function (raw) {
              if (raw.indexOf('data: ') !== 0) return;
              var ev;
              try { ev = JSON.parse(raw.slice(6)); } catch (e) { return; }

              if (ev.type === 'start') {
                total = ev.total;
                pLabel.textContent = 'Gerando dilemas…';
                setBar(0, total);

              } else if (ev.type === 'dilema') {
                dilemas.push(ev.dilema);
                setBar(ev.current, ev.total);
                pLabel.textContent = 'Gerando dilemas…';
                if (ev.dilema && ev.dilema.titulo) {
                  pLast.textContent = 'Último: "' + ev.dilema.titulo + '"';
                }

              } else if (ev.type === 'rate_limit') {
                pLabel.textContent = 'Aguardando rate limit (' + (ev.sleep || 60) + 's)…';

              } else if (ev.type === 'error') {
                setError(ev.msg || 'Erro desconhecido no backend.');

              } else if (ev.type === 'done') {
                saved = true;
                pLabel.textContent = 'Salvando no banco de dados…';
                saveMatrix(dilemas);
              }
            });

            return pump();
          });
        }

        return pump();
      })
      .catch(function (e) {
        setError('Conexão com o backend falhou: ' + e.message);
      });
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
            setDone(r.data.saved || dilemas.length);
          } else {
            setError('Dilemas gerados mas erro ao salvar: ' + (r.data && r.data.msg ? r.data.msg : 'erro desconhecido'));
          }
        })
        .catch(function (e) {
          setError('Dilemas gerados, mas falha de rede ao salvar: ' + e.message);
        });
    }
  }());
  </script>

  <?php endif; // is_edit && backend_ok ?>
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
  var densSlider = document.getElementById('ng-density');
  var densDisplay= document.getElementById('ng-density-display');
  var statusInput= document.getElementById('ng-status-input');
  var btnDraft   = document.getElementById('ng-btn-draft');
  var btnReview  = document.getElementById('ng-btn-review');

  /* ── Slider de densidade ────────────────────────────────── */
  densSlider.addEventListener('input', function () {
    densDisplay.textContent = this.value;
  });

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
