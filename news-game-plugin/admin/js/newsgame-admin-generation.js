/**
 * News-Game — geração de perguntas e reset da instância (admin).
 *
 * Extraído do <script> inline de admin/views/page-instance-form.php pelo A3
 * (FLAG 007 passo 3). Dois motivos: JS inline exige 'unsafe-inline' em
 * script-src, e o ESLint não lê JS dentro de PHP — este arquivo passa a entrar
 * na trava de complexidade do `npm run lint`.
 *
 * Os dados dinâmicos chegam em `ngGenCfg`, por wp_localize_script em
 * Newsgame_Admin_Instances::enqueue_form_scripts(). Nenhum valor de PHP é
 * interpolado aqui, e o esc_js() das quatro linhas de action desapareceu junto:
 * o JSON agora é gerado e escapado pelo WordPress.
 *
 * A seção de geração só é renderizada quando a instância já foi salva E o
 * backend Python está configurado. A guarda de âncora abaixo é o que permite
 * enfileirar este arquivo na tela de formulário inteira, sem repetir essa
 * condição no PHP.
 */
(function () {
  'use strict';

  var cfg = window.ngGenCfg || {};

  var genBtn      = document.getElementById('ng-generate-btn');
  var genStatus   = document.getElementById('ng-gen-status');
  var resetBtn    = document.getElementById('ng-reset-btn');
  var wrap        = document.getElementById('ng-progress-wrap');
  var pLabel      = document.getElementById('ng-progress-label');
  var pPct        = document.getElementById('ng-progress-pct');
  var pBar        = document.getElementById('ng-progress-bar');
  var pLast       = document.getElementById('ng-progress-last');
  var instId      = cfg.instanceId;
  var nonce       = cfg.nonce;
  var reviewNonce = cfg.reviewNonce;

  // Âncora única: #ng-generate-btn só existe quando a seção de geração é
  // renderizada. Se ele está no DOM, a barra de progresso e o status também
  // estão — são irmãos no mesmo bloco de markup. O #ng-reset-btn é o único
  // condicional de verdade, e tem guarda própria lá embaixo.
  if (!genBtn) { return; }

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
    fd.append('action',      cfg.actionStart);
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
      fetch(ajaxurl + '?action=' + encodeURIComponent(cfg.actionGenerateOne) + '&nonce=' + encodeURIComponent(nonce), {
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
    fd.append('action',      cfg.actionSave);
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
      fd.append('action',      cfg.actionClear);
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
