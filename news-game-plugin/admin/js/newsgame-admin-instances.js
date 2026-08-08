/**
 * News-Game — listagem de instâncias (admin).
 * A confirmação de exclusão vive num arquivo externo porque atributo de evento
 * inline (onclick) exige 'unsafe-inline' na CSP do site. Ver TECH_FLAGS FLAG 007.
 */
(function () {
  'use strict';

  function boot() {
    document.querySelectorAll('a.ng-confirm').forEach(function (link) {
      link.addEventListener('click', function (ev) {
        var msg = link.dataset.ngConfirm;
        if (msg && !window.confirm(msg)) {
          ev.preventDefault();
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
