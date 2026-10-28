/**
 * News-Game — formulário de instância: builder de personagens e botões de ação.
 *
 * Extraído do <script> inline de admin/views/page-instance-form.php pelo A3
 * (FLAG 007 passo 3). Dois motivos: JS inline exige 'unsafe-inline' em
 * script-src, e o ESLint não lê JS dentro de PHP — este arquivo passa a entrar
 * na trava de complexidade do `npm run lint`.
 *
 * Os dados dinâmicos chegam em `ngFormCfg`, por wp_localize_script em
 * Newsgame_Admin_Instances::enqueue_form_scripts(). Nenhum valor de PHP é
 * interpolado aqui.
 *
 * ⚠️ WP_Scripts::localize() aplica `(string)` em todo escalar, então
 * `cfg.numOptions` e `cfg.reviewTotal` chegam como string. Onde o tipo importa,
 * a conversão é explícita — ver a trava de troca de opções lá embaixo.
 */
(function () {
  'use strict';

  var cfg = window.ngFormCfg || {};

  /* ── Elementos ──────────────────────────────────────────── */
  var form       = document.getElementById('ng-form');
  var list       = document.getElementById('ng-chars-list');
  var tpl        = document.getElementById('ng-char-tpl');
  var addBtn     = document.getElementById('ng-add-char');
  var jsonInput  = document.getElementById('ng-chars-json');
  var btnSalvar  = document.getElementById('ng-btn-salvar');
  var btnBack    = document.getElementById('ng-btn-back');
  var afterInput = document.getElementById('ng-after-input');

  // Âncora única da tela: #ng-form só existe na criação/edição de instância. Se
  // ele está no DOM, os outros sete elementos também estão — são irmãos no
  // mesmo bloco de markup. A guarda existe porque o enfileiramento é por hook
  // de tela: sem ela, um dia em que o markup mudar isto vira exceção no console
  // em vez de no-op.
  if (!form) { return; }

  /* ── Builder de personagens ─────────────────────────────── */
  // Hidrata personagens existentes (edição)
  var initial = cfg.characters || [];
  initial.forEach(function (c) { addRow(c.label, c.description, c.apresentacao); });

  addBtn.addEventListener('click', function () { addRow('', '', ''); });

  function addRow(label, desc, apresentacao) {
    var clone = tpl.content.cloneNode(true);
    var row   = clone.querySelector('.ng-char-row');

    row.querySelector('.ng-char-label').value        = label || '';
    row.querySelector('.ng-char-desc').value         = desc  || '';
    row.querySelector('.ng-char-apresentacao').value = apresentacao || '';

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
      var apres = row.querySelector('.ng-char-apresentacao').value.trim();
      if (!label) return;
      data.push({ slug: slugify(label), label: label, description: desc, apresentacao: apres });
    });
    jsonInput.value = JSON.stringify(data);
    atualizarPrevia();
  }

  function slugify(str) {
    var slug = str.toLowerCase()
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .substring(0, 80);
    // 'geral' é o grupo das perguntas gerais: o mesmo desvio do
    // sanitize_characters() no PHP.
    return slug === 'geral' ? 'personagem-geral' : slug;
  }

  /* ── Prévia da receita (item 58) ────────────────────────────
     A MESMA conta de Newsgame_Status::receita(), que é quem grava a meta do
     conjunto no save; aqui é só a prévia. O fator anda em décimos (16 = 1,6)
     para o teto ser de conta inteira, como no PHP. */
  var previa     = document.getElementById('ng-receita-previa');
  var campoN     = document.getElementById('ng-round-size');
  var campoPct   = document.getElementById('ng-gerais-pct');
  var campoFator = document.getElementById('ng-pronto-fator');
  var CONJUNTO_MAX = 120;

  function inteiroNaFaixa(campo, min, max, padrao) {
    var v = parseInt(campo.value, 10);
    return Math.min(max, Math.max(min, isNaN(v) ? padrao : v));
  }

  function fatorDecimos() {
    var v = parseFloat(String(campoFator.value).replace(',', '.'));
    return isNaN(v) ? 16 : Math.min(50, Math.max(16, Math.round(v * 10)));
  }

  function metaDe(qtd, fator) { return Math.floor((qtd * fator + 9) / 10); }

  // Slugs distintos, como o array_unique do PHP: dois personagens com o mesmo
  // nome viram um grupo só.
  function contarPersonagens() {
    var vistos = {};
    JSON.parse(jsonInput.value || '[]').forEach(function (c) { vistos[c.slug] = true; });
    return Object.keys(vistos).length;
  }

  function textoDaReceita(n, pct, fator, nChars) {
    if (!nChars) {
      var m = metaDe(n, fator);
      return { total: m, texto: 'Partida: ' + n + ' perguntas gerais. Meta: ' + m + ' gerais aprovadas.' };
    }
    var g  = Math.max(1, Math.floor(n * pct / 100));
    var y  = n - g;
    var mg = metaDe(g, fator);
    var my = metaDe(y, fator);
    var total = mg + nChars * my;
    return { total: total, texto: 'Partida: ' + g + ' gerais + ' + y + ' do personagem escolhido. Metas: ' +
      mg + ' gerais; ' + my + ' por personagem (' + nChars + ' personagem(ns)). Total a aprovar: ' + total + '.' };
  }

  function atualizarPrevia() {
    if (!previa) { return; }
    var r = textoDaReceita(inteiroNaFaixa(campoN, 4, 15, 10), inteiroNaFaixa(campoPct, 0, 50, 50),
      fatorDecimos(), contarPersonagens());
    var passou = r.total > CONJUNTO_MAX;
    previa.textContent = r.texto + (passou
      ? ' ⚠️ Passa de ' + CONJUNTO_MAX + ': a meta do conjunto fica em ' + CONJUNTO_MAX + '.'
      : '');
    previa.style.color = passou ? '#b32d2e' : '';
  }

  [campoN, campoPct, campoFator].forEach(function (campo) {
    if (campo) { campo.addEventListener('input', atualizarPrevia); }
  });
  atualizarPrevia();

  /* ── Foto da capa e logo do apoio (H3, rodada A) ──────────
     Mesmo seletor nativo da imagem do dilema na Mesa (newsgame-admin-review.js):
     um frame por campo, reaproveitado. O formulário grava só o ID do anexo no
     campo oculto; o jogo resolve a URL na leitura. */
  function pintarCapaImg(campo, id, thumb) {
    var preview = campo.querySelector('.ng-img-preview');
    campo.querySelector('input[type="hidden"]').value = id ? String(id) : '0';
    preview.textContent = '';
    if (id && thumb) {
      var img = document.createElement('img');
      img.src = thumb;
      img.alt = '';
      preview.appendChild(img);
    } else {
      var vazio = document.createElement('span');
      vazio.className = 'ng-img-vazio';
      vazio.textContent = 'sem imagem';
      preview.appendChild(vazio);
    }
    campo.querySelector('.ng-capa-img-remover').hidden = !id;
  }

  function abrirSeletorCapa(campo) {
    if (typeof wp === 'undefined' || !wp.media) {
      window.alert('O seletor de mídia do WordPress não carregou nesta tela. Recarregue a página.');
      return;
    }
    if (!campo.ngFrame) {
      campo.ngFrame = wp.media({
        title:    campo.dataset.titulo,
        button:   { text: 'Usar esta imagem' },
        library:  { type: 'image' },
        multiple: false,
      });
      campo.ngFrame.on('select', function () {
        var att = campo.ngFrame.state().get('selection').first().toJSON();
        pintarCapaImg(campo, att.id, (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) || att.url || '');
      });
    }
    campo.ngFrame.open();
  }

  document.querySelectorAll('.ng-capa-img').forEach(function (campo) {
    campo.querySelector('.ng-capa-img-escolher').addEventListener('click', function () { abrirSeletorCapa(campo); });
    campo.querySelector('.ng-capa-img-remover').addEventListener('click', function () { pintarCapaImg(campo, 0, ''); });
  });

  /* ── Trava de troca de opções com perguntas existentes ────
     A condição era um `<?php if ( $review_total > 0 ) : ?>` em volta deste
     trecho, na view. Virou condicional de JS sobre o mesmo número, agora vindo
     do cfg — parseInt porque o localize entrega string.

     O cancelamento foi reescrito depois do teste manual de agosto, em que clicar
     em "Cancelar" deixava o rádio na opção nova. A lógica antiga era
     `r.checked = r.value === currentOptions` para os dois rádios, o que tem dois
     modos de falha: se `currentOptions` divergisse em tipo ou valor, DESMARCAVA
     os dois em silêncio; e a atribuição acontecia dentro do mesmo dispatch de
     `change` de onde o modal foi aberto, que é onde o navegador pode reaplicar a
     ativação padrão do rádio depois de o diálogo fechar. As três medidas abaixo
     cobrem os dois modos. */
  function ligarTravaDeOpcoes() {
    var radios = document.querySelectorAll('[name="num_options"]');

    /*
     * (1) O valor anterior vem do DOM, não do cfg. O rádio marcado no
     * carregamento é, por construção, um dos que existem na tela, então o `===`
     * do cancelamento tem com o que casar. O cfg fica como reserva, para o caso
     * de nenhum rádio vir marcado.
     */
    var marcado        = document.querySelector('[name="num_options"]:checked');
    var currentOptions = marcado ? marcado.value : String(cfg.numOptions);

    /*
     * (2) Marca explicitamente o rádio do valor pedido, em vez de atribuir a
     * comparação aos dois. Não achar rádio nenhum agora reclama no console em
     * vez de deixar o grupo inteiro desmarcado.
     */
    function aplicarSelecao(valor) {
      var achou = false;
      radios.forEach(function (r) {
        if (r.value === valor) {
          r.checked = true;
          achou = true;
        }
      });
      if (!achou) {
        console.warn('[news-game] nenhum rádio de num_options com o valor', valor);
      }
    }

    radios.forEach(function (radio) {
      radio.addEventListener('change', function () {
        if (radio.value === currentOptions) return;

        var ok = confirm(
          'Atenção: você está trocando de ' + currentOptions + ' para ' + radio.value + ' opções de resposta.\n\n' +
          'Esta instância já possui ' + cfg.reviewTotal + ' pergunta(s) gerada(s) com ' + currentOptions + ' alternativas — elas ficarão incompatíveis com o novo formato.\n\n' +
          'Recomendamos limpar e regenerar todas as perguntas após salvar. Continuar com a troca?'
        );

        if (ok) {
          currentOptions = radio.value;
          return;
        }

        aplicarSelecao(currentOptions);
        /*
         * (3) Segunda passada no tick seguinte. A primeira cobre o caso normal;
         * esta cobre o navegador que reaplica a ativação do rádio depois de um
         * modal aberto de dentro do próprio handler de `change` — reescrevendo
         * o que a primeira acabou de fazer. Marcar `checked` por script não
         * dispara `change`, então a repetição não realimenta o handler.
         */
        setTimeout(function () { aplicarSelecao(currentOptions); }, 0);
      });
    });
  }

  if ((parseInt(cfg.reviewTotal, 10) || 0) > 0) {
    ligarTravaDeOpcoes();
  }

  /* ── Botões de ação ─────────────────────────────────────── */
  /*
   * Um caminho só para os dois botões. Desde outubro o formulário não manda
   * status: ele é DERIVADO no servidor (Newsgame_Status). `destino` vai para o
   * handle_save(): 'stay' volta ao formulário com "Salvo ✓", 'list' volta à
   * listagem.
   *
   * O título é checado nos dois, com foco no campo — sem isso o servidor
   * devolveria `title_required` e o que foi digitado se perderia.
   */
  function enviar(destino) {
    var title = document.getElementById('ng-title');
    if (!title.value.trim()) {
      title.focus();
      title.reportValidity();
      return;
    }
    afterInput.value = destino;
    serialize();
    form.submit();
  }

  btnSalvar.addEventListener('click', function () { enviar('stay'); });
  btnBack.addEventListener('click',   function () { enviar('list'); });
}());
