(function () {
  'use strict';

  /* ── Escape de HTML para evitar XSS nos dados do DB ── */
  function esc(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function escNl(str) {
    return esc(str).replace(/\n/g, '<br>');
  }

  /* ── Gráfico triangular de perfil (adaptado do JDA) ──── */
  function buildRadarSVG(vetor, eixos) {
    var W = 480, H = 230, cx = 240, cy = 132, r = 83;

    var norms  = [vetor.eixo1 || 0, vetor.eixo2 || 0, vetor.eixo3 || 0];
    var pcts   = norms.map(function (n) { return Math.min(100, Math.max(-100, n * 100)); });
    var labels = [
      (eixos.eixo1 && eixos.eixo1.nome) || 'Eixo 1',
      (eixos.eixo2 && eixos.eixo2.nome) || 'Eixo 2',
      (eixos.eixo3 && eixos.eixo3.nome) || 'Eixo 3',
    ];
    // Topo → eixo1, direita-baixo → eixo2, esquerda-baixo → eixo3
    var angRad = [-90, 30, 150].map(function (a) { return a * Math.PI / 180; });

    // Pontos do polígono (valores negativos cruzam o centro — efeito fratura)
    var pts = norms.map(function (n, i) {
      return [
        +(cx + n * r * Math.cos(angRad[i])).toFixed(2),
        +(cy + n * r * Math.sin(angRad[i])).toFixed(2),
      ];
    });

    // Grid: triângulos concêntricos em 25/50/75/100% do raio (paleta escura)
    var gridSVG = [0.25, 0.5, 0.75, 1.0].map(function (lv) {
      var gPts = angRad.map(function (a) {
        return (cx + lv * r * Math.cos(a)).toFixed(2) + ',' +
               (cy + lv * r * Math.sin(a)).toFixed(2);
      }).join(' ');
      return '<polygon points="' + gPts + '" fill="none"' +
             ' stroke="rgba(255,255,255,' + (lv === 1.0 ? '0.18' : '0.07') + ')"' +
             ' stroke-width="' + (lv === 1.0 ? 1.3 : 0.7) + '"/>';
    }).join('');

    // Eixos do centro aos vértices
    var axesSVG = angRad.map(function (a) {
      return '<line x1="' + cx + '" y1="' + cy + '"' +
             ' x2="' + (cx + r * Math.cos(a)).toFixed(2) + '"' +
             ' y2="' + (cy + r * Math.sin(a)).toFixed(2) + '"' +
             ' stroke="rgba(255,255,255,0.18)" stroke-width="1"/>';
    }).join('');

    // Polígono de dados colorido pela média geral
    var avgNorm   = (norms[0] + norms[1] + norms[2]) / 3;
    var fillClr   = avgNorm >= 0 ? 'rgba(46,204,113,0.25)' : 'rgba(231,76,60,0.20)';
    var strokeClr = avgNorm >= 0 ? '#27ae60' : '#E74C3C';
    var polyPts   = pts.map(function (p) { return p.join(','); }).join(' ');

    // Círculos nos vértices, coloridos individualmente
    var dotClrs = norms.map(function (n) {
      return n > 0.05 ? '#27ae60' : n < -0.05 ? '#E74C3C' : '#95A5A6';
    });
    var dotsSVG = pts.map(function (pt, i) {
      return '<circle cx="' + pt[0] + '" cy="' + pt[1] + '" r="6"' +
             ' fill="' + dotClrs[i] + '" stroke="rgba(255,255,255,0.5)" stroke-width="2"/>';
    }).join('');

    // Rótulos: nome do eixo + percentual com sinal
    var labelOff  = 24;
    var labelsSVG = angRad.map(function (a, i) {
      var lx     = +(cx + (r + labelOff) * Math.cos(a)).toFixed(1);
      var ly     = +(cy + (r + labelOff) * Math.sin(a)).toFixed(1);
      var pctTxt = (pcts[i] >= 0 ? '+' : '') + Math.round(pcts[i]) + '%';
      var anchor = Math.cos(a) > 0.15 ? 'start' : Math.cos(a) < -0.15 ? 'end' : 'middle';
      return '<text x="' + lx + '" y="' + (ly - 4) + '"' +
               ' text-anchor="' + anchor + '" font-size="11" font-weight="700"' +
               ' letter-spacing="0.5" fill="rgba(255,255,255,0.65)"' +
               ' font-family="system-ui,sans-serif">' + labels[i].toUpperCase() + '</text>' +
             '<text x="' + lx + '" y="' + (ly + 14) + '"' +
               ' text-anchor="' + anchor + '" font-size="17" font-weight="800"' +
               ' fill="' + dotClrs[i] + '" font-family="system-ui,sans-serif">' + pctTxt + '</text>';
    }).join('');

    return '<svg viewBox="0 0 ' + W + ' ' + H + '" width="100%"' +
           ' style="display:block;margin:0 auto;"' +
           ' role="img" aria-label="Gráfico de perfil: ' + labels.join(', ') + '">' +
      '<g>' + gridSVG + '</g>' +
      '<g>' + axesSVG + '</g>' +
      '<polygon points="' + polyPts + '" fill="' + fillClr + '"' +
               ' stroke="' + strokeClr + '" stroke-width="2.5" stroke-linejoin="round"/>' +
      '<g>' + dotsSVG + '</g>' +
      '<circle cx="' + cx + '" cy="' + cy + '" r="3.5" fill="rgba(255,255,255,0.35)"/>' +
      '<g>' + labelsSVG + '</g>' +
    '</svg>';
  }

  /* ── Monta uma seção do veredito ─────────────────────── */
  function vSection(label, text) {
    if (!text) return '';
    return (
      '<div>' +
        '<span class="ng-verd-label">' + esc(label) + '</span>' +
        '<p class="ng-verd-text">' + escNl(text) + '</p>' +
      '</div>'
    );
  }

  /* ── Instância do jogo ───────────────────────────────── */
  function NgGame(wrap) {
    var gid     = wrap.id.replace('ng-game-', '');
    var data    = window['_ngGame_' + gid];
    var inner   = wrap.querySelector('.ng-game-inner');

    if (!data || !data.dilemas || !data.dilemas.length) {
      inner.innerHTML = '<p style="padding:2rem;color:#f88">Nenhum dilema disponível para esta instância.</p>';
      return;
    }

    var dilemas  = data.dilemas;
    var title    = data.title || 'News-Game';
    var idx      = -1;   // -1 = tela inicial

    var numOptions = data.num_options || 2;
    var optKeys    = ['a', 'b', 'c'].slice(0, numOptions);

    /* acumulador do vetor de eixos — resetado a cada jogo */
    var vetor    = {};   // { eixo1: N, eixo2: N, eixo3: N }
    var pesoCount = 0;   // quantos dilemas tinham axis_weights

    /* ── Tela inicial ── */
    function renderStart() {
      inner.innerHTML =
        '<div class="ng-screen">' +
          '<h2>' + esc(title) + '</h2>' +
          '<p>' + dilemas.length + ' perguntas para você responder.</p>' +
          '<button class="ng-btn-cta">Iniciar jogo ›</button>' +
        '</div>';
      inner.querySelector('.ng-btn-cta')
        .addEventListener('click', function () { idx = 0; renderDilema(); });
    }

    /* ── Tela de dilema ── */
    function renderDilema() {
      var d   = dilemas[idx];
      var pct = Math.round(idx / dilemas.length * 100);

      var optsHtml = optKeys.map(function (key) {
        var opt = d['option_' + key] || {};
        return '<button class="ng-btn-option" data-pick="' + key + '">' +
          '<span class="ng-opt-label">Opção ' + key.toUpperCase() + '</span>' +
          esc(opt.texto || '') +
        '</button>';
      }).join('');

      inner.innerHTML =
        '<div class="ng-progress">' +
          '<div class="ng-progress-track">' +
            '<div class="ng-progress-fill" style="width:' + pct + '%"></div>' +
          '</div>' +
          '<span class="ng-progress-label">' + (idx + 1) + ' / ' + dilemas.length + '</span>' +
        '</div>' +
        '<div class="ng-card">' +
          '<h2 class="ng-dilema-title">' + esc(d.title) + '</h2>' +
          '<p class="ng-dilema-body">' + escNl(d.body) + '</p>' +
        '</div>' +
        '<div class="ng-options">' + optsHtml + '</div>';

      inner.querySelectorAll('.ng-btn-option').forEach(function (btn) {
        btn.addEventListener('click', function () { renderVeredicto(btn.dataset.pick); });
      });
    }

    /* ── Tela de veredito ── */
    function renderVeredicto(pick) {
      var d       = dilemas[idx];
      var pct     = Math.round((idx + 1) / dilemas.length * 100);
      var nextLbl = idx + 1 < dilemas.length ? 'Próxima pergunta →' : 'Ver resultado final';
      var verd    = d.veredicto || {};

      /* Acumula pesos de eixo da alternativa escolhida */
      var pesos = d.axis_weights && d.axis_weights[pick.toUpperCase()];
      if (pesos) {
        Object.keys(pesos).forEach(function (eixo) {
          vetor[eixo] = (vetor[eixo] || 0) + pesos[eixo];
        });
        pesoCount++;
      }

      // Renderiza todas as opções: escolhida com consequencia, demais sem
      var optsHtml = optKeys.map(function (key) {
        var opt      = d['option_' + key] || {};
        var isChosen = key === pick;
        var label    = isChosen
          ? 'Opção ' + key.toUpperCase() + ' — sua escolha'
          : 'Opção ' + key.toUpperCase();
        var cls      = isChosen ? 'ng-btn-option is-chosen' : 'ng-btn-option is-other';
        return '<button class="' + cls + '" disabled>' +
          '<span class="ng-opt-label">' + label + '</span>' +
          esc(opt.texto || '') +
          (isChosen && opt.consequencia
            ? '<span class="ng-opt-consequence">' + esc(opt.consequencia) + '</span>'
            : '') +
        '</button>';
      }).join('');

      inner.innerHTML =
        '<div class="ng-progress">' +
          '<div class="ng-progress-track">' +
            '<div class="ng-progress-fill" style="width:' + pct + '%"></div>' +
          '</div>' +
          '<span class="ng-progress-label">' + (idx + 1) + ' / ' + dilemas.length + '</span>' +
        '</div>' +
        '<div class="ng-card">' +
          '<h2 class="ng-dilema-title">' + esc(d.title) + '</h2>' +
        '</div>' +
        '<div class="ng-options">' + optsHtml + '</div>' +
        '<div class="ng-veredicto">' +
          vSection('O que de fato ocorreu', verd.desfecho) +
          vSection('Lição', verd.licao) +
          vSection('Contexto', verd.sintese) +
          (d.source_url
            ? '<div><span class="ng-verd-label">Fonte</span>' +
              '<a class="ng-source-link" href="' + esc(d.source_url) + '" target="_blank" rel="noopener">' +
              esc(d.source_url) + '</a></div>'
            : '') +
        '</div>' +
        '<button class="ng-btn-next">' + esc(nextLbl) + '</button>';

      inner.querySelector('.ng-btn-next').addEventListener('click', function () {
        idx++;
        if (idx < dilemas.length) {
          renderDilema();
        } else {
          renderEnd();
        }
        wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }

    /* ── Tela final: decide entre veredito narrativo e tela genérica ── */
    function renderEnd() {
      var temVerdict = data.perfis && data.eixos && pesoCount > 0;

      if (!temVerdict) {
        renderEndGenerico();
        return;
      }

      /* Normaliza o vetor acumulado para o intervalo [-1, 1] */
      var vetorNorm = {};
      Object.keys(vetor).forEach(function (eixo) {
        vetorNorm[eixo] = Math.max(-1, Math.min(1, vetor[eixo] / (pesoCount * 20)));
      });

      /* Seleciona o perfil mais próximo por distância euclidiana */
      var melhor    = null;
      var menorDist = Infinity;
      data.perfis.forEach(function (p) {
        var vap  = p.vetor_aproximado || {};
        var soma = 0;
        ['eixo1', 'eixo2', 'eixo3'].forEach(function (eixo) {
          var diff = (vetorNorm[eixo] || 0) - (vap[eixo] || 0);
          soma += diff * diff;
        });
        var dist = Math.sqrt(soma);
        if (dist < menorDist) { menorDist = dist; melhor = p; }
      });

      if (!melhor) {
        renderEndGenerico();
        return;
      }

      renderVerdictNarrativa(melhor, vetorNorm);
    }

    /* ── Veredito narrativo — perfil calculado localmente ── */
    function renderVerdictNarrativa(perfil, vetorNorm) {
      var radar = (vetorNorm && data.eixos) ? buildRadarSVG(vetorNorm, data.eixos) : '';

      inner.innerHTML =
        '<div class="ng-verdict-result">' +
          (radar ? '<div class="ng-radar-wrap">' + radar + '</div>' : '') +
          '<h2>' + esc(perfil.nome) + '</h2>' +
          '<div class="ng-verdict-block">' +
            '<span class="ng-verdict-block-label">Sua força</span>' +
            '<p>' + esc(perfil.forca_dominante) + '</p>' +
          '</div>' +
          '<div class="ng-verdict-block">' +
            '<span class="ng-verdict-block-label">Ponto cego</span>' +
            '<p>' + esc(perfil.ponto_cego) + '</p>' +
          '</div>' +
          '<p class="ng-verdict-share-text">Compartilhe seu resultado e descubra o perfil dos seus amigos!</p>' +
          '<button class="ng-btn-cta ng-btn-share" style="display:block;margin:0 auto 0.75rem">Compartilhar</button>' +
          '<button class="ng-btn-cta ng-btn-restart" style="display:block;margin:0 auto">Jogar novamente</button>' +
        '</div>';

      inner.querySelector('.ng-btn-share').addEventListener('click', function () {
        var btn = this;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(window.location.href).then(function () {
            btn.textContent = '✓ Link copiado!';
          });
        }
      });

      inner.querySelector('.ng-btn-restart').addEventListener('click', function () {
        idx = 0; vetor = {}; pesoCount = 0;
        renderDilema();
        wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }

    /* ── Tela genérica de conclusão (fallback) ── */
    function renderEndGenerico() {
      inner.innerHTML =
        '<div class="ng-screen">' +
          '<h2>Jogo concluído!</h2>' +
          '<p>' +
            'Você respondeu todas as ' + dilemas.length + ' perguntas de <em>' + esc(title) + '</em>.' +
          '</p>' +
          '<button class="ng-btn-cta">Jogar novamente</button>' +
        '</div>';
      inner.querySelector('.ng-btn-cta').addEventListener('click', function () {
        idx = 0; vetor = {}; pesoCount = 0;
        renderDilema();
        wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }

    renderStart();
  }

  /* ── Bootstrap: inicializa todos os containers da página ── */
  function boot() {
    document.querySelectorAll('.ng-game-wrap').forEach(function (el) {
      NgGame(el);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
