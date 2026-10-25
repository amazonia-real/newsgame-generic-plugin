<?php
/**
 * Exclusão do plugin pelo painel (Plugins → Excluir).
 *
 * APAGA: as options `newsgame_*` (chaves de provedores, token, URL, cursores,
 * versão do schema, aviso de erro de schema etc.) e os transients `newsgame_*`.
 * Em multisite, repete por site.
 *
 * NÃO APAGA: as tabelas `{prefixo}newsgame_instances` e `{prefixo}newsgame_dilemas`
 * nem a pasta `uploads/newsgame-vault` — são conteúdo do editor (instâncias,
 * perguntas revisadas, acervo sincronizado). Como apagar tudo à mão: ver
 * README_PORTABILIDADE.md, seção "Desinstalar o plugin".
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Apaga as options e os transients do plugin no site atual.
 * O "_" do LIKE é curinga: sem esc_like(), `newsgame_%` apanharia também
 * `newsgamer_x` de outro plugin. Por isso o prefixo passa por esc_like().
 */
function newsgame_uninstall_limpar_site() {
    global $wpdb;

    $padroes = array(
        $wpdb->esc_like( 'newsgame_' ) . '%',
        $wpdb->esc_like( '_transient_newsgame_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_newsgame_' ) . '%',
    );

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
          WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $padroes
    ) );
}

if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
        switch_to_blog( $site_id );
        newsgame_uninstall_limpar_site();
        restore_current_blog();
    }
} else {
    newsgame_uninstall_limpar_site();
}

wp_cache_flush();
