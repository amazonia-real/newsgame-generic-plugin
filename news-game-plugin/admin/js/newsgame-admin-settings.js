/**
 * News-Game — tela de configurações: sincronizar acervo e indexar no backend.
 *
 * Extraído do <script> inline de admin/views/page-settings.php pelo A3
 * (FLAG 007 passo 3). Dois motivos: JS inline exige 'unsafe-inline' em
 * script-src, e o ESLint não lê JS dentro de PHP — este arquivo passa a entrar
 * na trava de complexidade do `npm run lint`.
 *
 * Os dados dinâmicos chegam em `ngSettingsCfg`, por wp_localize_script em
 * Newsgame_Settings::enqueue_assets(). Nenhum valor de PHP é interpolado aqui.
 *
 * As duas operações longas (sincronizar o cofre e enviar ao backend) rodam em
 * LOTES CURTOS, um de cada vez, orquestrados por este arquivo: cada chamada AJAX
 * processa um lote no PHP e devolve o progresso. O cursor fica no servidor
 * (option por coleção), então "Continuar de onde parou" sobrevive ao recarregar
 * a página. O tamanho do lote é a constante PHP Newsgame_Ingestor::LOTE_SYNC (leitura); o
 * do envio ao backend fecha em LOTE_PUSH_MAX_ITENS ou LOTE_PUSH_MAX_CARACTERES.
 */
(function() {
  'use strict';

  var cfg = window.ngSettingsCfg || {};

  // Esperas entre as tentativas de um lote que falhou: 3 novas tentativas.
  var ESPERAS_MS = [2000, 5000, 10000];

  // "Tempo esgotado" do envio ao backend: o backend pode ainda estar processando o
  // lote anterior, então a 1ª nova tentativa espera mais (as demais seguem ESPERAS_MS).
  var ESPERA_TEMPO_ESGOTADO_MS = 15000;

  // Falhas em que repetir não adianta: dependem de o editor mudar algo.
  var SEM_RETENTATIVA = { token: 1, config: 1, cofre: 1, cofre_vazio: 1, permissao: 1, ajax_sessao: 1 };

  var DEPOIS = ' Depois de resolver, clique em “Continuar de onde parou”: o que já foi feito não é repetido.';

  var MENSAGENS = {
    tempo_esgotado: 'O backend demorou demais para responder (tempo esgotado). O servidor pode estar lento ou sobrecarregado. Espere alguns minutos.' + DEPOIS,
    inacessivel: 'Não foi possível conectar ao backend. Confira o endereço em “URL do Backend Python” (com https://) e se o servidor do backend está ligado.' + DEPOIS,
    token: 'O backend recusou o Token (erro {http}). Confira o campo “Token do Backend” nesta tela: ele precisa ser igual ao configurado no servidor do backend.' + DEPOIS,
    cota: 'O backend ou o provedor de IA pediu para esperar (limite de uso, erro 429). Espere alguns minutos.' + DEPOIS,
    backend_5xx: 'O backend teve um erro interno (erro {http}). Peça a quem cuida do servidor do backend para olhar os registros (logs) dele.' + DEPOIS,
    backend_http: 'O backend recusou o envio (erro {http}). Confira a “URL do Backend Python”.' + DEPOIS,
    nao_json: 'O endereço respondeu, mas não é o backend do News-Game (a resposta não é a esperada). Confira a “URL do Backend Python”. Início da resposta: {detalhe}' + DEPOIS,
    rest_inacessivel: 'O site não conseguiu ler os próprios textos pela API REST. Plugin de segurança ou firewall podem estar bloqueando.' + DEPOIS,
    rest_http: 'A API REST do site recusou a leitura (erro {http}). Plugin de segurança ou firewall podem estar bloqueando.' + DEPOIS,
    ajax_tempo: 'O servidor do WordPress demorou demais neste lote (tempo esgotado). A hospedagem pode ter um limite de tempo curto. Tente de novo em alguns minutos.' + DEPOIS,
    ajax_5xx: 'O servidor do WordPress teve um erro interno (erro {http}). Veja o registro de erros (error_log) da hospedagem.' + DEPOIS,
    ajax_nao_json: 'O servidor do WordPress respondeu algo inesperado (código HTTP {http}). Início da resposta: {detalhe}' + DEPOIS,
    ajax_rede: 'A conexão com este site foi interrompida. Confira a sua internet.' + DEPOIS,
    ajax_sessao: 'Sua sessão do WordPress expirou. Recarregue a página e entre de novo.',
    desconhecido: 'Ocorreu um erro inesperado.' + DEPOIS
  };

  function criar(tag, atributos, texto) {
    var e = document.createElement(tag);
    Object.keys(atributos || {}).forEach(function(k) { e.setAttribute(k, atributos[k]); });
    if (texto) { e.textContent = texto; }
    return e;
  }

  function numero(n) { return Number(n).toLocaleString('pt-BR'); }

  function mostrar(e, sim) { e.style.display = sim ? '' : 'none'; }

  function atualizarContagem(total) {
    if (total === undefined || total === null) { return; }
    ['newsgame-vault-count', 'newsgame-vault-count-2'].forEach(function(id) {
      var e = document.getElementById(id);
      if (e) { e.textContent = String(total); }
    });
  }

  /* ── Mensagem de erro, em português claro ── */
  function mensagemDe(erro) {
    var base = erro.msg || MENSAGENS[erro.codigo] || MENSAGENS.desconhecido;
    // Funções no replace: o "$" do texto vindo do backend não pode virar padrão ($&, $1…).
    return base
      .replace('{http}', function() { return erro.http || '?'; })
      .replace('{detalhe}', function() { return erro.detalhe ? '“' + erro.detalhe + '”' : '(vazia)'; });
  }

  /* ── Uma chamada AJAX: sempre resolve { ok, dados } ou { ok: false, erro } ── */
  function erroDeResposta(resp) {
    var texto = resp.texto.trim();
    if (resp.status === 403 || texto === '-1' || texto === '0') {
      return { codigo: 'ajax_sessao', http: resp.status };
    }
    if (!texto || [408, 502, 503, 504, 524].indexOf(resp.status) !== -1) {
      return { codigo: 'ajax_tempo', http: resp.status };
    }
    if (resp.status >= 500) { return { codigo: 'ajax_5xx', http: resp.status }; }
    return { codigo: 'ajax_nao_json', http: resp.status, detalhe: texto.slice(0, 200) };
  }

  function interpretar(resp) {
    var j = null;
    try { j = JSON.parse(resp.texto); } catch (e) { j = null; }
    if (j && typeof j === 'object' && 'success' in j) {
      return j.success ? { ok: true, dados: j.data } : { ok: false, erro: j.data || { codigo: 'desconhecido' } };
    }
    return { ok: false, erro: erroDeResposta(resp) };
  }

  function chamar(t, recomecar) {
    var fd = new FormData();
    fd.append('action', t.acao);
    fd.append('nonce', t.nonce);
    fd.append('recomecar', recomecar ? '1' : '0');
    var inicio = Date.now();
    return fetch(ajaxurl, { method: 'POST', body: fd })
      .then(function(r) { return r.text().then(function(txt) { return { status: r.status, texto: txt }; }); })
      .then(interpretar)
      .catch(function() { return { ok: false, erro: { codigo: 'ajax_rede' } }; })
      .then(function(r) { r.duracaoMs = Date.now() - inicio; return r; });
  }

  function esperar(ms) { return new Promise(function(resolve) { setTimeout(resolve, ms); }); }

  /* Falhou: espera 2 s, 5 s e 10 s e tenta de novo, no máximo 3 vezes. */
  function chamarComRetentativa(t, recomecar, tentativa) {
    return chamar(t, recomecar).then(function(r) {
      var repetir = !r.ok && !SEM_RETENTATIVA[r.erro.codigo] && !t.cancelar && tentativa < ESPERAS_MS.length;
      if (!repetir) { return r; }
      var espera = tentativa === 0 && r.erro.codigo === 'tempo_esgotado' ? ESPERA_TEMPO_ESGOTADO_MS : ESPERAS_MS[tentativa];
      t.texto.textContent = 'Falhou. Tentando de novo em ' + (espera / 1000) +
        ' s (nova tentativa ' + (tentativa + 1) + ' de ' + ESPERAS_MS.length + ')…';
      return esperar(espera).then(function() {
        return chamarComRetentativa(t, recomecar, tentativa + 1);
      });
    });
  }

  /* ── Painel: barra de progresso, texto para leitor de tela, botões ── */
  function botao(texto, aoClicar) {
    var b = criar('button', { type: 'button', 'class': 'button' }, texto);
    b.style.display = 'none';
    b.style.marginRight = '8px';
    b.addEventListener('click', aoClicar);
    return b;
  }

  function montarBarra(t) {
    t.barra = criar('div', {
      role: 'progressbar', 'aria-label': 'Progresso: ' + t.rotulo,
      'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': '0'
    });
    t.barra.style.cssText = 'height:14px;border:1px solid #1d2327;background:#fff;display:none;margin:.5rem 0';
    t.preench = criar('div');
    t.preench.style.cssText = 'height:100%;width:0;background:#1d2327';
    t.barra.appendChild(t.preench);
  }

  function montarPainel(t) {
    var raiz = criar('div', { id: 'newsgame-painel-' + t.nome });
    raiz.style.cssText = 'margin-top:.75rem;max-width:560px';
    t.aviso = criar('p', { 'aria-live': 'polite' });
    t.aviso.style.fontWeight = '600';
    montarBarra(t);
    t.texto = criar('p', { role: 'status', 'aria-live': 'polite' });
    t.erro = criar('p', { role: 'alert' });
    t.erro.style.cssText = 'color:#b32d2e;font-weight:600';
    t.btnCancelar = botao('Cancelar', function() { cancelar(t); });
    t.btnContinuar = botao('Continuar de onde parou', function() { iniciar(t, false); });
    t.btnRecomecar = botao('Recomeçar do zero', function() { iniciar(t, true); });
    [t.aviso, t.barra, t.texto, t.erro, t.btnCancelar, t.btnContinuar, t.btnRecomecar].forEach(function(e) {
      raiz.appendChild(e);
    });
    t.status.parentNode.insertBefore(raiz, t.status.nextSibling);
  }

  function aplicarProgresso(t, d) {
    var total = d.total || 0;
    var feitos = d.processados || 0;
    var rotuloTipo = d.tipo ? ' (' + (d.tipo === 'pages' ? 'páginas' : 'posts') + ')' : '';
    var ultimoLote = t.ultimoLoteS ? ' (último lote: ' + t.ultimoLoteS + ' s)' : '';
    var texto = t.verbo + '… ' + numero(feitos) + ' de ' + numero(total) + rotuloTipo + ultimoLote;
    mostrar(t.barra, true);
    t.barra.setAttribute('aria-valuemax', String(total));
    t.barra.setAttribute('aria-valuenow', String(feitos));
    t.barra.setAttribute('aria-valuetext', texto);
    t.preench.style.width = (total ? Math.round(feitos * 100 / total) : 0) + '%';
    t.texto.textContent = texto;
    atualizarContagem(d.vault_total);
  }

  function mostrarInicio(t) {
    mostrar(t.principal, true);
    t.principal.disabled = false;
    [t.aviso, t.btnCancelar, t.btnContinuar, t.btnRecomecar].forEach(function(e) { mostrar(e, false); });
  }

  function mostrarExecucao(t) {
    t.principal.disabled = true;
    mostrar(t.principal, false);
    mostrar(t.btnCancelar, true);
    t.btnCancelar.disabled = false;
    [t.aviso, t.btnContinuar, t.btnRecomecar].forEach(function(e) { mostrar(e, false); });
    t.erro.textContent = '';
  }

  /* Parada com cursor salvo no servidor: oferece continuar ou recomeçar. */
  function mostrarRetomada(t, estado) {
    mostrar(t.principal, false);
    mostrar(t.btnCancelar, false);
    mostrar(t.btnContinuar, true);
    mostrar(t.btnRecomecar, true);
    t.aviso.textContent = 'Há uma ' + t.incompleta + ' incompleta (' + (estado.total
      ? 'parou em ' + numero(estado.processados) + ' de ' + numero(estado.total)
      : 'parou antes do primeiro lote') + ').';
    mostrar(t.aviso, true);
    aplicarProgresso(t, estado);
    t.texto.textContent = '';
  }

  /* ── O laço: um lote por vez, nunca em paralelo ── */
  function cancelar(t) {
    t.cancelar = true;
    t.btnCancelar.disabled = true;
    t.texto.textContent = 'Cancelando… vai parar quando o lote em andamento terminar.';
  }

  function parar(t, motivo) {
    if (t.ultimo) { mostrarRetomada(t, t.ultimo); } else { mostrarInicio(t); }
    t.texto.textContent = motivo;
  }

  function falhou(t, erro) {
    var msg = mensagemDe(erro);
    // Sem nenhum lote concluído ainda, "Continuar" começa do início: é o mesmo
    // que recomeçar, e o editor precisa do botão que a mensagem cita.
    if (!t.ultimo) { t.ultimo = { processados: 0, total: 0 }; }
    parar(t, '');
    t.erro.textContent = msg;
  }

  function concluir(t, d) {
    t.ultimo = null;
    aplicarProgresso(t, d);
    mostrarInicio(t);
    t.texto.textContent = t.fim(d);
  }

  function passo(t, recomecar) {
    if (t.cancelar) {
      parar(t, 'Cancelado. Clique em “Continuar de onde parou” para retomar.');
      return;
    }
    chamarComRetentativa(t, recomecar, 0).then(function(r) {
      if (!r.ok) {
        if (t.cancelar) { parar(t, 'Cancelado.'); } else { falhou(t, r.erro); }
        return;
      }
      atualizarContagem(r.dados.vault_total);
      t.ultimoLoteS = Math.max(1, Math.round(r.duracaoMs / 1000));
      if (r.dados.feito) { concluir(t, r.dados); return; }
      t.ultimo = { processados: r.dados.processados, total: r.dados.total, tipo: r.dados.tipo };
      aplicarProgresso(t, r.dados);
      passo(t, false);
    });
  }

  function iniciar(t, recomecar) {
    t.cancelar = false;
    t.ultimoLoteS = null;
    if (recomecar) { t.ultimo = null; }
    mostrarExecucao(t);
    t.texto.textContent = t.verbo + '…';
    passo(t, recomecar);
  }

  function criarTarefa(def) {
    var t = def;
    montarPainel(t);
    t.principal.addEventListener('click', function() { iniciar(t, true); });
    if (t.cursor) {
      t.ultimo = t.cursor;
      mostrarRetomada(t, t.cursor);
    } else {
      mostrarInicio(t);
    }
    return t;
  }

  // Artigos acima do limite por item são indexados só até ele; a conclusão avisa quantos.
  function textoCortados(n) {
    if (!n) { return ''; }
    var mil = numero((cfg.limiteItem || 30000) / 1000);
    return n === 1
      ? ' 1 texto muito longo foi indexado só até os primeiros ' + mil + ' mil caracteres.'
      : ' ' + numero(n) + ' textos muito longos foram indexados só até os primeiros ' + mil + ' mil caracteres.';
  }

  /* ── "Testar conexão": consulta nova ao /health do backend ── */
  function testarConexao(btn) {
    var linha = document.getElementById('newsgame-backend-status');
    btn.disabled = true;
    linha.textContent = 'Testando…';
    chamar({ acao: cfg.actionTest, nonce: cfg.nonceTest }, false).then(function(r) {
      btn.disabled = false;
      // Falha do próprio WordPress (não do backend): texto curto, sem o roteiro
      // de "Continuar" dos lotes.
      linha.textContent = r.ok ? r.dados.texto
        : 'Não foi possível fazer o teste agora: o WordPress não respondeu como esperado. Recarregue a página e tente de novo.';
    });
  }

  var testBtn = document.getElementById('newsgame-test-btn');
  if (testBtn) {
    testBtn.addEventListener('click', function() { testarConexao(testBtn); });
  }

  /* ── As duas tarefas ── */
  var syncBtn = document.getElementById('newsgame-sync-btn');

  // Âncora única da tela: #newsgame-sync-btn só existe aqui. A guarda existe
  // porque o enfileiramento é por hook de tela, e um dia em que o markup mudar
  // isto vira no-op em vez de exceção no console.
  if (!syncBtn) { return; }

  criarTarefa({
    nome: 'sync',
    rotulo: 'sincronização do acervo',
    verbo: 'Sincronizando',
    incompleta: 'sincronização',
    principal: syncBtn,
    status: document.getElementById('newsgame-sync-status'),
    acao: cfg.actionSync,
    nonce: cfg.nonceSync,
    cursor: cfg.cursorSync,
    fim: function(d) { return 'Sincronização concluída: ' + numero(d.salvos) + ' itens salvos.'; }
  });

  var pushBtn = document.getElementById('newsgame-push-btn');
  if (pushBtn) {
    criarTarefa({
      nome: 'push',
      rotulo: 'indexação no backend',
      verbo: 'Enviando',
      incompleta: 'indexação',
      principal: pushBtn,
      status: document.getElementById('newsgame-push-status'),
      acao: cfg.actionPush,
      nonce: cfg.noncePush,
      cursor: cfg.cursorPush,
      fim: function(d) {
        return 'Indexação concluída: ' + numero(d.enviados) + ' enviados, ' + numero(d.pulados) +
          ' pulados (textos com menos de 100 caracteres são ignorados).' + textoCortados(d.cortados);
      }
    });
  }
}());
