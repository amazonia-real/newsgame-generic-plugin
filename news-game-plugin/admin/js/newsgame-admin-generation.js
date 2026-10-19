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

  // Um botão por grupo (item 58): "geral" ou o slug de um personagem, no
  // data-grupo. Um lote por vez: enquanto um roda, todos ficam travados.
  var botoes      = Array.prototype.slice.call(document.querySelectorAll('.ng-gen-grupo'));
  var ativo       = null;               // botão do lote em curso
  var genStatus   = document.getElementById('ng-gen-status');
  var resetBtn    = document.getElementById('ng-reset-btn');
  var wrap        = document.getElementById('ng-progress-wrap');
  var pLabel      = document.getElementById('ng-progress-label');
  var pPct        = document.getElementById('ng-progress-pct');
  var pBar        = document.getElementById('ng-progress-bar');
  var pLast       = document.getElementById('ng-progress-last');
  var pararBtn    = document.getElementById('ng-gen-parar');
  var instId      = cfg.instanceId;
  var nonce       = cfg.nonce;
  var reviewNonce = cfg.reviewNonce;

  // Âncora única: #ng-gen-status só existe quando a seção de geração é
  // renderizada. Se ele está no DOM, a barra de progresso também está — são
  // irmãos no mesmo bloco de markup. Os botões de grupo podem faltar todos
  // (grupos prontos ou só com pendentes), e o reset tem guarda própria.
  if (!genStatus) { return; }

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

  function travarBotoes(travar) {
    botoes.forEach(function (b) { b.disabled = travar; });
    if (!travar) { ativo = null; }
  }

  function setError(msg) {
    genStatus.style.color = '#c00';
    genStatus.textContent = '✗ ' + msg;
    travarBotoes(false);
  }

  /* ── Clique: o WP devolve déficit, posições livres e fontes já usadas ── */
  // Os botões ficam desabilitados do clique até o fim do lote: só setError() e
  // concluir() os reabilitam — e concluir() não o faz quando vai recarregar.
  botoes.forEach(function (b) {
    b.addEventListener('click', function () { iniciarGrupo(b); });
  });

  function iniciarGrupo(botao) {
    travarBotoes(true);
    ativo = botao;
    esconderRelatorio();
    genStatus.style.color = '#666';
    genStatus.textContent = 'Iniciando — ' + botao.dataset.rotulo + '…';
    wrap.style.display = 'none';

    var fd = new FormData();
    fd.append('action',      cfg.actionStart);
    fd.append('nonce',       nonce);
    fd.append('instance_id', instId);
    fd.append('grupo',       botao.dataset.grupo);

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
  }

  /* ── Lote: um estado só, um passo por chamada ao proxy ── */
  function novoEstado(posicoes, fontes) {
    return {
      fila:         posicoes.slice(),   // posições ainda por gerar, na ordem
      alvo:         posicoes.length,
      tentativas:   {},                 // posição → chamadas que falharam
      chamadas:     0,                  // chamadas que chegaram ao provedor
      teto:         posicoes.length + FOLGA_CHAMADAS,
      esperas:      0,                  // esperas de 60 s após 429
      salvas:       0,                  // gravadas no banco, uma a uma
      naoGravadas:  0,                  // geradas e não gravadas (para o lote)
      gravadas:     [],                 // { pedida, gravada } — posição real no banco
      fontes:       fontes.slice(),     // semeadas pelo servidor, crescem no lote
      falhas:       [],                 // { posicao, motivo } — vão para o relatório
      interrompido: '',                 // linha do relatório da parada antecipada, se houve
      alertas:      [],                 // avisos que o relatório imprime SEMPRE (gravação ambígua)
      emCurso:      false,              // geração ou gravação esperando resposta
      timer:        null                // espera agendada até o próximo passo
    };
  }

  function iniciarLote(basePayload, posicoes, fontes) {
    var st = novoEstado(posicoes, fontes);

    function passo() {
      st.timer = null;
      var pos = proximaPosicao(st);
      if (pos === null) { finalizarLote(st); return; }
      var feitas = st.alvo - st.fila.length;
      setBar(feitas, st.alvo);
      pLabel.textContent = 'Gerando a pergunta da posição ' + pos +
        ' (' + (feitas + 1) + ' de ' + st.alvo + ')…';
      st.emCurso = true;
      chamarPosicao(basePayload, st, pos)
        .then(function (res) {
          return res.tipo === 'ok' ? gravarGerada(st, pos, res.dilema) : aplicarResultado(st, pos, res);
        })
        .then(function (espera) {
          st.emCurso = false;
          st.timer = setTimeout(passo, st.interrompido ? 0 : espera);
        });
    }

    pararBtn.onclick = function () { pararLote(st, passo); };
    pararBtn.disabled = false;
    pararBtn.style.display = '';
    passo();
  }

  /* ── Parar (3b-3) ───────────────────────────────────── */
  // NÃO cancela a chamada em curso: o PHP já a mandou ao provedor e a pergunta já
  // foi paga. Ela termina e, se vier uma pergunta, é gravada. O que se cancela é a
  // espera agendada — inclusive os 60 s do 429 —, para o lote encerrar na hora.
  function pararLote(st, passo) {
    pararBtn.disabled = true;
    if (!st.interrompido) { st.interrompido = 'Lote parado pelo editor.'; }
    if (st.emCurso) {
      pLabel.textContent = 'Parando: aguardando a resposta da chamada em curso…';
      return;
    }
    clearTimeout(st.timer);
    passo();
  }

  // A primeira causa de parada vence: as seguintes não a sobrescrevem.
  function interromper(st, motivo) {
    if (!st.interrompido) { st.interrompido = 'Lote interrompido: ' + motivo; }
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

  // Aplica um resultado SEM pergunta (espera, recusa ou falha) e devolve a
  // espera, em ms, até o próximo passo. A pergunta gerada vai por gravarGerada().
  function aplicarResultado(st, pos, res) {
    if (res.tipo === 'espera') { return registrarEspera(st); }
    if (res.tipo === 'recusa') { interromper(st, res.motivo); return 0; }
    st.chamadas++;
    registrarFalha(st, pos, res.motivo);
    return 600;
  }

  /* ── Cada pergunta é gravada assim que é gerada (3b-2) ── */
  // Em SEQUÊNCIA: o próximo /generate-one só sai depois da resposta do save. O
  // servidor confere a posição pedida e devolve a gravada (cd902e8). Falha
  // EXPLÍCITA de gravação (o servidor respondeu e garante que nada gravou) tem UMA
  // nova tentativa; falha AMBÍGUA (rede, HTTP, resposta sem JSON) não tem: repetir
  // poderia gravar a mesma pergunta duas vezes. Nos dois casos o lote para, e o que
  // já foi gravado fica.
  function gravarGerada(st, pos, dilema) {
    st.chamadas++;
    pLabel.textContent = 'Gravando a pergunta da posição ' + pos + '…';
    return salvarUma(dilema)
      .then(function (g) { return g.ok || g.ambigua ? g : salvarUma(dilema); })
      .then(function (g) {
        if (g.ok) { registrarSucesso(st, pos, dilema, g); return 600; }
        registrarNaoGravada(st, pos, g);
        return 0;
      });
  }

  // Um POST com UM dilema. Resolve sempre: { ok, gravada, contagens } ou
  // { ok:false, ambigua, motivo }. Ambígua = o cliente não sabe se o INSERT ocorreu.
  function salvarUma(dilema) {
    var fd = new FormData();
    fd.append('action',      cfg.actionSave);
    fd.append('nonce',       nonce);
    fd.append('instance_id', instId);
    // O servidor recusa a pergunta que voltar de outro grupo (item 58).
    fd.append('grupo',       ativo ? ativo.dataset.grupo : '');
    fd.append('dilemas',     JSON.stringify([dilema]));

    return fetch(ajaxurl, { method: 'POST', body: fd })
      .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
      })
      .then(lerSalvamento)
      .catch(function (e) { return { ok: false, ambigua: true, motivo: e.message }; });
  }

  // Explícita só quando o servidor respondeu JSON dizendo que não gravou
  // (success:false ou failed > 0); qualquer outra resposta fora do esperado é ambígua.
  function lerSalvamento(r) {
    var d = (r && r.data) || {};
    if (gravouUma(r, d)) {
      var p = (d.posicoes || [])[0] || {};
      return { ok: true, gravada: p.gravada, contagens: d.contagens };
    }
    var explicita = !(r && r.success) || d.failed > 0;
    return { ok: false, ambigua: !explicita, motivo: d.msg || 'o servidor não confirmou a gravação' };
  }

  // Sucesso só com exatamente 1 gravada e nenhuma falha: o POST leva um dilema.
  function gravouUma(r, d) {
    return !!(r && r.success) && d.saved === 1 && !(d.failed > 0);
  }

  function registrarNaoGravada(st, pos, g) {
    st.fila.shift();
    if (g.ambigua) {
      // O aviso vai para st.alertas, e não só para a causa da parada: com o Parar já
      // clicado, a causa é "parado pelo editor" e este aviso sumiria do relatório.
      st.alertas.push('Não sei se a pergunta da posição ' + pos + ' foi gravada (' + g.motivo +
        '): confira a aba Pendentes antes de gerar de novo.');
      interromper(st, 'gravação incerta da pergunta da posição ' + pos + '.');
      return;
    }
    st.naoGravadas++;
    interromper(st, 'gerei a pergunta da posição ' + pos + ' mas não consegui gravá-la (' +
      g.motivo + '). As já gravadas ficam.');
  }

  // 429 é o throttle do proxy e não chega ao provedor, então não conta no teto
  // de chamadas — mas tem teto próprio, para o lote não esperar para sempre.
  function registrarEspera(st) {
    st.esperas++;
    if (st.esperas > MAX_ESPERAS_429) {
      interromper(st, 'limite de uso do provedor (' + MAX_ESPERAS_429 +
        ' esperas de 60 s no mesmo lote)');
      return 0;
    }
    pLabel.textContent = 'Limite de requisições — aguardando 60 s (' +
      st.esperas + ' de ' + MAX_ESPERAS_429 + ')…';
    return 60000;
  }

  function registrarSucesso(st, pos, dilema, g) {
    st.salvas++;
    st.gravadas.push({ pedida: pos, gravada: g.gravada });
    if (g.contagens) { pintarPainel(g.contagens); }
    st.fila.shift();
    pararSeMetaAtingida(st, g.contagens);
    var fonte = (dilema.info_contextual && dilema.info_contextual.link_original) || dilema.source_url;
    if (fonte) { st.fontes.push(fonte); }
    if (dilema.titulo) { pLast.textContent = 'Último: "' + dilema.titulo + '"'; }
  }

  // Alguém inseriu ou devolveu por baixo durante o lote e a meta fechou: gerar o
  // resto passaria da meta. Só vale se ainda havia posições na fila.
  function pararSeMetaAtingida(st, c) {
    if (c && c.deficit <= 0 && st.fila.length) {
      interromper(st, 'meta atingida no meio do lote; as posições restantes não foram geradas.');
    }
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
    pararBtn.style.display = 'none';
    concluir(st, st.salvas, st.naoGravadas);
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
    travarBotoes(false);
  }

  function montarRelatorio(st, saved, failed) {
    var grupo  = ativo ? ' de ' + ativo.dataset.rotulo : '';
    var linhas = ['Salvas ' + saved + ' de ' + st.alvo + ' pergunta(s) que faltavam para a meta' + grupo + '.'];
    if (failed > 0) {
      linhas.push(failed + ' gerada(s) não foram gravadas no banco — veja o log do servidor (error_log).');
    }
    if (st.gravadas.length) { linhas.push('Gravadas nas posições: ' + textoGravadas(st.gravadas) + '.'); }
    if (st.interrompido) { linhas.push(st.interrompido); }
    linhas = linhas.concat(st.alertas);
    st.falhas.forEach(function (f) { linhas.push('Posição ' + f.posicao + ': ' + f.motivo + '.'); });
    return { linhas: linhas, faltam: st.alvo - saved, alerta: saved < st.alvo || failed > 0 };
  }

  // A posição GRAVADA, que é a do banco. Quando o servidor moveu a pergunta
  // (a pedida foi ocupada durante o lote), o relatório mostra as duas.
  function textoGravadas(gravadas) {
    return gravadas.map(function (g) {
      return g.gravada === g.pedida ? String(g.gravada) : g.gravada + ' (pedida ' + g.pedida + ', ocupada no meio do lote)';
    }).join(', ');
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
    if (atualizarBotao && ativo && rel.faltam > 0) {
      ativo.textContent = '▶ Gerar as que faltam (' + rel.faltam + ')';
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

  /* ── Linha de contagem em dia sem F5, depois de ações na Mesa ── */
  // A Mesa já reconta as abas a cada ação e avisa por `ng:contagens`. O déficit
  // agora é por grupo (item 58) e fica na tabela, calculada na carga da tela:
  // aqui só a linha de aprovadas e pendentes da instância se atualiza.
  function pintarPainel(c) {
    var linha = document.getElementById('ng-gen-contagem');
    if (linha) {
      linha.textContent = c.approved + c.pending > 0
        ? 'Aprovadas: ' + c.approved + ', pendentes: ' + c.pending + '.'
        : '';
    }
  }

  document.addEventListener('ng:contagens', function (ev) {
    // Lote em curso, ou recarga agendada: o painel é do lote, não da Mesa.
    if (ativo) { return; }
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
