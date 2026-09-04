/**
 * News-Game — seletor visual de skins (admin), painel de conferência.
 *
 * ESTE ARQUIVO FAZ UMA COISA SÓ: manter o painel de conferência sincronizado
 * com o radio marcado. Ele NÃO grava, NÃO chama AJAX e NÃO decide aparência de
 * cartão.
 *
 *  · a seleção em si é do radio NATIVO — clique, setas, Tab e o envio no POST
 *    são do navegador, não deste arquivo. Se este script não carregar, o
 *    seletor continua funcionando por inteiro; o que se perde é só o texto do
 *    painel acompanhar o clique antes de salvar;
 *  · a moldura verde do cartão selecionado é do CSS (`:has(input:checked)`),
 *    dono único. Ver o comentário em `admin/css/newsgame-admin-skins.css`.
 *
 * SEM wp_localize_script, de propósito: rótulo e resumo de cada skin viajam em
 * `data-ng-rotulo` / `data-ng-resumo` no próprio input. Os dois são texto de UI
 * e moram no mapa da view (`$rotulos_skin` / `$resumos_skin`), que é onde a
 * convenção do projeto os coloca — "slug é dado, rótulo é UI". Passar por
 * localize exigiria uma terceira cópia dos textos dentro da classe PHP, porque
 * `admin_enqueue_scripts` roda ANTES de a view existir.
 *
 * Ver TECH_FLAGS FLAG 007 (nenhum JS de lógica inline no admin).
 */
(function () {
  'use strict';

  function boot() {
    var picker = document.getElementById('ng-skin-picker');
    var alvoNome = document.getElementById('ng-skin-confere-label');
    var alvoResumo = document.getElementById('ng-skin-confere-resumo');

    // Guarda de âncora: os cinco arquivos de JS desta tela são enfileirados sem
    // reproduzir as condições da view (A3), então cada um sai fora sozinho quando
    // o seu bloco não está no DOM.
    if (!picker || !alvoNome || !alvoResumo) {
      return;
    }

    function pintar() {
      var marcado = picker.querySelector('input[name="skin"]:checked');

      // Nenhum marcado só acontece com markup alterado à mão: o PHP normaliza a
      // skin na leitura e sempre imprime um `checked`. Sair sem tocar no painel
      // preserva o que o servidor escreveu, em vez de esvaziá-lo.
      if (!marcado) {
        return;
      }

      alvoNome.textContent = marcado.dataset.ngRotulo || marcado.value;
      alvoResumo.textContent = marcado.dataset.ngResumo || '';
    }

    picker.addEventListener('change', function (ev) {
      if (ev.target && ev.target.name === 'skin') {
        pintar();
      }
    });

    /*
     * Uma passada na carga, reconciliando com o servidor.
     *
     * Não é redundante com o que o PHP já imprimiu: o Firefox (e o Chrome no
     * botão de voltar) RESTAURA a seleção de formulário num reload, e a restauração
     * não dispara `change`. Sem esta linha, recarregar a tela depois de clicar em
     * outra skin sem salvar deixaria o cartão marcado numa skin e o painel
     * descrevendo outra.
     */
    pintar();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
