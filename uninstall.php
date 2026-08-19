<?php
/**
 * Uninstall cleanup: drop the plugin tables and delete its options/transients.
 * Runs with the plugin unloaded, so nothing here relies on plugin constants.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

function sita_xml_importer_uninstall_site() {
    global $wpdb;

    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'sita_xml_importer_article' );
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'sita_xml_importer_log' );

    delete_option( 'sita_xml_importer' );
    delete_option( 'sita_xml_importer_db_version' );
    delete_option( 'sita_xml_importer_version' );
    delete_option( 'sita_xml_importer_migration_completed_at' );

    delete_transient( 'sita_xml_importer_lock' );
    delete_transient( 'sita_xml_importer_passes' );
    delete_transient( 'sita_xml_importer_manual_cooldown' );

    wp_clear_scheduled_hook( 'sita_xml_importer_cron' );
    wp_clear_scheduled_hook( 'sita_xml_importer_continue' );
    wp_clear_scheduled_hook( 'sita_xml_importer_migrate_cron' );
    wp_clear_scheduled_hook( 'sita_xml_importer_cleanup_cron' );
}

if ( is_multisite() ) {
    $site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
    foreach ( $site_ids as $site_id ) {
        switch_to_blog( $site_id );
        sita_xml_importer_uninstall_site();
        restore_current_blog();
    }
} else {
    sita_xml_importer_uninstall_site();
}
