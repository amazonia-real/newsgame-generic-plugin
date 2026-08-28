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
  var statusInput= document.getElementById('ng-status-input');
  var btnDraft   = document.getElementById('ng-btn-draft');
  var btnReview  = document.getElementById('ng-btn-review');

  // Âncora única da tela: #ng-form só existe na criação/edição de instância. Se
  // ele está no DOM, os outros sete elementos também estão — são irmãos no
  // mesmo bloco de markup. A guarda existe porque o enfileiramento é por hook
  // de tela: sem ela, um dia em que o markup mudar isto vira exceção no console
  // em vez de no-op.
  if (!form) { return; }

  /* ── Builder de personagens ─────────────────────────────── */
  // Hidrata personagens existentes (edição)
  var initial = cfg.characters || [];
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

  /* ── Trava de troca de opções com perguntas existentes ────
     A condição era um `<?php if ( $review_total > 0 ) : ?>` em volta deste
     trecho, na view. Virou condicional de JS sobre o mesmo número, agora vindo
     do cfg — parseInt porque o localize entrega string.

     O cancelamento foi reescrito depois do teste manual de 28/08, em que clicar
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
