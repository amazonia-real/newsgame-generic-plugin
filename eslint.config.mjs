/**
 * News Game Plugin — configuração de lint (FLAG 002 / A4).
 *
 * Escopo deliberadamente mínimo: **uma única regra**, `complexity`, com limite
 * 10. Sem plugins, sem Prettier, sem regras de estilo. O objetivo é travar o
 * crescimento de complexidade ciclomática durante a fase de UX, não padronizar
 * formatação. Acrescentar regra aqui exige decisão registrada no roadmap.
 *
 * Cobertura: só o JS do plugin em arquivos `.js`. Os ~959 linhas de JS que
 * vivem inline nos `<script>` de `admin/views/*.php` **não são alcançáveis**
 * por esta config — ESLint não lê JS dentro de PHP. Elas entram na trava
 * quando o A3 mover os blocos para arquivos `.js`.
 *
 * Uso: `npm run lint`
 */
export default [
  {
    // O .venv do backend traz JS de dependências Python (torch, sklearn, urllib3).
    ignores: ['backend-python/.venv/**', 'node_modules/**'],
  },
  {
    files: ['news-game-plugin/**/*.js'],
    languageOptions: {
      // IIFE clássico no browser, não módulo ES — os dois arquivos abrem com
      // `(function () { 'use strict';`.
      ecmaVersion: 2020,
      sourceType: 'script',
    },
    rules: {
      complexity: ['error', 10],
    },
  },
];
