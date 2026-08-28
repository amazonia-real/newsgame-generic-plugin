/**
 * News-Game — analisar acervo e salvar a matriz de eixos (admin).
 *
 * Extraído do <script> inline de admin/views/page-instance-form.php pelo A3
 * (FLAG 007 passo 3). Dois motivos: JS inline exige 'unsafe-inline' em
 * script-src, e o ESLint não lê JS dentro de PHP — este arquivo passa a entrar
 * na trava de complexidade do `npm run lint`.
 *
 * Os dados dinâmicos chegam em `ngAxisCfg`, por wp_localize_script em
 * Newsgame_Admin_Instances::enqueue_form_scripts().
 *
 * Duas mudanças de forma, na extração, ambas registradas no plano do A3:
 *
 * 1. O bloco inline era o ÚNICO dos cinco que não estava em IIFE —
 *    `ngAxisNonce`, `ngAnalyzeNonce`, `ngInstanceId` e `ngBindSalvarBtn` eram
 *    globais no `window`. Conferido por grep em todo o plugin que nada fora do
 *    próprio bloco os referenciava. Agora são locais.
 * 2. `acharCampoAcervo()` foi extraída: a cadeia de cinco `||` sozinha levava o
 *    handler de clique a complexidade 12, acima do limite 10 (medido com o
 *    ESLint antes de mover). Com a extração, helper 6 e handler 7. A ordem dos
 *    seletores e o curto-circuito são os mesmos de antes.
 */
(function () {
  'use strict';

  var cfg            = window.ngAxisCfg || {};
  var ngAxisNonce    = cfg.axisNonce;
  var ngAnalyzeNonce = cfg.analyzeNonce;
  var ngInstanceId   = cfg.instanceId;

  /* Procura o campo de descrição do acervo tolerando os nomes históricos.
     Ver o item 2 do cabeçalho: a existência desta função é a trava de
     complexidade, não preferência de estilo. */
  function acharCampoAcervo() {
    return document.querySelector('#theme_prompt') ||
      document.querySelector('[name="theme_prompt"]') ||
      document.querySelector('#context_text') ||
      document.querySelector('[name="context_text"]') ||
      document.querySelector('#acervo') ||
      document.querySelector('[name="acervo"]');
  }

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

      var campoAcervo = acharCampoAcervo();

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
            'Verifique, nesta ordem:<br>' +
            '1. O backend Python (uvicorn) está rodando?<br>' +
            '2. O <em>Token do Backend</em> em News-Game › Configurações é ' +
            'igual ao <code>API_SECRET_TOKEN</code> do <code>.env</code>?<br>' +
            '3. A <em>URL do Backend Python</em> em News-Game › Configurações ' +
            'está correta e acessível a partir deste servidor WordPress?' +
          '</div>';
      } finally {
        btn.disabled = false;
        btn.textContent = '🔍 Analisar Acervo e Propor Eixos';
      }

    });
  });
}());
