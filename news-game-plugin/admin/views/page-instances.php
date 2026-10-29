<?php
defined( 'ABSPATH' ) || exit;

$instances = Newsgame_Admin_Instances::get_all();
$new_url   = add_query_arg( array( 'page' => 'newsgame-instances', 'action' => 'new' ), admin_url( 'admin.php' ) );

// Status DERIVADO (Newsgame_Status), não a coluna `status`. Cores de selo.
$status_colors = array(
    Newsgame_Status::RASCUNHO => '#777',
    Newsgame_Status::REVISAO  => '#b8860b',
    Newsgame_Status::PRONTO   => '#2d7a2d',
);
?>
<div class="wrap">
  <h1 class="wp-heading-inline">News-Game &mdash; Instâncias</h1>
  <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">+ Nova Instância</a>
  <hr class="wp-header-end">

  <?php if ( isset( $_GET['saved'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>Alterações salvas. Se o site usa cache de servidor ou CDN (por exemplo, o Cache Manager da hospedagem), limpe esse cache para o visitante ver a mudança.</p></div>
  <?php endif; ?>
  <?php if ( isset( $_GET['deleted'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>Instância excluída.</p></div>
  <?php endif; ?>
  <?php if ( isset( $_GET['error'] ) && 'db_write' === $_GET['error'] ) : ?>
    <div class="notice notice-error"><p>Não foi possível excluir a instância — a operação foi desfeita e nada foi apagado. A causa está no log do servidor (<code>error_log</code>).</p></div>
  <?php endif; ?>
  <?php if ( isset( $_GET['error'] ) && 'not_found' === $_GET['error'] ) : ?>
    <div class="notice notice-error"><p>A instância não existe mais — talvez já tenha sido excluída. Nada foi apagado.</p></div>
  <?php endif; ?>

  <?php if ( empty( $instances ) ) : ?>
    <div style="margin-top:2rem;text-align:center;color:#555">
      <p style="font-size:16px">Nenhuma instância criada ainda.</p>
      <a href="<?php echo esc_url( $new_url ); ?>" class="button button-primary button-large">
        + Criar primeira instância
      </a>
    </div>

  <?php else : ?>
    <table class="wp-list-table widefat fixed striped" style="margin-top:1rem">
      <thead>
        <tr>
          <th scope="col" style="width:50px">ID</th>
          <th scope="col">Nome do Jogo</th>
          <th scope="col" style="width:120px">Status</th>
          <th scope="col" style="width:120px">Dilemas</th>
          <th scope="col" style="width:110px">Criado em</th>
          <th scope="col" style="width:130px">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php
        // Contagem por grupo de TODAS as instâncias numa query só (item 58): o
        // selo depende dos grupos, e uma query por linha seria o N+1 que o
        // get_all() já evita para as outras contagens.
        $por_grupo = Newsgame_Status::contagem_por_grupo();
        foreach ( $instances as $inst ) :
          $edit_url   = add_query_arg( array( 'page' => 'newsgame-instances', 'action' => 'edit', 'id' => $inst->id ), admin_url( 'admin.php' ) );
          $delete_url = wp_nonce_url(
            add_query_arg( array( 'action' => 'newsgame_delete_instance', 'id' => $inst->id ), admin_url( 'admin-post.php' ) ),
            "newsgame_delete_{$inst->id}"
          );
          $st    = Newsgame_Status::avaliar( $inst, (int) $inst->aprovadas, (int) $inst->pendentes, (int) $inst->sem_peso, $por_grupo[ (int) $inst->id ] ?? array() );
          $color = $status_colors[ $st['estado'] ];
          $chars = json_decode( $inst->characters ?? '[]', true );
          $chars_preview = $chars ? implode( ', ', array_column( $chars, 'label' ) ) : '—';
        ?>
        <tr>
          <td><?php echo (int) $inst->id; ?></td>
          <td>
            <strong>
              <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $inst->title ); ?></a>
            </strong>
            <div style="margin-top:3px">
              <code style="font-size:11px">[news-game id="<?php echo (int) $inst->id; ?>"]</code>
              <?php if ( Newsgame_Status::PRONTO !== $st['estado'] ) : ?>
                <div style="font-size:11px;color:#b8860b">
                  ⚠ Ainda não está Pronto — o jogo aparece mesmo assim se o shortcode estiver numa página.
                </div>
              <?php endif; ?>
            </div>
            <?php if ( $chars_preview !== '—' ) : ?>
              <div style="font-size:12px;color:#888;margin-top:2px">
                <?php echo esc_html( $chars_preview ); ?>
              </div>
            <?php endif; ?>
          </td>
          <td>
            <span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600">
              <?php echo esc_html( $st['rotulo'] ); ?>
            </span>
            <?php if ( $st['falta'] ) : ?>
              <div style="font-size:11px;color:#666;margin-top:2px">
                <?php echo esc_html( 'Falta: ' . implode( '; ', $st['falta'] ) ); ?>
              </div>
            <?php endif; ?>
          </td>
          <td>
            <?php echo (int) $inst->dilema_count; ?> / <?php echo (int) $inst->density; ?>
            <div style="font-size:11px;color:#999"><?php echo (int) $inst->density; ?> planejados</div>
          </td>
          <td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $inst->created_at ) ) ); ?></td>
          <td>
            <a href="<?php echo esc_url( $edit_url ); ?>">Editar</a>
            &nbsp;|&nbsp;
            <a href="<?php echo esc_url( $delete_url ); ?>"
               style="color:#c00"
               class="ng-confirm"
               data-ng-confirm="<?php echo esc_attr(
                   'Excluir «' . $inst->title . '» e todos os seus dilemas?' . "\n\n"
                   . 'Esta ação não pode ser desfeita.'
               ); ?>">
              Excluir
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
