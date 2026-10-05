<?php
defined( 'ABSPATH' ) || exit;

// Carrega instância existente (edição) ou define defaults (novo)
$id      = (int) ( $_GET['id'] ?? 0 );
$inst    = $id ? Newsgame_Admin_Instances::get( $id ) : null;
$is_edit = (bool) $inst;

$title         = $is_edit ? $inst->title        : '';
$theme         = $is_edit ? $inst->theme_prompt : '';
$density       = $is_edit ? (int) $inst->density : 10;
$num_options   = $is_edit ? (int) ( $inst->num_options   ?? 2 ) : 2;
$enable_levels = $is_edit ? (bool) ( $inst->enable_levels ?? false ) : false;
$nivel_padrao  = $is_edit ? ( $inst->nivel_padrao ?? 'pedagogico' ) : 'pedagogico';
/*
 * Skin normalizada na LEITURA, e não só na escrita. Uma linha gravada por SQL à
 * mão, ou anterior à migração 1.6, chega aqui com valor ausente ou fora da
 * whitelist — e um `selected()` contra valor inválido deixaria o <select> sem
 * nenhuma opção marcada, que o navegador resolve marcando a primeira. O editor
 * veria "Padrão" numa instância que tem outra coisa no banco, e o próximo save
 * gravaria essa mentira.
 */
$skin          = $is_edit
                    ? Newsgame_Admin_Instances::sanitize_skin( $inst->skin ?? '' )
                    : Newsgame_Admin_Instances::SKIN_DEFAULT;
/*
 * Rótulos de tela. Ficam na view, e não na classe: slug é dado, rótulo é UI.
 * Acrescentar skin exige editar a whitelist (classe) e este mapa (view) — e o
 * `?? $slug` do loop faz a omissão aparecer como slug cru na tela, degradação
 * visível em vez de opção que desaparece.
 */
$rotulos_skin  = array(
    'default'   => 'Padrão — verde terra',
    'neutro'    => 'Neutro — terroso sóbrio',
    'mono'      => 'Mono — preto e branco editorial',
    'colorido'  => 'Colorido — vibrante e lúdico',
    'futurista' => 'Futurista — digital, ciano',
    'pixel'     => 'Pixel — videogame 16 bits, amarelo',
);
/*
 * Resumo de uma linha por skin, para o painel de conferência ao lado da grade.
 * Mesma casa dos rótulos, e pela mesma razão: é texto de UI.
 *
 * Cada frase diz O QUE MUDA, não como é bonito — é o que o editor precisa para
 * decidir. Os raios entre parênteses são os valores reais declarados em
 * `public/css/skins/skin-<slug>.css`; se uma folha mudar de raio, este texto fica
 * velho. É desatualização VISÍVEL (o número está na tela), e não silenciosa, o que
 * a torna preferível a derivar o valor do CSS em PHP.
 */
$resumos_skin  = array(
    'default'   => 'Verde terra sobre fundo escuro, raios 12/8/3. Não carrega folha extra: é a própria base do plugin.',
    'neutro'    => 'Terra queimada e cinzas quentes, raios 8/6/2. Muda só cor e raio — canto mais fechado lê como impresso.',
    'mono'      => 'Preto e branco de fundo escuro, ênfase por peso e não por cor, tipografia serifada. Muda cor, raio e tipografia.',
    'colorido'  => 'Índigo com magenta e amarelo, raios 16/12/4. Muda só cor e raio — a mais lúdica das seis.',
    'futurista' => 'Azul-preto e ciano, raios 2/2/0 (quase retos). Muda só cor e raio.',
    'pixel'     => 'Amarelo de caixa de diálogo, fonte de arcade no cromo e três cenários de fábrica em pixel art — capa, dilemas e resultado. É a única com imagem própria.',
);
/*
 * Miniaturas resolvidas UMA VEZ, antes da grade, porque o mesmo resultado alimenta
 * duas coisas: o cartão de cada skin e o aviso agregado embaixo. Resolver duas
 * vezes daria duas leituras de disco por skin e, pior, duas respostas que podem
 * divergir entre si.
 *
 * Miniatura ausente é ESTADO VÁLIDO: o componente subiu com as seis vazias, por
 * decisão. O aviso conta, não bloqueia.
 */
$skin_thumbs    = array();
$skin_sem_thumb = array();
foreach ( Newsgame_Admin_Instances::SKINS as $slug_skin ) {
    $skin_thumbs[ $slug_skin ] = Newsgame_Admin_Instances::skin_thumb_url( $slug_skin );
    if ( ! $skin_thumbs[ $slug_skin ] ) {
        $skin_sem_thumb[] = $slug_skin;
    }
}
$status        = $is_edit ? $inst->status       : 'draft';
$chars         = $is_edit ? (array) json_decode( $inst->characters ?? '[]', true ) : array();
$axis_matrix   = $is_edit ? ( $inst->axis_matrix ?? '' ) : '';

$form_url = add_query_arg( array( 'action' => 'newsgame_save_instance' ), admin_url( 'admin-post.php' ) );
$list_url = add_query_arg( array( 'page' => 'newsgame-instances' ), admin_url( 'admin.php' ) );
$page_title = $is_edit
    ? 'Editar: ' . esc_html( $title )
    : 'Nova Instância de Jogo';
?>
<style>
/* ── Estilos do formulário de instância ──────────────── */
#ng-form .form-table th    { width: 180px; padding-top: 18px; }
#ng-form .form-table td    { padding-top: 10px; }
.ng-section-title          { margin: 2rem 0 0.25rem; border-bottom: 1px solid #ddd; padding-bottom: 6px; }
/* Builder de personagens */
#ng-chars-list             { margin: 0.75rem 0; }
.ng-char-row               { display: flex; gap: 10px; align-items: flex-start;
                             padding: 12px 14px; background: #f6f7f7;
                             border: 1px solid #dcdcde; border-radius: 4px;
                             margin-bottom: 8px; }
.ng-char-row .ng-col-label { flex: 1 1 200px; }
.ng-char-row .ng-col-desc  { flex: 2 1 300px; }
.ng-char-row .ng-col-rm    { flex: 0 0 auto; padding-top: 22px; }
.ng-char-row label         { display: block; font-size: 12px; font-weight: 600;
                             margin-bottom: 4px; color: #444; }
.ng-char-row input         { width: 100%; box-sizing: border-box; }
#ng-chars-list:empty::after {
    content: 'Nenhum personagem definido. Clique em "+ Adicionar Personagem".';
    display: block; color: #888; font-style: italic; font-size: 13px;
}
/* Barra de ações fixa no rodapé da tela */
#ng-sticky-actions         { position: sticky; bottom: 0; background: #fff;
                             border-top: 1px solid #dcdcde; padding: 12px 0;
                             margin-top: 2rem; display: flex; gap: 10px;
                             align-items: center; z-index: 10; }
#ng-density-display        { font-weight: 600; color: #2d7a2d; }

/* ── Mesa Editorial ─────────────────────────────────────── */
#ng-review-section         { margin-top: 2.5rem; }
.ng-review-tabs            { display: flex; gap: 0; border-bottom: 2px solid #2d7a2d;
                             margin-bottom: 1rem; }
.ng-tab                    { background: #f6f7f7; border: 1px solid #dcdcde;
                             border-bottom: none; padding: 8px 18px; cursor: pointer;
                             font-size: 13px; font-weight: 600; color: #444;
                             border-radius: 4px 4px 0 0; margin-right: 3px;
                             transition: background 0.15s; }
.ng-tab:hover              { background: #e8f5e2; }
.ng-tab.is-active          { background: #2d7a2d; color: #fff; border-color: #2d7a2d; }
.ng-tab-badge              { display: inline-block; background: rgba(0,0,0,.15);
                             border-radius: 10px; padding: 0 7px; font-size: 11px;
                             margin-left: 6px; min-width: 18px; text-align: center; }
.ng-tab.is-active .ng-tab-badge { background: rgba(255,255,255,.3); }
#ng-review-list            { border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; }
.ng-qrow                   { border-bottom: 1px solid #e8e8e8; }
.ng-qrow:last-child        { border-bottom: none; }
.ng-qrow-head              { display: flex; align-items: center; gap: 10px;
                             padding: 10px 14px; cursor: pointer; background: #fff;
                             transition: background 0.12s; user-select: none; }
.ng-qrow-head:hover        { background: #f0f8eb; }
.ng-qrow-head.is-open      { background: #e8f5e2; }
.ng-qrow-num               { flex: 0 0 28px; font-size: 12px; color: #888;
                             font-weight: 700; text-align: right; }
.ng-qrow-title             { flex: 1 1 auto; font-size: 13px; font-weight: 600;
                             color: #1d2327; white-space: nowrap; overflow: hidden;
                             text-overflow: ellipsis; }
.ng-qrow-meta              { flex: 0 0 auto; font-size: 11px; color: #888; }
.ng-qrow-chevron           { flex: 0 0 20px; text-align: center; color: #888;
                             font-size: 16px; transition: transform 0.2s; }
.ng-qrow-head.is-open .ng-qrow-chevron { transform: rotate(90deg); }
.ng-qrow-body              { padding: 0 16px 16px; background: #fafafa;
                             border-top: 1px solid #e8e8e8; display: none; }
.ng-edit-table             { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
.ng-edit-table th          { width: 160px; text-align: right; padding: 6px 12px 6px 0;
                             vertical-align: top; font-size: 12px; color: #555;
                             font-weight: 600; white-space: nowrap; }
.ng-edit-table td          { padding: 4px 0; }
.ng-img-field              { display: flex; align-items: flex-start; gap: 12px; }
.ng-img-preview            { flex: 0 0 80px; }
.ng-img-preview img        { width: 80px; height: 60px; object-fit: cover; display: block;
                             border-radius: 3px; border: 1px solid #c3c4c7; }
.ng-img-vazio              { display: flex; align-items: center; justify-content: center;
                             width: 80px; height: 60px; font-size: 11px; color: #646970;
                             border: 1px dashed #c3c4c7; border-radius: 3px; text-align: center; }
.ng-img-controls           { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
.ng-img-credito            { font-size: 12px; color: #646970; }
.ng-img-sem-credito        { color: #b32d2e; }
.ng-edit-table textarea,
.ng-edit-table input[type=text],
.ng-edit-table input[type=url] { width: 100%; box-sizing: border-box;
                                 font-size: 13px; padding: 5px 8px; }
.ng-source-check           { font-size: 12px; color: #2271b1; margin-top: 3px;
                             display: inline-block; }
/* Zona de ações separada dos campos por margem e linha.
   Antes eram 4px de padding entre o último campo e os botões — e "Pedir
   Substituta", que gasta cota do Gemini, ficava a 4px de um campo de edição.
   O campo de imagem, sendo a última linha da tabela, tornou o clique errado
   fácil o suficiente para acontecer com quem conhece a tela. A separação vale
   para qualquer campo que venha a ser o último, não só para este. */
.ng-qrow-actions           { display: flex; gap: 8px; align-items: center;
                             margin-top: 18px; padding-top: 12px; flex-wrap: wrap;
                             border-top: 1px solid #dcdcde; }
.ng-action-msg             { font-size: 12px; margin-left: 6px; }
.ng-review-empty           { padding: 1.5rem; color: #888; font-style: italic;
                             text-align: center; }
#ng-review-pagination      { padding: 10px 0; display: flex; gap: 4px;
                             flex-wrap: wrap; }
.ng-page-btn               { padding: 4px 10px; border: 1px solid #dcdcde;
                             background: #f6f7f7; border-radius: 3px; cursor: pointer;
                             font-size: 12px; }
.ng-page-btn:hover         { background: #e8f5e2; border-color: #2d7a2d; }
.ng-page-btn.is-current    { background: #2d7a2d; color: #fff; border-color: #2d7a2d; }
.ng-review-loading         { padding: 1.5rem; color: #888; text-align: center; }
.ng-char-count             { font-size: 11px; color: #888; margin-top: 2px; display: block; }
.ng-char-count.is-over     { color: #c00; font-weight: 600; }
</style>

<div class="wrap">
  <h1><?php echo $page_title; ?></h1>
  <a href="<?php echo esc_url( $list_url ); ?>" style="display:inline-block;margin-bottom:1rem">
    &larr; Voltar para lista
  </a>

  <?php if ( isset( $_GET['saved'] ) && $is_edit ) : ?>
    <div class="notice notice-success is-dismissible"><p>Salvo ✓</p></div>
  <?php endif; ?>

  <?php if ( isset( $_GET['error'] ) && 'title_required' === $_GET['error'] ) : ?>
    <div class="notice notice-error"><p>O nome do jogo é obrigatório.</p></div>
  <?php endif; ?>

  <?php if ( isset( $_GET['error'] ) && 'db_write' === $_GET['error'] ) : ?>
    <div class="notice notice-error"><p>Não foi possível gravar a instância no banco — nada foi salvo, e as alterações deste envio se perderam. A causa está no log do servidor (<code>error_log</code>).</p></div>
  <?php endif; ?>

  <?php
  // Pendência de pesos: sem eles o jogador recebe veredito genérico e nada avisa
  // — nem ele, nem o editor. Sem is-dismissible de propósito: reaparece a cada
  // carga da tela até a pendência sumir.
  $sem_peso = $id ? Newsgame_Admin_Instances::count_unweighted_approved( $id ) : 0;
  //
  // Os `id` abaixo existem para o JS poder atualizar isto SEM F5 depois de
  // pesar — é o C6, aberto desde 13/08. O bloco continua condicional: quando não
  // há pendência no carregamento, o notice simplesmente não existe, e o JS
  // (`pintarPendencia`) trata a ausência.
  if ( $sem_peso > 0 ) : ?>
    <div class="notice notice-error" id="ng-notice-unweighted">
      <p>
        <strong>⚖ <span id="ng-notice-unweighted-count"><?php echo (int) $sem_peso; ?></span> pergunta(s) aprovada(s) sem pesos de eixo.</strong>
        Sem pesos o jogo entrega um veredito genérico no fim da partida, sem o
        gráfico de perfil — e nada avisa o jogador.
        Use <strong>&ldquo;⚖ Pesar os que faltam&rdquo;</strong> na Mesa Editorial, abaixo
        &mdash; ele processa só estas, sem recalcular as que já têm peso.
        <?php if ( isset( $_GET['error'] ) && 'unweighted_approved' === $_GET['error'] ) : ?>
          <br><em>A publicação foi bloqueada por isso — o status voltou para &ldquo;Em Revisão&rdquo;.</em>
        <?php endif; ?>
      </p>
    </div>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url( $form_url ); ?>" id="ng-form">
    <?php wp_nonce_field( 'newsgame_save_instance', 'nonce' ); ?>
    <?php if ( $is_edit ) : ?>
      <input type="hidden" name="instance_id" value="<?php echo (int) $id; ?>">
    <?php endif; ?>
    <input type="hidden" id="ng-status-input" name="status" value="<?php echo esc_attr( $status ); ?>">
    <?php // 'stay' ou 'list' — preenchido pelo botão clicado. Ver handle_save(). ?>
    <input type="hidden" id="ng-after-input" name="ng_after" value="stay">

    <!-- ── Identificação ─────────────────────────────────── -->
    <h2 class="ng-section-title">Identificação</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row">
          <label for="ng-title">Nome do Jogo <span style="color:#c00" aria-label="obrigatório">*</span></label>
        </th>
        <td>
          <input type="text" id="ng-title" name="title"
                 value="<?php echo esc_attr( $title ); ?>"
                 class="large-text" maxlength="500" required
                 placeholder="Ex: Grandes Estreias do Teatro, Geração Z…">
          <p class="description">
            Aparece no painel e gera o shortcode
            <code>[news-game id="<?php echo $is_edit ? (int) $id : 'X'; ?>"]</code>.
          </p>
        </td>
      </tr>
    </table>

    <!-- ── Contexto de IA ────────────────────────────────── -->
    <h2 class="ng-section-title">Contexto de IA</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row">
          <label for="ng-theme">Descrição do Acervo</label>
        </th>
        <td>
          <textarea id="ng-theme" name="theme_prompt" rows="7" class="large-text"
                    placeholder="Descreva o tema do jogo e o acervo que o alimenta.

Exemplo:
Este jogo é sobre a história do teatro brasileiro, baseado no acervo do portal Exemplo com 800 reportagens. Os dilemas exploram escolhas éticas e artísticas de diretores, atores e produtores ao longo de décadas. O público-alvo são jovens entre 18 e 30 anos."><?php echo esc_textarea( $theme ); ?></textarea>
          <p class="description">
            Este texto é enviado à IA como contexto da geração. Seja específico:
            descreva o tema, o tipo de acervo e quem é o público-alvo.
          </p>

          <button
            type="button"
            id="btn-analisar-eixos"
            class="button button-secondary"
            style="margin-top: 8px;"
            <?php
            // Sem ID não há onde gravar a matriz — o handler recusaria com erro.
            // Desabilitado no PHP, e não no JS, para não piscar habilitado.
            disabled( ! $is_edit );
            if ( ! $is_edit ) : ?>aria-describedby="ng-analisar-dica"<?php endif; ?>
          >
            🔍 Analisar Acervo e Propor Eixos
          </button>
          <?php if ( ! $is_edit ) : ?>
            <p class="description" id="ng-analisar-dica">
              Salve o rascunho antes — a análise grava os eixos nesta instância,
              e ela só passa a existir no primeiro salvamento.
            </p>
          <?php endif; ?>

          <div id="container-eixos-dinamicos" style="margin-top: 16px;"><?php if ( $axis_matrix ) : ?>
            <div style="background:#f0f7f0; border:1px solid #00a32a; padding:16px; border-radius:4px;">
              <strong style="color:#00a32a; font-size:13px;">✅ Matriz de eixos salva — edite e confirme se necessário</strong>
              <p style="font-size:12px; color:#555; margin:8px 0;">Edite o JSON abaixo se necessário antes de salvar.</p>
              <textarea id="json-eixos-editavel" rows="15" style="width:100%; font-family:monospace; font-size:12px; background:#222; color:#fff; padding:10px; border-radius:4px; border:1px solid #555; box-sizing:border-box;"><?php
                $axis_decoded = json_decode( $axis_matrix, true );
                echo esc_textarea( $axis_decoded
                    ? wp_json_encode( $axis_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
                    : $axis_matrix
                );
              ?></textarea>
              <button type="button" id="btn-salvar-matriz" class="button button-primary" style="margin-top:10px;">✓ Confirmar e Salvar Matriz de Eixos</button>
            </div>
          <?php endif; ?></div>
        </td>
      </tr>
    </table>

    <!-- ── Configuração ──────────────────────────────────── -->
    <h2 class="ng-section-title">Configuração</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row">
          <label for="ng-density">Número de Perguntas/Rodadas</label>
        </th>
        <td>
          <input type="number" id="ng-density" name="density"
                 value="<?php echo (int) $density; ?>"
                 min="4" max="15" step="1"
                 style="width:80px;vertical-align:middle">
          &nbsp;perguntas
          <p class="description">
            Número de dilemas gerados por sessão de jogo.
            Mínimo: 4 &nbsp;|&nbsp; Máximo: 15
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row">Opções de Resposta</th>
        <td>
          <label style="margin-right:1.5rem">
            <input type="radio" name="num_options" value="2"
                   <?php checked( $num_options, 2 ); ?>>
            2 opções &nbsp;<code>A / B</code>
          </label>
          <label>
            <input type="radio" name="num_options" value="3"
                   <?php checked( $num_options, 3 ); ?>>
            3 opções &nbsp;<code>A / B / C</code>
          </label>
          <p class="description">
            Número de alternativas geradas por pergunta. 3 opções aumentam a complexidade narrativa.
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row">Níveis de Dificuldade</th>
        <td>
          <label>
            <input type="checkbox" id="ng-enable-levels" name="enable_levels" value="1"
                   <?php checked( $enable_levels ); ?>>
            Ativar Níveis (Aprendiz / Pro)
          </label>
          <p class="description">
            Se ativo, a IA calibra a complexidade em dois níveis: Aprendiz (linguagem acessível)
            e Pro (jornalístico e sistêmico, com 20% das perguntas no nível avançado).
            Se inativo, todas as perguntas têm complexidade uniforme.
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row">
          <label for="ng-nivel-padrao">Perfil do Jogo</label>
        </th>
        <td>
          <select id="ng-nivel-padrao" name="nivel_padrao" style="min-width:200px">
            <option value="ludico"     <?php selected( $nivel_padrao, 'ludico' ); ?>>Lúdico</option>
            <option value="pedagogico" <?php selected( $nivel_padrao, 'pedagogico' ); ?>>Pedagógico</option>
            <option value="complexo"   <?php selected( $nivel_padrao, 'complexo' ); ?>>Complexo</option>
          </select>
          <p class="description">
            Define o tom narrativo das perguntas geradas pela IA.<br>
            <strong>Lúdico:</strong> linguagem leve, dilemas de fácil identificação.<br>
            <strong>Pedagógico:</strong> equilíbrio entre acessibilidade e profundidade (padrão).<br>
            <strong>Complexo:</strong> dilemas sistêmicos, linguagem jornalística densa.
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row">Aparência (Skin)</th>
        <td>
          <?php
          /*
           * SELETOR VISUAL — fieldset com RADIO NATIVO, um por skin.
           *
           * Substitui um <select> de seis opções. O ganho não é enfeite: skin é
           * escolha visual, e um nome de lista ("Futurista — digital, ciano") pede
           * que o editor imagine o resultado, publique e volte para conferir.
           *
           * ⚠️ RADIO NATIVO, E O REQUISITO CENTRAL ESTÁ AQUI: num grupo de radios o
           * navegador SEMPRE envia o valor marcado, e a marcação é impressa pelo PHP
           * a partir da skin do banco. Salvar o formulário sem tocar no seletor manda
           * de volta a skin que já estava gravada — nunca 'default'. É a mesma
           * propriedade que o <select> tinha, e é o que o `isset( $_POST['skin'] )`
           * do handle_save() protege desde 27/08 (família do C11: sobrescrita
           * silenciosa).
           *
           * ⚠️ NÃO ESCONDER O INPUT (display:none, opacity:0, largura zero) para
           * fazer o cartão inteiro parecer o controle. É o atalho comum em seletor de
           * cards e custa a navegação por setas, o anel de foco e o estado anunciado
           * ao leitor de tela — tudo que vem de graça do elemento nativo. O <label>
           * envolve o cartão, então clicar na miniatura já marca a skin sem isso.
           */
          ?>
          <fieldset id="ng-skin-picker" class="ng-skin-picker">
            <legend class="screen-reader-text">
              Aparência (Skin) — escolha uma das <?php echo count( Newsgame_Admin_Instances::SKINS ); ?> skins de fábrica
            </legend>

            <div class="ng-skin-grid">
              <?php
              foreach ( Newsgame_Admin_Instances::SKINS as $slug ) :
                  $rotulo = $rotulos_skin[ $slug ] ?? $slug;
                  $thumb  = $skin_thumbs[ $slug ];
              ?>
                <label class="ng-skin-card" for="ng-skin-<?php echo esc_attr( $slug ); ?>">
                  <span class="ng-skin-thumb">
                    <?php if ( $thumb ) : ?>
                      <?php
                      /*
                       * alt="" de propósito: a miniatura é REDUNDANTE com o nome da
                       * skin, que está no rótulo logo abaixo e é o que carrega a
                       * informação para quem não vê a imagem. Descrever a arte aqui
                       * faria o leitor de tela anunciar a mesma skin duas vezes.
                       */
                      ?>
                      <img src="<?php echo esc_url( $thumb ); ?>" alt=""
                           width="240" height="135" loading="lazy" decoding="async">
                    <?php else : ?>
                      <?php
                      /*
                       * PLACEHOLDER — a skin continua selecionável sem miniatura. O
                       * componente subiu assim, com as seis vazias, por decisão, e a
                       * arte entra depois soltando arquivo em `admin/images/skins/`.
                       * aria-hidden porque é decoração: quem diz em TEXTO o que falta
                       * é o aviso agregado abaixo da grade.
                       */
                      ?>
                      <span class="ng-skin-thumb-vazio" aria-hidden="true">
                        <b>&#9638;</b>
                        sem miniatura
                      </span>
                    <?php endif; ?>
                  </span>
                  <span class="ng-skin-rotulo">
                    <input type="radio" class="ng-skin-radio"
                           id="ng-skin-<?php echo esc_attr( $slug ); ?>"
                           name="skin" value="<?php echo esc_attr( $slug ); ?>"
                           <?php checked( $skin, $slug ); ?>
                           data-ng-rotulo="<?php echo esc_attr( $rotulo ); ?>"
                           data-ng-resumo="<?php echo esc_attr( $resumos_skin[ $slug ] ?? '' ); ?>">
                    <span class="ng-skin-nome"><?php echo esc_html( $rotulo ); ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>

            <?php if ( $skin_sem_thumb ) : ?>
              <p class="ng-skin-falta">
                &#9888; <strong><?php echo count( $skin_sem_thumb ); ?> de <?php echo count( Newsgame_Admin_Instances::SKINS ); ?></strong>
                sem miniatura em disco (<?php echo esc_html( implode( ', ', $skin_sem_thumb ) ); ?>).
                A escolha funciona igual — a miniatura é conferência visual, não requisito.
                Para preencher, solte <code>skin-&lt;slug&gt;.webp</code> (480&times;270) em
                <code>admin/images/skins/</code>: aparece sozinha, sem mudança de código.
              </p>
            <?php endif; ?>
          </fieldset>

          <?php
          /*
           * PAINEL DE CONFERÊNCIA — o que a skin marcada faz, em texto, antes de
           * salvar. Renderizado pelo PHP com a skin do banco e mantido em dia pelo
           * `admin/js/newsgame-admin-skins.js`. Sem JS ele fica estático mostrando a
           * skin gravada, que é informação correta — degrada, não mente.
           *
           * aria-live="polite" porque o conteúdo muda sem recarregar a tela: sem
           * isso, quem troca de skin com as setas do teclado não recebe nada além do
           * nome do próprio radio.
           */
          ?>
          <div class="ng-skin-confere" id="ng-skin-confere" aria-live="polite">
            <p class="ng-skin-confere-linha">
              Selecionada: <strong id="ng-skin-confere-label"><?php echo esc_html( $rotulos_skin[ $skin ] ?? $skin ); ?></strong>
            </p>
            <p class="ng-skin-confere-resumo" id="ng-skin-confere-resumo"><?php echo esc_html( $resumos_skin[ $skin ] ?? '' ); ?></p>
          </div>

          <p class="description">
            Paleta de cores e cantos do jogo publicado. <strong>Padrão</strong> usa
            apenas a folha base, sem arquivo extra.<br>
            <strong>Cinco das seis skins mudam só cor, raio e tipografia</strong> —
            não trocam texto, layout nem imagem do jogo. A <strong>Pixel</strong> é a
            única que também traz <strong>cenários de fábrica</strong> em pixel art —
            um para a capa, um para os dilemas e um para o resultado.<br>
            A imagem escolhida dilema a dilema na Mesa Editorial
            <strong>continua vencendo</strong> em qualquer skin: na Pixel, o
            cenário aparece só nos dilemas que não têm imagem própria.<br>
            O gráfico do veredito mantém verde/vermelho em todas as skins: essas
            cores significam resultado do jogador, não identidade do veículo.
          </p>
        </td>
      </tr>
    </table>

    <!-- ── Personagens ───────────────────────────────────── -->
    <h2 class="ng-section-title">Personagens do Jogo</h2>
    <p class="description" style="margin-bottom:0.75rem">
      Defina quem são as personas desta instância. Cada personagem representa um
      ponto de vista nas perguntas geradas pela IA.<br>
      Exemplo: <em>Diretor de Teatro, Ator, Produtor Executivo, Crítico Cultural.</em><br>
      <strong>Sem personagens?</strong> O motor entra no <strong>Modo Perguntas Gerais</strong>:
      a IA gerará perguntas para um jogador genérico, sem perspectiva fixa de personagem.
    </p>

    <div id="ng-chars-list" role="list" aria-label="Personagens"></div>

    <button type="button" id="ng-add-char" class="button button-secondary">
      + Adicionar Personagem
    </button>

    <!-- JSON serializado dos personagens — lido pelo PHP no submit -->
    <input type="hidden" id="ng-chars-json" name="characters_json"
           value="<?php echo esc_attr( wp_json_encode( array_values( $chars ) ) ); ?>">

    <!-- Template de linha de personagem (não renderizado pelo browser) -->
    <template id="ng-char-tpl">
      <div class="ng-char-row" role="listitem">
        <div class="ng-col-label">
          <label>Nome / Label</label>
          <input type="text" class="ng-char-label regular-text"
                 placeholder="Ex: Diretor de Teatro" maxlength="200"
                 aria-label="Nome do personagem">
        </div>
        <div class="ng-col-desc">
          <label>Descrição para a IA</label>
          <input type="text" class="ng-char-desc widefat"
                 placeholder="Ex: Representa a perspectiva artística e a visão de quem concebe o espetáculo"
                 aria-label="Descrição para a IA">
        </div>
        <div class="ng-col-rm">
          <button type="button" class="ng-rm button" style="color:#c00;border-color:#c00"
                  title="Remover personagem" aria-label="Remover">&#x2715;</button>
        </div>
      </div>
    </template>

    <!-- ── Ações ─────────────────────────────────────────── -->
    <div id="ng-sticky-actions">
      <button type="button" class="button button-secondary" id="ng-btn-draft">
        Salvar como Rascunho
      </button>
      <button type="button" class="button button-primary" id="ng-btn-review">
        Salvar e Preparar para Geração ›
      </button>
      <button type="button" class="button-link" id="ng-btn-back">
        Salvar e voltar à lista
      </button>
      <span id="ng-action-feedback" style="color:#555;font-size:13px"></span>
    </div>

  </form><!-- #ng-form -->

  <?php
  $backend_ok = (bool) Newsgame_Settings::backend_url();
  if ( $is_edit && $backend_ok ) :
  ?>
  <!-- ── Seção de Geração ───────────────────────────────── -->
  <hr style="margin:2rem 0 1.5rem">
  <h2>Geração de Perguntas</h2>

  <?php
  $dilema_count = (int) $GLOBALS['wpdb']->get_var(
      $GLOBALS['wpdb']->prepare(
          "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}newsgame_dilemas WHERE instance_id = %d",
          $id
      )
  );
  $approved_count = (int) $GLOBALS['wpdb']->get_var(
      $GLOBALS['wpdb']->prepare(
          "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}newsgame_dilemas WHERE instance_id = %d AND review_status = 'approved'",
          $id
      )
  );
  $pending_count = (int) $GLOBALS['wpdb']->get_var(
      $GLOBALS['wpdb']->prepare(
          "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}newsgame_dilemas WHERE instance_id = %d AND review_status = 'pending'",
          $id
      )
  );
  // Mesma conta de Newsgame_Generator::ajax_start(): aprovadas + pendentes contra
  // a meta. Se as duas divergirem, a tela promete um número e o lote gera outro.
  $deficit = $density - $approved_count - $pending_count;
  ?>
  <?php if ( $dilema_count > 0 ) : ?>
    <div class="notice notice-info inline" style="margin:0 0 1rem">
      <p>Esta instância já possui <strong><?php echo esc_html( $dilema_count ); ?> perguntas</strong>.
         Gerar de novo só completa a meta: gera as que faltam para aprovadas + pendentes
         chegarem a <strong><?php echo (int) $density; ?></strong>.</p>
    </div>
  <?php endif; ?>

  <p class="description" style="margin-bottom:1rem">
    O backend buscará no ChromaDB os trechos relevantes para cada personagem,
    enviará à IA e salvará as perguntas em <code>wp_newsgame_dilemas</code>.
    Meta: <strong><?php echo (int) $density; ?> perguntas</strong>
    com <strong><?php echo (int) $num_options; ?> opções de resposta</strong><?php echo $enable_levels ? ', níveis ativados' : ''; ?>.
    <?php if ( $approved_count + $pending_count > 0 ) : ?>
      Aprovadas: <strong><?php echo (int) $approved_count; ?></strong>,
      pendentes: <strong><?php echo (int) $pending_count; ?></strong>.
      <?php if ( $deficit <= 0 ) : ?>
        A meta já está preenchida.
      <?php elseif ( 1 === $deficit ) : ?>
        Será gerada apenas <strong>1</strong> pergunta, a que falta.
      <?php else : ?>
        Serão geradas apenas as <strong><?php echo (int) $deficit; ?></strong> que faltam.
      <?php endif; ?>
    <?php endif; ?>
  </p>

  <button type="button" id="ng-generate-btn" class="button button-primary button-large">
    <?php if ( $dilema_count > 0 && $deficit > 0 ) : ?>
      &#9654; Gerar as que faltam (<?php echo (int) $deficit; ?>)
    <?php else : ?>
      &#9654; Gerar Perguntas Agora
    <?php endif; ?>
  </button>
  <span id="ng-gen-status" style="margin-left:12px;font-size:13px;font-weight:600"></span>
  <?php /* Relatório do último lote. Preenchido pelo JS — inclusive depois do reload,
           via sessionStorage — e nunca pelo PHP. */ ?>
  <div id="ng-gen-report" class="notice inline" style="display:none;margin:1rem 0 0;max-width:600px"></div>

  <?php if ( $dilema_count > 0 ) : ?>
  <button type="button" id="ng-reset-btn" class="button"
          style="margin-left:16px;background:#c00;border-color:#9e0000;color:#fff">
    🗑️ Limpar e Resetar Todas as Perguntas
  </button>
  <?php endif; ?>

  <div id="ng-progress-wrap" style="display:none;margin-top:1.5rem;max-width:600px">
    <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px">
      <span id="ng-progress-label">Aguardando backend…</span>
      <span id="ng-progress-pct">0%</span>
    </div>
    <div style="background:#ddd;border-radius:4px;height:12px;overflow:hidden">
      <div id="ng-progress-bar" style="background:#2d7a2d;height:100%;width:0%;transition:width 0.4s;border-radius:4px"></div>
    </div>
    <p id="ng-progress-last" style="font-size:12px;color:#666;margin-top:6px"></p>
  </div>

  <!-- O JS desta seção mora em admin/js/newsgame-admin-generation.js (gerar e
       resetar perguntas) e admin/js/newsgame-admin-axis.js (analisar acervo e
       salvar a matriz de eixos), enfileirados por
       Newsgame_Admin_Instances::enqueue_form_scripts(). Ver A3 / FLAG 007. -->

  <?php endif; // is_edit && backend_ok ?>

<?php
// ── Mesa Editorial HIL ────────────────────────────────────
// Aparece sempre que houver perguntas no banco, independente do status da instância
$review_total = $id ? (int) $GLOBALS['wpdb']->get_var(
    $GLOBALS['wpdb']->prepare(
        "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}newsgame_dilemas WHERE instance_id = %d",
        $id
    )
) : 0;
$weighted_count = (int) $GLOBALS['wpdb']->get_var(
    $GLOBALS['wpdb']->prepare(
        "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}newsgame_dilemas WHERE instance_id = %d AND axis_weights IS NOT NULL",
        $id
    )
);
if ( $id && $review_total > 0 ) :
?>
<hr style="margin:2.5rem 0 2rem">
<div id="ng-review-section">
  <h2>Mesa Editorial
    <span style="font-size:13px;font-weight:400;color:#666;margin-left:8px">
      — revise, edite e aprove as perguntas antes de publicar no jogo
    </span>
  </h2>

  <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:1rem">
    <div class="ng-review-tabs" role="tablist" style="margin-bottom:0">
      <button class="ng-tab is-active" data-tab="pending" role="tab">
        Pendentes <span class="ng-tab-badge" id="ng-count-pending">…</span>
      </button>
      <button class="ng-tab" data-tab="approved" role="tab">
        Aprovadas <span class="ng-tab-badge" id="ng-count-approved">…</span>
      </button>
      <button class="ng-tab" data-tab="discarded" role="tab">
        Descartadas <span class="ng-tab-badge" id="ng-count-discarded">…</span>
      </button>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <?php if ( $axis_matrix ) : ?>
      <?php
      /*
       * DOIS CONTROLES, UMA ACTION. O barato é botão e o caro é link, de
       * propósito: repor o peso de um dilema editado é rotina, e reatribuir a
       * instância inteira gasta a cota de todos os dilemas. Dar a mesma
       * proeminência aos dois convidaria ao gesto caro.
       *
       * O CONTADOR DO BOTÃO É `$sem_peso` (aprovados sem peso), o MESMO número
       * do aviso no topo da tela — é ele que bloqueia a publicação, e é por ele
       * que o editor chega aqui.
       *   ⚠️ Ressalva: a operação pesa TODOS os dilemas sem peso, inclusive os
       *   pendentes, então o número efetivamente processado pode ser maior que o
       *   do rótulo. Está no `title`, e a mensagem de retorno informa o real.
       */
      ?>
      <button type="button" id="ng-btn-assign-weights" class="button"
              style="white-space:nowrap"
              title="Chama a IA apenas para os dilemas que estão sem peso — inclusive os pendentes. Não altera quem já tem peso.">
        ⚖ Pesar os que faltam<?php if ( $sem_peso > 0 ) : ?> <span id="ng-assign-pending" style="font-size:11px;color:#555;">(<?php echo (int) $sem_peso; ?>)</span><?php endif; ?>
      </button>
      <button type="button" id="ng-btn-reassign-weights" class="button-link"
              style="font-size:12px;white-space:nowrap"
              title="Recalcula os pesos de TODOS os dilemas, sobrescrevendo os existentes. Necessário quando a matriz de eixos foi regerada.">
        reatribuir todos<?php if ( $weighted_count > 0 ) : ?> (<?php echo $weighted_count; ?>/<?php echo $review_total; ?>)<?php endif; ?>
      </button>
      <span id="ng-assign-msg" style="font-size:12px;"></span>
      <?php endif; ?>
      <button type="button" id="ng-btn-approve-all" class="button button-primary"
              style="margin-bottom:2px;white-space:nowrap">
        ✓ Aprovar Todas as Pendentes
      </button>
    </div>
  </div>

  <div id="ng-review-list" role="list">
    <p class="ng-review-loading">Carregando…</p>
  </div>

  <div id="ng-review-pagination"></div>
</div><!-- #ng-review-section -->

<!-- O JS da Mesa Editorial mora em admin/js/newsgame-admin-review.js,
     enfileirado por Newsgame_Admin_Instances::enqueue_form_scripts().
     Ver A3 / FLAG 007. -->
<?php endif; // is_edit && review_total > 0 ?>

</div>

<!-- O JS do formulário (builder de personagens e botões de ação) mora em
     admin/js/newsgame-admin-form.js, enfileirado por
     Newsgame_Admin_Instances::enqueue_form_scripts(). Ver A3 / FLAG 007. -->
