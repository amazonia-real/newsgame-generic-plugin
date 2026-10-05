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

  // Tetos do lote — decididos em 02/10. O plano gratuito do Gemini tem 20
  // requisições por DIA, então cada chamada desperdiçada é cara.
  var MAX_TENTATIVAS  = 3;  // por posição: a 1ª chamada + 2 retentativas
  var FOLGA_CHAMADAS  = 3;  // teto global de chamadas do lote = déficit + 3
  var MAX_ESPERAS_429 = 3;  // esperas de 60 s por lote; na 4ª, o lote para
  var CHAVE_RELATORIO = 'ngGenRelatorio:' + instId;

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

  /* ── Clique: o WP devolve déficit, posições livres e fontes já usadas ── */
  // O botão fica desabilitado do clique até o fim do lote: só setError() e
  // concluir() o reabilitam — e concluir() não o reabilita quando vai recarregar.
  genBtn.addEventListener('click', function () {
    genBtn.disabled = true;
    esconderRelatorio();
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
        var posicoes = r.data.posicoes_livres || [];
        if (!posicoes.length) {
          setError('O servidor não devolveu posições livres.');
          return;
        }
        wrap.style.display = 'block';
        setBar(0, posicoes.length);
        genStatus.textContent = 'Conectando ao backend…';
        iniciarLote(r.data.payload, posicoes, r.data.fontes_usadas || []);
      })
      .catch(function (e) { setError(e.message); });
  });

  /* ── Lote: um estado só, um passo por chamada ao proxy ── */
  function novoEstado(posicoes, fontes) {
    return {
      fila:         posicoes.slice(),   // posições ainda por gerar, na ordem
      alvo:         posicoes.length,
      tentativas:   {},                 // posição → chamadas que falharam
      chamadas:     0,                  // chamadas que chegaram ao provedor
      teto:         posicoes.length + FOLGA_CHAMADAS,
      esperas:      0,                  // esperas de 60 s após 429
      dilemas:      [],
      fontes:       fontes.slice(),     // semeadas pelo servidor, crescem no lote
      falhas:       [],                 // { posicao, motivo } — vão para o relatório
      interrompido: ''                  // motivo da parada antecipada, se houve
    };
  }

  function iniciarLote(basePayload, posicoes, fontes) {
    var st = novoEstado(posicoes, fontes);

    function passo() {
      var pos = proximaPosicao(st);
      if (pos === null) { finalizarLote(st); return; }
      var feitas = st.alvo - st.fila.length;
      setBar(feitas, st.alvo);
      pLabel.textContent = 'Gerando a pergunta da posição ' + pos +
        ' (' + (feitas + 1) + ' de ' + st.alvo + ')…';
      chamarPosicao(basePayload, st, pos).then(function (res) {
        setTimeout(passo, aplicarResultado(st, pos, res));
      });
    }

    passo();
  }

  // Próxima posição da fila, ou null quando o lote acabou. Teto de chamadas
  // atingido move o resto da fila para as falhas: o relatório diz o que faltou.
  function proximaPosicao(st) {
    if (st.interrompido || !st.fila.length) { return null; }
    if (st.chamadas < st.teto) { return st.fila[0]; }
    st.fila.forEach(function (p) {
      st.falhas.push({ posicao: p, motivo: 'teto de ' + st.teto + ' chamadas do lote atingido' });
    });
    st.fila = [];
    return null;
  }

  function chamarPosicao(basePayload, st, pos) {
    // slot_index = posição − 1: o backend grava position = slot_index + 1 (rota
    // /generate-one em main.py), e a rotação de personagens segue a posição.
    var payload = Object.assign({}, basePayload, {
      slot_index:    pos - 1,
      fontes_usadas: st.fontes
    });

    // Proxy PHP (admin-ajax) → backend Python. action/nonce na query
    // string porque o body carrega o payload JSON cru.
    return fetch(ajaxurl + '?action=' + encodeURIComponent(cfg.actionGenerateOne) + '&nonce=' + encodeURIComponent(nonce), {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    })
      .then(classificarResposta)
      .catch(function (e) {
        return { tipo: 'retentavel', motivo: 'falha de rede (' + e.message + ')' };
      });
  }

  // Traduz a resposta HTTP do proxy num tipo que aplicarResultado() entende.
  // ⚠️ 502 é RECUSA e para o lote: o backend usa 502 para chave rejeitada,
  // crédito esgotado e cota longa, e o proxy usa 502 para backend inalcançável.
  // Em nenhum dos quatro a próxima posição teria sorte melhor.
  function classificarResposta(response) {
    if (response.status === 429) { return { tipo: 'espera' }; }
    if (response.status === 422) { return { tipo: 'retentavel', motivo: 'pergunta inválida' }; }
    if (response.status === 502) {
      return response.json()
        .catch(function () { return {}; })
        .then(function (b) {
          return { tipo: 'recusa', motivo: (b && b.detail) || 'o provedor de IA recusou a chamada' };
        });
    }
    if (!response.ok) { return { tipo: 'retentavel', motivo: 'HTTP ' + response.status }; }
    return response.json().then(function (d) { return { tipo: 'ok', dilema: d }; });
  }

  // Aplica o resultado ao estado e devolve a espera, em ms, até o próximo passo.
  function aplicarResultado(st, pos, res) {
    if (res.tipo === 'espera') { return registrarEspera(st); }
    if (res.tipo === 'recusa') { st.interrompido = res.motivo; return 0; }
    st.chamadas++;
    if (res.tipo === 'ok') { registrarSucesso(st, res.dilema); return 600; }
    registrarFalha(st, pos, res.motivo);
    return 600;
  }

  // 429 é o throttle do proxy e não chega ao provedor, então não conta no teto
  // de chamadas — mas tem teto próprio, para o lote não esperar para sempre.
  function registrarEspera(st) {
    st.esperas++;
    if (st.esperas > MAX_ESPERAS_429) {
      st.interrompido = 'limite de uso do provedor (' + MAX_ESPERAS_429 +
        ' esperas de 60 s no mesmo lote)';
      return 0;
    }
    pLabel.textContent = 'Limite de requisições — aguardando 60 s (' +
      st.esperas + ' de ' + MAX_ESPERAS_429 + ')…';
    return 60000;
  }

  function registrarSucesso(st, dilema) {
    st.dilemas.push(dilema);
    st.fila.shift();
    var fonte = (dilema.info_contextual && dilema.info_contextual.link_original) || dilema.source_url;
    if (fonte) { st.fontes.push(fonte); }
    if (dilema.titulo) { pLast.textContent = 'Último: "' + dilema.titulo + '"'; }
  }

  // Falha retentável: a posição fica na frente da fila até MAX_TENTATIVAS.
  function registrarFalha(st, pos, motivo) {
    st.tentativas[pos] = (st.tentativas[pos] || 0) + 1;
    if (st.tentativas[pos] < MAX_TENTATIVAS) {
      pLast.textContent = 'Posição ' + pos + ': ' + motivo + ' — tentando de novo (' +
        st.tentativas[pos] + ' de ' + MAX_TENTATIVAS + ').';
      return;
    }
    st.fila.shift();
    st.falhas.push({ posicao: pos, motivo: motivo + ' após ' + MAX_TENTATIVAS + ' tentativas' });
  }

  function finalizarLote(st) {
    // Fila com sobra só existe quando o lote foi interrompido.
    st.fila.forEach(function (p) { st.falhas.push({ posicao: p, motivo: 'não gerada (lote interrompido)' }); });
    st.fila = [];
    setBar(st.alvo, st.alvo);
    if (!st.dilemas.length) { concluir(st, 0, 0); return; }
    saveMatrix(st);
  }

  /* ── Salva o lote no WordPress via AJAX ──────────────── */
  function saveMatrix(st) {
    pLabel.textContent = 'Salvando ' + st.dilemas.length + ' pergunta(s)…';

    var fd = new FormData();
    fd.append('action',      cfg.actionSave);
    fd.append('nonce',       nonce);
    fd.append('instance_id', instId);
    fd.append('dilemas',     JSON.stringify(st.dilemas));

    fetch(ajaxurl, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r.success) {
          setError('Erro ao salvar: ' + (r.data && r.data.msg ? r.data.msg : 'verifique o log do servidor.'));
          return;
        }
        var saved  = r.data && r.data.saved  != null ? r.data.saved  : st.dilemas.length;
        var failed = r.data && r.data.failed != null ? r.data.failed : 0;
        concluir(st, saved, failed);
      })
      .catch(function (e) {
        setError('Falha de rede ao salvar: ' + e.message);
      });
  }

  /* ── Relatório: fica na tela até a próxima ação ──────── */
  // Quando algo foi salvo, a tela recarrega — a Mesa Editorial está nela e
  // precisa mostrar as pendentes novas. O relatório atravessa o reload pelo
  // sessionStorage e é apagado de lá assim que aparece.
  function concluir(st, saved, failed) {
    var rel = montarRelatorio(st, saved, failed);
    pLabel.textContent = rel.faltam > 0 ? 'Geração concluída com pendências.' : '✓ Geração concluída!';
    if (saved > 0) {
      guardarRelatorio(rel);
      genStatus.style.color = '#2d7a2d';
      genStatus.textContent = '✓ ' + saved + ' salva(s). Recarregando…';
      setTimeout(function () { window.location.reload(); }, 2500);
      return; // botão segue desabilitado até o reload
    }
    genStatus.textContent = '';
    mostrarRelatorio(rel, true);
    genBtn.disabled = false;
  }

  function montarRelatorio(st, saved, failed) {
    var linhas = ['Salvas ' + saved + ' de ' + st.alvo + ' pergunta(s) que faltavam para a meta.'];
    if (failed > 0) {
      linhas.push(failed + ' gerada(s) não foram gravadas no banco — veja o log do servidor (error_log).');
    }
    if (st.interrompido) { linhas.push('Lote interrompido: ' + st.interrompido); }
    st.falhas.forEach(function (f) { linhas.push('Posição ' + f.posicao + ': ' + f.motivo + '.'); });
    return { linhas: linhas, faltam: st.alvo - saved, alerta: saved < st.alvo || failed > 0 };
  }

  // `atualizarBotao` só no relatório sem reload: depois do reload o rótulo do
  // botão já vem do PHP, contado no banco, que é a fonte de verdade.
  function mostrarRelatorio(rel, atualizarBotao) {
    var box = document.getElementById('ng-gen-report');
    if (!box) { return; }
    box.className = 'notice inline ' + (rel.alerta ? 'notice-warning' : 'notice-success');
    box.textContent = '';
    rel.linhas.forEach(function (t) {
      var p = document.createElement('p');
      p.textContent = t; // textContent: o `detail` do backend nunca vira HTML
      box.appendChild(p);
    });
    box.style.display = 'block';
    if (atualizarBotao && rel.faltam > 0) {
      genBtn.textContent = '▶ Gerar as que faltam (' + rel.faltam + ')';
    }
  }

  function esconderRelatorio() {
    var box = document.getElementById('ng-gen-report');
    if (box) { box.style.display = 'none'; }
    try { sessionStorage.removeItem(CHAVE_RELATORIO); } catch (e) { /* storage indisponível */ }
  }

  function guardarRelatorio(rel) {
    try {
      sessionStorage.setItem(CHAVE_RELATORIO, JSON.stringify(rel));
    } catch (e) { /* sem storage, o relatório só não sobrevive ao reload */ }
  }

  // No carregamento: mostra uma vez o relatório do lote que provocou o reload.
  function restaurarRelatorio() {
    var bruto = null;
    try {
      bruto = sessionStorage.getItem(CHAVE_RELATORIO);
      sessionStorage.removeItem(CHAVE_RELATORIO);
    } catch (e) { return; }
    if (!bruto) { return; }
    try { mostrarRelatorio(JSON.parse(bruto), false); } catch (e) { /* JSON corrompido: ignora */ }
  }

  restaurarRelatorio();

  /* ── Painel em dia sem F5, depois de ações na Mesa Editorial ── */
  // A Mesa já reconta as abas a cada ação e avisa por `ng:contagens`. Aqui se
  // refaz a conta do PHP (page-instance-form.php): meta − aprovadas − pendentes.
  // A meta é a gravada (cfg.meta), a mesma do ajax_start().
  function textoMeta(deficit) {
    if (deficit <= 0) { return 'A meta já está preenchida.'; }
    if (deficit === 1) { return 'Será gerada apenas 1 pergunta, a que falta.'; }
    return 'Serão geradas apenas as ' + deficit + ' que faltam.';
  }

  function pintarPainel(c) {
    var deficit = (parseInt(cfg.meta, 10) || 0) - c.approved - c.pending;
    var linha   = document.getElementById('ng-gen-contagem');
    if (linha) {
      linha.textContent = c.approved + c.pending > 0
        ? 'Aprovadas: ' + c.approved + ', pendentes: ' + c.pending + '. ' + textoMeta(deficit)
        : '';
    }
    genBtn.textContent = c.approved + c.pending + c.discarded > 0 && deficit > 0
      ? '▶ Gerar as que faltam (' + deficit + ')'
      : '▶ Gerar Perguntas Agora';
  }

  document.addEventListener('ng:contagens', function (ev) {
    // Lote em curso, ou recarga agendada: o rótulo é do lote, não da Mesa.
    if (genBtn.disabled) { return; }
    pintarPainel(ev.detail);
  });

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
