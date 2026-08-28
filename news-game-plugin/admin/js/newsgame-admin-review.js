/**
 * News-Game — Mesa Editorial: revisar, editar, aprovar e pedir substituta.
 *
 * Extraído do <script> inline de admin/views/page-instance-form.php pelo A3
 * (FLAG 007 passo 3). Dois motivos: JS inline exige 'unsafe-inline' em
 * script-src, e o ESLint não lê JS dentro de PHP — este arquivo passa a entrar
 * na trava de complexidade do `npm run lint`. É o maior dos cinco blocos que o
 * A3 moveu: ~510 linhas que estavam invisíveis para o lint.
 *
 * Os dados dinâmicos chegam em `ngReviewCfg`, por wp_localize_script em
 * Newsgame_Admin_Instances::enqueue_form_scripts(). Nenhum valor de PHP é
 * interpolado aqui, e o esc_js() das sete linhas de action desapareceu junto: o
 * JSON agora é gerado e escapado pelo WordPress.
 *
 * ⚠️ Nada aqui foi validado em runtime antes do A3 — o CLAUDE.md registra
 * "Mesa Editorial: accordions, títulos, body e campos de edição: código escrito,
 * não validado". A extração é movimento de código, não conserto: se algo estava
 * quebrado antes, continua quebrado do mesmo jeito.
 */
(function () {
  'use strict';

  var cfg           = window.ngReviewCfg || {};
  var instId        = cfg.instanceId;
  /* `numOptions` existia aqui e não era usado em lugar nenhum do bloco — era a
     única linha de código que o A3 apagou em vez de mover. Quem decide 2 ou 3
     alternativas na tela é `q.opt_c_texto != null`, dilema a dilema. */
  var enableLevels  = !!cfg.enableLevels;   /* localize devolve "1" ou "" */
  var nonce         = cfg.nonce;
  var assignNonce   = cfg.assignNonce;
  var totalDilemas  = cfg.totalDilemas;
  var curTab     = 'pending';
  var curPage    = { pending: 1, approved: 1, discarded: 1 };
  var list       = document.getElementById('ng-review-list');
  var pgWrap     = document.getElementById('ng-review-pagination');

  // Âncora única: a Mesa Editorial só é renderizada com dilemas no banco. Se
  // #ng-review-list está no DOM, a paginação e as abas também estão — são
  // irmãos no mesmo bloco de markup. É esta guarda que permite enfileirar o
  // arquivo na tela de formulário inteira, sem repetir a condição no PHP.
  if (!list) { return; }

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
      action:      cfg.actionLoad,
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

  /* ── Constrói HTML de uma linha accordion ─────────────────
     Complexidade 15, medida com o ESLint antes de mover — acima do limite 10.
     Diretiva e não refatoração, pelo mesmo critério que o A4 aplicou ao
     buildRadarSVG: os 15 são 14 ternários e `||` de default montando string de
     HTML, sem um `if`, `for` ou `case` no corpo. Quebrar em helpers no mesmo
     passo em que a função muda de arquivo tornaria impossível saber, se a tela
     quebrar, qual das duas mudanças quebrou — e esta é justamente a massa de JS
     que nunca foi validada em runtime. Fica como dívida declarada. */
  // eslint-disable-next-line complexity
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
            '<tr><th>Imagem de fundo</th><td>', imagemCampo(q), '</td></tr>',
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

  /* ── Campo de imagem de fundo do dilema ────────────────────
     O `image_id` vai num hidden com `name`, então é recolhido de graça pela
     serialização genérica de `[name]` que já existe abaixo.
     O crédito vem da LEGENDA do anexo e é exibido aqui de propósito: legenda
     vazia significa foto sem atribuição, e isso tem de aparecer na hora de
     escolher, não depois de publicar. */
  function imagemCampo(q) {
    var temImg   = !!q.image_id;
    var credito  = q.image_credito || '';
    var avisoSem = '<span class="ng-img-sem-credito">sem crédito — preencha a legenda do anexo na Biblioteca de Mídia</span>';

    return [
      '<div class="ng-img-field">',
        '<input type="hidden" name="image_id" value="' + esc(String(q.image_id || '')) + '">',
        '<span class="ng-img-preview">',
          (temImg && q.image_thumb
            ? '<img src="' + esc(safeUrl(q.image_thumb)) + '" alt="">'
            : '<span class="ng-img-vazio">sem imagem</span>'),
        '</span>',
        '<span class="ng-img-controls">',
          '<button type="button" class="button ng-img-pick">' + (temImg ? 'Trocar imagem' : 'Escolher imagem') + '</button>',
          (temImg ? ' <button type="button" class="button-link ng-img-clear">Remover</button>' : ''),
          '<span class="ng-img-credito">' + (temImg ? (credito ? 'Crédito: ' + esc(credito) : avisoSem) : '') + '</span>',
        '</span>',
      '</div>',
    ].join('');
  }

  /* Redesenha o campo a partir do estado atual, sem recarregar a linha. */
  function pintarImagem(field, id, thumbUrl, credito) {
    var hidden  = field.querySelector('input[name="image_id"]');
    var preview = field.querySelector('.ng-img-preview');
    var pick    = field.querySelector('.ng-img-pick');
    var creditoEl = field.querySelector('.ng-img-credito');
    var clear   = field.querySelector('.ng-img-clear');

    hidden.value = id ? String(id) : '';

    if (id) {
      preview.innerHTML = thumbUrl
        ? '<img src="' + esc(safeUrl(thumbUrl)) + '" alt="">'
        : '<span class="ng-img-vazio">sem miniatura</span>';
      pick.textContent = 'Trocar imagem';
      creditoEl.innerHTML = credito
        ? 'Crédito: ' + esc(credito)
        : '<span class="ng-img-sem-credito">sem crédito — preencha a legenda do anexo na Biblioteca de Mídia</span>';
      if (!clear) {
        pick.insertAdjacentHTML('afterend', ' <button type="button" class="button-link ng-img-clear">Remover</button>');
      }
    } else {
      preview.innerHTML   = '<span class="ng-img-vazio">sem imagem</span>';
      pick.textContent    = 'Escolher imagem';
      creditoEl.innerHTML = '';
      if (clear) { clear.remove(); }
    }
  }

  /* Um frame de mídia POR CAMPO, reaproveitado.
     A primeira versão criava um `wp.media(...)` novo a cada clique, sem
     reaproveitar nem destruir: vazava frame e acumulava handler de `select` a
     cada abertura. WeakMap e não propriedade no elemento porque `buildRow()`
     recria as linhas a cada paginação e troca de aba — com WeakMap, o campo
     antigo sai de escopo e o frame vai com ele. */
  var seletores = new WeakMap();

  function abrirSeletor(field) {
    if (typeof wp === 'undefined' || !wp.media) {
      window.alert('O seletor de mídia do WordPress não carregou nesta tela. Recarregue a página.');
      return;
    }

    var frame = seletores.get(field);

    if (!frame) {
      frame = wp.media({
        title:    'Imagem de fundo do dilema',
        button:   { text: 'Usar esta imagem' },
        library:  { type: 'image' },
        multiple: false,
      });

      frame.on('select', function () {
        var att   = frame.state().get('selection').first().toJSON();
        var thumb = (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) || att.url || '';
        // att.caption é a legenda do anexo — a mesma que o shortcode lê no render.
        pintarImagem(field, att.id, thumb, att.caption || '');
      });

      seletores.set(field, frame);
    }

    frame.open();
  }

  /* ── Delegação de eventos no container ─────────────────── */
  list.addEventListener('click', function (e) {
    var imgField = e.target.closest('.ng-img-field');
    if (imgField) {
      if (e.target.closest('.ng-img-pick'))  { e.preventDefault(); abrirSeletor(imgField); return; }
      if (e.target.closest('.ng-img-clear')) { e.preventDefault(); pintarImagem(imgField, 0, '', ''); return; }
    }
  });

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
      action:      cfg.actionApprove,
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
      action:      cfg.actionDiscard,
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
      action:      cfg.actionRestore,
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
      action:          cfg.actionRegen,
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
        action:      cfg.actionLoad,
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
        action:      cfg.actionApproveAll,
        nonce:       nonce,
        instance_id: instId,
      }, function (r) {
        approveAllBtn.disabled = false;
        approveAllBtn.textContent = '✓ Aprovar Todas as Pendentes';
        if (r.success) {
          if (r.data && r.data.warning) { alert('⚠ ' + r.data.warning); }
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
