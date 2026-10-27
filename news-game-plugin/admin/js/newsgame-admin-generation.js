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

  // A linha de progresso é lida por leitor de tela a cada mudança de texto.
  pLabel.setAttribute('aria-live', 'polite');

  // Tetos do lote — decididos em 02/10. O plano gratuito do Gemini tem 20
  // requisições por DIA, então cada chamada desperdiçada é cara.
  var MAX_TENTATIVAS  = 3;  // por posição: a 1ª chamada + 2 retentativas
  // Teto global de chamadas do lote = posições × MAX_TENTATIVAS: o máximo que a
  // regra por posição já permite (antes era déficit + 3, o que cortava o lote
  // quando várias posições precisavam de nova tentativa).
  var MAX_ESPERAS_429 = 3;  // esperas de 60 s por lote; na 4ª, o lote para
  var CHAVE_RELATORIO = 'ngGenRelatorio:' + instId;

  // Espera antes da 1ª e da 2ª nova tentativa da MESMA posição (MAX_TENTATIVAS - 1).
  var ESPERAS_RETENTATIVA_MS = [2000, 5000];

  /* ── TABELA ÚNICA de textos do backend ──────────────────────────────
     O backend responde HTTP 502 quando o provedor de IA recusa a chamada, e só
     distingue a causa pelo texto do `detail` (generator.detail_de_provedor).
     Estes padrões reconhecem esses textos; AJUSTAR AQUI se o backend mudar as
     frases. Texto de 502 que não bate com nenhum também PARA a geração e mostra o
     `detail` (falha segura). Ordem importa só onde um texto contém o outro. */
  var PADROES_502 = [
    { causa: 'chave',   padrao: /recusou a chave de API/i,
      texto: 'A chave do provedor foi recusada. Confira a chave em Configurações.' },
    { causa: 'credito', padrao: /recusou a chamada por falta de crédito/i,
      texto: 'A conta do provedor de IA está sem crédito. Recarregue o crédito ou troque o provedor em Configurações.' },
    { causa: 'cota',    padrao: /recusou a chamada por limite de uso/i,
      texto: 'A cota gratuita do provedor de IA acabou. Espere (a cota diária costuma renovar de madrugada) ou troque o provedor em Configurações.' },
    { causa: 'modelo',  padrao: /não encontrou o recurso pedido/i,
      texto: 'O provedor não encontrou o modelo configurado no servidor do backend. Confira o modelo escolhido para o provedor.' }
  ];

  // Disjuntor: 2 posições SEGUIDAS que esgotam as tentativas por grupo errado param o
  // lote, para não gastar a cota do provedor com um backend desatualizado.
  var LIMITE_GRUPOS_SEGUIDOS = 2;
  var TEXTO_DISJUNTOR = 'Duas perguntas seguidas voltaram de outro grupo. Isso quase sempre indica que o backend está desatualizado: ' +
    "em Configurações, clique em 'Testar conexão' e atualize o backend se for o caso. " +
    'O lote foi interrompido para não gastar a cota do provedor.';

  var TEXTO_CHAVE_AUSENTE = 'A chave do provedor não está configurada. Confira a chave em Configurações.';
  var TEXTO_TOKEN         = 'O backend recusou o Token. Confira o campo "Token do Backend" em Configurações.';

  // Motivos de uma pergunta que não saiu, para o relatório agrupado.
  var MOTIVOS = {
    grupo:        { curto: 'veio de outro grupo', um: 'voltou de outro grupo', varios: 'voltaram de outro grupo' },
    incompleta:   { curto: 'resposta incompleta', um: 'teve resposta incompleta', varios: 'tiveram resposta incompleta' },
    sem_resposta: { curto: 'o backend não respondeu', um: 'ficou sem resposta do backend', varios: 'ficaram sem resposta do backend' },
    erro_backend: { curto: 'erro do backend', um: 'teve erro do backend', varios: 'tiveram erro do backend' },
    teto:         { curto: 'limite de chamadas', um: 'ficou de fora (limite de chamadas do lote)', varios: 'ficaram de fora (limite de chamadas do lote)' },
    interrompida: { curto: 'geração interrompida', um: 'ficou de fora (a geração foi interrompida)', varios: 'ficaram de fora (a geração foi interrompida)' }
  };

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
      teto:         posicoes.length * MAX_TENTATIVAS,
      esperas:      0,                  // esperas de 60 s após 429
      salvas:       0,                  // gravadas no banco, uma a uma
      naoGravadas:  0,                  // geradas e não gravadas (para o lote)
      gravadas:     [],                 // { pedida, gravada } — posição real no banco
      fontes:       fontes.slice(),     // semeadas pelo servidor, crescem no lote
      falhas:       [],                 // { posicao, motivo } — vão para o relatório
      interrompido: '',                 // linha do relatório da parada antecipada, se houve
      paradaGrave:  false,              // parada por causa que exige o editor (chave, cota…)
      gruposSeguidos: 0,                // posições seguidas esgotadas por grupo errado (disjuntor)
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
      var nova = st.tentativas[pos] ? ' — nova tentativa ' + (st.tentativas[pos] + 1) + ' de ' + MAX_TENTATIVAS : '';
      pLabel.textContent = 'Gerando a pergunta da posição ' + pos +
        ' (' + (feitas + 1) + ' de ' + st.alvo + ')' + nova + '…';
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
  function interromper(st, motivo, grave) {
    if (st.interrompido) { return; }
    st.interrompido = (grave ? 'Geração interrompida: ' : 'Lote interrompido: ') + motivo;
    st.paradaGrave  = !!grave;
  }

  // Próxima posição da fila, ou null quando o lote acabou. Teto de chamadas
  // atingido move o resto da fila para as falhas: o relatório diz o que faltou.
  function proximaPosicao(st) {
    if (st.interrompido || !st.fila.length) { return null; }
    if (st.chamadas < st.teto) { return st.fila[0]; }
    st.fila.forEach(function (p) {
      st.falhas.push({ posicao: p, causa: 'teto' });
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
        // SyntaxError = resposta 200 que não é JSON; o resto é a rede do navegador.
        return { tipo: 'retentavel', causa: e instanceof SyntaxError ? 'incompleta' : 'sem_resposta' };
      });
  }

  // Traduz a resposta HTTP do proxy num tipo que aplicarResultado() entende.
  // 429 = throttle do proxy (espera 60 s). Erro com `codigo` é o que o PRÓPRIO
  // proxy decidiu (backend_inacessivel, tempo_esgotado, sem_url…); o 502 SEM
  // `codigo` é recusa do provedor, reconhecida pela tabela PADROES_502.
  function classificarResposta(response) {
    if (response.status === 429) { return { tipo: 'espera' }; }
    if (response.ok) { return response.json().then(function (d) { return { tipo: 'ok', dilema: d }; }); }
    return response.json()
      .catch(function () { return {}; })
      .then(function (b) { return classificarFalha(response.status, b || {}); });
  }

  function parada(texto, causa) { return { tipo: 'recusa', causa: causa, texto: texto }; }

  // O 502 do backend PARA a geração, reconhecido ou não: repetir não ajudaria, e
  // texto desconhecido mostra o `detail` (falha segura).
  function paradaDoBackend(detail) {
    for (var i = 0; i < PADROES_502.length; i++) {
      if (PADROES_502[i].padrao.test(detail)) {
        return parada(PADROES_502[i].texto + (detail ? ' Mensagem do backend: ' + detail : ''), PADROES_502[i].causa);
      }
    }
    return parada('O backend recusou a geração e não sei dizer o motivo exato. Mensagem dele: ' +
      (detail || '(sem texto)'), 'desconhecida');
  }

  function classificarFalha(status, b) {
    var detail = String(b.detail || '');
    if (b.codigo === 'backend_inacessivel' || b.codigo === 'tempo_esgotado') {
      return { tipo: 'retentavel', causa: 'sem_resposta' };
    }
    if (b.codigo) { return parada(detail || 'Falha de configuração do plugin.', 'config'); }
    if (status === 401) { return parada(detail === 'Invalid API key' ? TEXTO_TOKEN : TEXTO_CHAVE_AUSENTE, 'chave'); }
    if (status === 502) { return paradaDoBackend(detail); }
    return { tipo: 'retentavel', causa: status === 422 ? 'incompleta' : 'erro_backend' };
  }

  // Aplica um resultado SEM pergunta (espera, recusa ou falha) e devolve a
  // espera, em ms, até o próximo passo. A pergunta gerada vai por gravarGerada().
  function aplicarResultado(st, pos, res) {
    if (res.tipo === 'espera') { return registrarEspera(st); }
    if (res.tipo === 'recusa') { interromper(st, res.texto, true); return 0; }
    st.chamadas++;
    return registrarFalha(st, pos, res.causa);
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
      .then(function (g) { return g.ok || g.ambigua || g.grupoErrado ? g : salvarUma(dilema); })
      .then(function (g) {
        if (g.ok) { registrarSucesso(st, pos, dilema, g); return 600; }
        // Grupo errado: o servidor NÃO gravou. Em vez de repetir o mesmo save,
        // gera de novo a MESMA posição, pedindo o mesmo grupo (até MAX_TENTATIVAS).
        if (g.grupoErrado) { return registrarFalha(st, pos, 'grupo'); }
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
    if (grupoRecusado(r, d)) { return { ok: false, grupoErrado: true, motivo: d.msg }; }
    var explicita = !(r && r.success) || d.failed > 0;
    return { ok: false, ambigua: !explicita, motivo: d.msg || 'o servidor não confirmou a gravação' };
  }

  // O servidor recusou a pergunta por vir de outro grupo (nada foi gravado).
  function grupoRecusado(r, d) {
    return !!r && !r.success && d.grupo_recusado > 0;
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
    st.gruposSeguidos = 0;
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

  // Falha retentável: a posição fica na frente da fila até MAX_TENTATIVAS, com
  // espera de 2 s e depois 5 s. Esgotadas, ela é PULADA e o lote segue. Devolve a
  // espera, em ms, até o próximo passo.
  function registrarFalha(st, pos, causa) {
    var n = st.tentativas[pos] = (st.tentativas[pos] || 0) + 1;
    if (n < MAX_TENTATIVAS) {
      pLast.textContent = 'Posição ' + pos + ': ' + MOTIVOS[causa].curto + ' — nova tentativa em ' +
        (ESPERAS_RETENTATIVA_MS[n - 1] / 1000) + ' s (' + (n + 1) + ' de ' + MAX_TENTATIVAS + ').';
      return ESPERAS_RETENTATIVA_MS[n - 1];
    }
    st.fila.shift();
    st.falhas.push({ posicao: pos, causa: causa });
    verificarDisjuntor(st, causa);
    return 600;
  }

  // Posição esgotada por grupo errado soma na contagem de seguidas; qualquer outro
  // desfecho (acerto, ou falha de outro motivo, que só pula) zera. Na 2ª seguida o
  // lote PARA, com role="alert" no relatório; o que já foi salvo continua salvo.
  function verificarDisjuntor(st, causa) {
    st.gruposSeguidos = causa === 'grupo' ? st.gruposSeguidos + 1 : 0;
    if (st.gruposSeguidos >= LIMITE_GRUPOS_SEGUIDOS) { interromper(st, TEXTO_DISJUNTOR, true); }
  }

  function finalizarLote(st) {
    // Fila com sobra só existe quando o lote foi interrompido.
    st.fila.forEach(function (p) { st.falhas.push({ posicao: p, causa: 'interrompida' }); });
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
    linhas = linhas.concat(st.alertas, resumoFalhas(st.falhas));
    return {
      linhas: linhas, faltam: st.alvo - saved, alerta: saved < st.alvo || failed > 0,
      papel:  st.paradaGrave ? 'alert' : 'status'
    };
  }

  // "2 voltaram de outro grupo; 1 teve resposta incompleta", agrupado por motivo.
  function resumoFalhas(falhas) {
    if (!falhas.length) { return []; }
    var contagem = {};
    falhas.forEach(function (f) { contagem[f.causa] = (contagem[f.causa] || 0) + 1; });
    var frases = Object.keys(MOTIVOS).filter(function (k) { return contagem[k]; }).map(function (k) {
      return contagem[k] + ' ' + (contagem[k] === 1 ? MOTIVOS[k].um : MOTIVOS[k].varios);
    });
    var linhas = ['Não foram geradas: ' + frases.join('; ') + '. Para tentar de novo as que faltam, clique em "Gerar os que faltam".'];
    if ((contagem.grupo || 0) * 2 > falhas.length) {
      linhas.push('Isso costuma indicar um backend desatualizado. Em Configurações, clique em "Testar conexão".');
    }
    return linhas;
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
    // Parada que exige o editor (chave, cota) é role="alert"; o resto, "status".
    box.setAttribute('role', rel.papel === 'alert' ? 'alert' : 'status');
    box.textContent = '';
    rel.linhas.forEach(function (t) {
      var p = document.createElement('p');
      p.textContent = t; // textContent: o `detail` do backend nunca vira HTML
      box.appendChild(p);
    });
    box.style.display = 'block';
    if (atualizarBotao && ativo && rel.faltam > 0) {
      ativo.textContent = '▶ Gerar os que faltam (' + rel.faltam + ')';
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
