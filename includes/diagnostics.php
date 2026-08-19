<?php
/**
 * Diagnostic report (Markdown): environment and schema state first, then health
 * checks and errors, then the recent runs as a CSV code block.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Health checks: quick PASS/WARN/FAIL assertions a reader (human or agent) can
 * scan first. Extensible via the filter.
 *
 * @return array<int,array{status:string,label:string}>
 */
function sita_xml_importer_health_checks() {
    global $wpdb;
    $checks  = [];
    $article = sita_xml_importer_article_table();
    $log     = sita_xml_importer_log_table();

    $tables_ok = sita_xml_importer_table_exists( $article ) && sita_xml_importer_table_exists( $log );
    $checks[]  = [ 'status' => $tables_ok ? 'PASS' : 'FAIL', 'label' => 'Plugin tables exist' ];

    // Collation of the join columns vs wp_postmeta.meta_value.
    $meta_coll = sita_xml_importer_meta_collation();
    $sita_coll = preg_replace(
        '/[^A-Za-z0-9_]/',
        '',
        (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COLLATION_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'sita_id' LIMIT 1",
                $article
            )
        )
    );
    if ( $meta_coll !== '' && $sita_coll !== '' ) {
        $match    = $meta_coll === $sita_coll;
        $checks[] = [
            'status' => $match ? 'PASS' : 'FAIL',
            'label'  => 'JOIN collation match (article.sita_id=' . $sita_coll . ', wp_postmeta.meta_value=' . $meta_coll . ')',
        ];
    }

    $next     = wp_next_scheduled( 'sita_xml_importer_cron' );
    $interval = sita_xml_importer_get_option( 'import_interval', SITA_XML_IMPORTER_OPTION, '30min' );
    $checks[] = [
        'status' => $next ? 'PASS' : 'FAIL',
        'label'  => $next ? ( 'Import cron scheduled every ' . $interval . ' (next ' . gmdate( 'Y-m-d H:i', $next ) . ' UTC)' ) : 'Import cron is NOT scheduled',
    ];

    $wpcron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    $checks[]   = [
        'status' => $wpcron_off ? 'WARN' : 'PASS',
        'label'  => $wpcron_off ? 'WP-Cron disabled (imports rely on a system cron)' : 'WP-Cron enabled',
    ];

    $feeds    = sita_xml_importer_get_xml_feeds();
    $checks[] = [ 'status' => $feeds ? 'PASS' : 'WARN', 'label' => count( $feeds ) . ' feed(s) configured' ];

    $limit    = (int) ini_get( 'max_execution_time' );
    $checks[] = [
        'status' => ( $limit > 0 && $limit < 30 ) ? 'WARN' : 'PASS',
        'label'  => 'max_execution_time = ' . ( $limit > 0 ? $limit . 's' : 'unlimited' ),
    ];

    $last = sita_xml_importer_log_last_finished();
    if ( $last ) {
        $map      = [ 'failed' => 'FAIL', 'partial' => 'WARN', 'success' => 'PASS' ];
        $checks[] = [
            'status' => $map[ $last->status ] ?? 'WARN',
            'label'  => 'Last run: ' . $last->status . ' at ' . $last->started_at,
        ];
    }

    if ( function_exists( 'sita_xml_importer_migration_pending_count' ) ) {
        $pending  = sita_xml_importer_migration_pending_count();
        $checks[] = [
            'status' => $pending > 0 ? 'WARN' : 'PASS',
            'label'  => $pending > 0 ? ( 'Legacy migration in progress (' . $pending . ' posts pending)' ) : 'Legacy migration complete (or not needed)',
        ];
    }

    return apply_filters( 'sita_xml_importer_health_checks', $checks );
}

/**
 * Environment key/value pairs.
 *
 * @return array<string,string>
 */
function sita_xml_importer_environment_info() {
    global $wpdb;
    $theme = wp_get_theme();

    return [
        'Plugin version'     => SITA_XML_IMPORTER_VERSION,
        'DB schema version'  => (string) get_option( SITA_XML_IMPORTER_DB_VERSION_OPT, '(unset)' ),
        'WordPress'          => get_bloginfo( 'version' ),
        'PHP'                => PHP_VERSION,
        'Database server'    => (string) $wpdb->get_var( 'SELECT VERSION()' ),
        'Multisite'          => is_multisite() ? 'yes' : 'no',
        'Active theme'       => trim( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ),
        'max_execution_time' => (string) ini_get( 'max_execution_time' ),
        'memory_limit'       => (string) ini_get( 'memory_limit' ),
        'WP_DEBUG'           => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'true' : 'false',
        'DISABLE_WP_CRON'    => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'true' : 'false',
        'Import interval'    => (string) sita_xml_importer_get_option( 'import_interval', SITA_XML_IMPORTER_OPTION, '30min' ),
        'Deleted (audit)'    => (string) sita_xml_importer_article_deleted_count(),
        'Site URL'           => home_url(),
    ];
}

function sita_xml_importer_md_cell( $value ) {
    $value = str_replace( [ "\r\n", "\n", "\r" ], ' ', (string) $value );
    // Neutralise Markdown control chars so feed-supplied titles/messages can't
    // break table cells or code fences when the report is rendered by an agent.
    $value = str_replace( '`', "'", $value );
    return str_replace( '|', '\|', $value );
}

/**
 * Build the full Markdown diagnostic report.
 *
 * @return string
 */
function sita_xml_importer_diagnostic_report() {
    $out   = [];
    $out[] = '# SITA XML Importer - diagnostic report';
    $out[] = '';
    $out[] = 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
    $out[] = '';

    $out[] = '## Health checks';
    foreach ( sita_xml_importer_health_checks() as $check ) {
        if ( $check['label'] === '' ) {
            continue;
        }
        $out[] = '- ' . $check['status'] . ': ' . $check['label'];
    }
    $out[] = '';

    $out[] = '## Environment';
    $out[] = '| Key | Value |';
    $out[] = '| --- | --- |';
    foreach ( sita_xml_importer_environment_info() as $key => $value ) {
        $out[] = '| ' . sita_xml_importer_md_cell( $key ) . ' | ' . sita_xml_importer_md_cell( $value ) . ' |';
    }
    $out[] = '';

    // Settings (feed URLs redacted).
    $options = get_option( SITA_XML_IMPORTER_OPTION, [] );
    $out[]   = '## Settings';
    $out[]   = '- Feeds: ' . ( sita_xml_importer_get_xml_feeds() ? implode( ', ', array_map( 'sita_xml_importer_redact_url', sita_xml_importer_get_xml_feeds() ) ) : '(none)' );
    $out[]   = '- Post type: ' . sita_xml_importer_md_cell( $options['post_type'] ?? 'post' );
    $out[]   = '- Post status: ' . sita_xml_importer_md_cell( $options['xml_status'] ?? 'pending' );
    $out[]   = '- Post author (user ID): ' . sita_xml_importer_md_cell( $options['xml_user'] ?? '(unset)' );
    $out[]   = '- Category saving: ' . ( empty( $options['xml_categorysaving'] ) ? 'lowest only' : 'whole tree' );
    $out[]   = '- Verbose logging: ' . ( empty( $options['verbose_logging'] ) ? 'off' : 'on' );
    $out[]   = '- Log retention (days): ' . sita_xml_importer_md_cell( $options['log_retention_days'] ?? 180 );
    $out[]   = '';

    // Migration state (only when the legacy module is present).
    if ( function_exists( 'sita_xml_importer_migration_pending_count' ) ) {
        $out[] = '## Legacy migration';
        $out[] = '- Posts pending migration: ' . (int) sita_xml_importer_migration_pending_count();
        if ( function_exists( 'sita_xml_importer_legacy_meta_count' ) ) {
            $out[] = '- Legacy meta rows remaining: ' . (int) sita_xml_importer_legacy_meta_count();
        }
        $completed = (int) get_option( 'sita_xml_importer_migration_completed_at' );
        $out[]     = '- Migration completed at: ' . ( $completed ? gmdate( 'Y-m-d H:i:s', $completed ) . ' UTC' : '(not recorded)' );
        $out[]     = '';
    }

    // Errors from recent runs (the actionable part).
    $runs   = sita_xml_importer_log_recent( 25 );
    $errors = [];
    foreach ( (array) $runs as $run ) {
        $details = json_decode( (string) $run->details, true );
        foreach ( (array) ( is_array( $details ) ? ( $details['errors'] ?? [] ) : [] ) as $err ) {
            $errors[] = '- [run ' . (int) $run->id . '] ' . sita_xml_importer_md_cell( ( ! empty( $err['title'] ) ? $err['title'] . ' - ' : '' ) . ( $err['message'] ?? '' ) );
        }
    }
    $out[] = '## Errors (recent runs)';
    $out[] = $errors ? implode( "\n", array_slice( $errors, 0, 100 ) ) : '- (none)';
    $out[] = '';

    // Recent runs as a CSV code block (compact, machine-parseable appendix).
    $out[] = '## Recent runs (CSV)';
    $out[] = '```csv';
    $out[] = 'run_id,started_at,finished_at,run_type,status,created,updated,skipped,errors';
    foreach ( (array) $runs as $run ) {
        $out[] = implode( ',', [
            (int) $run->id,
            $run->started_at,
            $run->finished_at,
            $run->run_type,
            $run->status,
            (int) $run->created_count,
            (int) $run->updated_count,
            (int) $run->skipped_count,
            (int) $run->error_count,
        ] );
    }
    $out[] = '```';
    $out[] = '';

    return apply_filters( 'sita_xml_importer_diagnostic_report', implode( "\n", $out ) . "\n" );
}

add_action( 'admin_post_sita_xml_importer_diagnostics', 'sita_xml_importer_handle_diagnostics' );
function sita_xml_importer_handle_diagnostics() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'sita-xml-importer' ) );
    }
    check_admin_referer( 'sita_xml_importer_diagnostics' );

    $filename = 'sita-xml-importer-diagnostic-' . gmdate( 'Ymd-His' ) . '.md';

    nocache_headers();
    header( 'Content-Type: text/markdown; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

    echo sita_xml_importer_diagnostic_report(); // phpcs:ignore WordPress.Security.EscapeOutput

    exit;
}
