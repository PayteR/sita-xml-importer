<?php
/**
 * Legacy data migration from the previous plugin generation.
 *
 * Transitional: settings migration, deactivating the old plugin, the postmeta ->
 * article-table backfill, the dual-read fallback that keeps dedup working during
 * it, and the migration admin panel. Self-contained: the core only reaches it
 * through filters and actions, so it can be removed once every installation has
 * migrated and cleaned up.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Activation / upgrade hooks (fired by the core; no-ops if this file is gone)
 * ---------------------------------------------------------------------- */

add_action( 'sita_xml_importer_activated', 'sita_xml_importer_legacy_on_activate' );
function sita_xml_importer_legacy_on_activate() {
    sita_xml_importer_migrate_settings();
    sita_xml_importer_deactivate_legacy_plugin();
    sita_xml_importer_maybe_start_meta_migration();
}

add_action( 'sita_xml_importer_upgraded', 'sita_xml_importer_maybe_start_meta_migration' );

add_action( 'sita_xml_importer_deactivated', 'sita_xml_importer_legacy_on_deactivate' );
function sita_xml_importer_legacy_on_deactivate() {
    wp_clear_scheduled_hook( 'sita_xml_importer_migrate_cron' );
    wp_clear_scheduled_hook( 'sita_xml_importer_cleanup_cron' );
}

/**
 * Migrate settings from the old sita-parser-xml plugin option.
 */
function sita_xml_importer_migrate_settings() {
    if ( get_option( SITA_XML_IMPORTER_OPTION ) ) {
        return;
    }

    $old_options = get_option( 'sita_parser' );
    if ( $old_options ) {
        update_option( SITA_XML_IMPORTER_OPTION, $old_options );
    }
}

/**
 * Deactivate the legacy sita-parser-xml plugin and clear its cron event so an
 * install that still had the old plugin active does not run two importers.
 */
function sita_xml_importer_deactivate_legacy_plugin() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $legacy = 'sita-parser-xml/index.php';
    if ( is_plugin_active( $legacy ) ) {
        deactivate_plugins( $legacy );
    }

    wp_clear_scheduled_hook( 'sita_parser_hourly' );
}

/* -------------------------------------------------------------------------
 * Dual-read fallback (the seam that keeps dedup working mid-migration)
 * ---------------------------------------------------------------------- */

add_filter( 'sita_xml_importer_resolve_post_id', 'sita_xml_importer_legacy_resolve_post_id', 10, 2 );
/**
 * When the article table has no row for a SITA id, read the old `_w_id`
 * postmeta and lazily backfill the row so the fallback path drains itself.
 */
function sita_xml_importer_legacy_resolve_post_id( $post_id, $sita_id ) {
    if ( $post_id ) {
        return $post_id;
    }

    $found = sita_xml_importer_legacy_postmeta_lookup( $sita_id, '_w_id' );
    if ( $found ) {
        sita_xml_importer_article_upsert(
            $sita_id,
            [
                'post_id'        => $found,
                'time_published' => (string) get_post_meta( $found, '_w_t_p', true ),
                'time_modified'  => (string) get_post_meta( $found, '_w_t_m', true ),
            ]
        );
    }

    return $found;
}

add_filter( 'sita_xml_importer_resolve_attachment_id', 'sita_xml_importer_legacy_resolve_attachment_id', 10, 2 );
function sita_xml_importer_legacy_resolve_attachment_id( $attachment_id, $image_url ) {
    if ( $attachment_id ) {
        return $attachment_id;
    }
    return sita_xml_importer_legacy_postmeta_lookup( $image_url, '_w_turl' );
}

/**
 * Exact-value postmeta lookup (used only as the migration fallback).
 */
function sita_xml_importer_legacy_postmeta_lookup( $value, $key ) {
    global $wpdb;

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            $key,
            (string) $value
        )
    );
}

/* -------------------------------------------------------------------------
 * Chunked background migration + cleanup
 * ---------------------------------------------------------------------- */

// Also runs after every import (sita_xml_importer_end) as a resilient restart
// net: if the migration's self-reschedule chain ever broke (e.g. a batch that
// died before queuing the next), the next import re-arms it. No-op when nothing
// is pending or a batch is already queued.
add_action( 'sita_xml_importer_end', 'sita_xml_importer_maybe_start_meta_migration' );
/**
 * Schedule the background meta migration if there is anything to migrate and it
 * is not already queued.
 */
function sita_xml_importer_maybe_start_meta_migration() {
    if ( wp_next_scheduled( 'sita_xml_importer_migrate_cron' ) ) {
        return;
    }
    if ( sita_xml_importer_migration_pending_count() > 0 ) {
        wp_schedule_single_event( time(), 'sita_xml_importer_migrate_cron' );
        spawn_cron();
    }
}

/**
 * True while a migration batch is in flight. Timestamp-based so a batch that
 * dies mid-run (e.g. a fatal/timeout) self-clears after the TTL instead of
 * wedging the migration.
 */
function sita_xml_importer_migration_is_running() {
    $since = (int) get_transient( 'sita_xml_importer_migrate_lock' );
    return $since > 0 && ( time() - $since ) < 5 * MINUTE_IN_SECONDS;
}

add_action( 'sita_xml_importer_migrate_cron', 'sita_xml_importer_run_meta_migration' );
function sita_xml_importer_run_meta_migration() {
    // Overlap guard: on a large site a batch can outlast WP's 60s cron lock,
    // which would let another cron spawn a concurrent batch and thrash the DB.
    // Bail if one is already running; the restart net (sita_xml_importer_end) or
    // the next scheduled batch picks it up again.
    if ( sita_xml_importer_migration_is_running() ) {
        return;
    }
    set_transient( 'sita_xml_importer_migrate_lock', time(), 5 * MINUTE_IN_SECONDS );

    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
    }

    $remaining = 0;
    try {
        $batch     = (int) apply_filters( 'sita_xml_importer_migration_batch_size', 2000 );
        $state     = sita_xml_importer_migrate_batch( $batch );
        $remaining = (int) $state['remaining'];
    } finally {
        delete_transient( 'sita_xml_importer_migrate_lock' );
    }

    if ( $remaining > 0 ) {
        // Space the next batch out instead of re-queuing instantly, so the
        // recurring import cron (and anything else) gets its turn on a big
        // backfill rather than being crowded out of WP-Cron. Filterable.
        $delay = (int) apply_filters( 'sita_xml_importer_migration_batch_delay', MINUTE_IN_SECONDS );
        wp_schedule_single_event( time() + max( 5, $delay ), 'sita_xml_importer_migrate_cron' );
        spawn_cron();
    } else {
        sita_xml_importer_log( 'Meta migration complete - all legacy _w_id posts are indexed in the article table.' );
    }
}

/**
 * How many posts still carry a legacy `_w_id` meta with no matching article row.
 */
function sita_xml_importer_migration_pending_count() {
    global $wpdb;
    $table   = sita_xml_importer_article_table();
    $coll    = sita_xml_importer_meta_collation();
    $collate = $coll !== '' ? " COLLATE {$coll}" : '';

    return (int) $wpdb->get_var(
        "SELECT COUNT(*)
           FROM {$wpdb->postmeta} pm
      LEFT JOIN {$table} a ON a.sita_id = pm.meta_value{$collate}
          WHERE pm.meta_key = '_w_id' AND a.id IS NULL"
    );
}

/**
 * Migrate a batch of legacy posts into the article table. Idempotent: only
 * touches posts whose `_w_id` has no article row yet. Never deletes postmeta.
 *
 * @return array{migrated:int,remaining:int}
 */
function sita_xml_importer_migrate_batch( $limit = 2000 ) {
    global $wpdb;
    $table   = sita_xml_importer_article_table();
    $limit   = max( 1, (int) $limit );
    $coll    = sita_xml_importer_meta_collation();
    $collate = $coll !== '' ? " COLLATE {$coll}" : '';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value AS sita_id
               FROM {$wpdb->postmeta} pm
          LEFT JOIN {$table} a ON a.sita_id = pm.meta_value{$collate}
              WHERE pm.meta_key = '_w_id' AND a.id IS NULL
              LIMIT %d",
            $limit
        )
    );

    $migrated = 0;
    foreach ( (array) $rows as $row ) {
        $post_id  = (int) $row->post_id;
        $sita_id  = (string) $row->sita_id;
        $thumb_id = (int) get_post_thumbnail_id( $post_id );
        $img_url  = $thumb_id ? (string) get_post_meta( $thumb_id, '_w_turl', true ) : '';

        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                    (sita_id, post_id, time_published, time_modified, image_url, attachment_id, first_imported_at, last_imported_at)
                 VALUES (%s, %d, %s, %s, %s, %d, %s, %s)",
                $sita_id,
                $post_id,
                (string) get_post_meta( $post_id, '_w_t_p', true ),
                (string) get_post_meta( $post_id, '_w_t_m', true ),
                $img_url !== '' ? $img_url : null,
                $thumb_id,
                get_post_field( 'post_date', $post_id ) ?: current_time( 'mysql' ),
                current_time( 'mysql' )
            )
        );
        $migrated++;
    }

    return [
        'migrated'  => $migrated,
        'remaining' => sita_xml_importer_migration_pending_count(),
    ];
}

/**
 * Delete legacy `_w_*` postmeta for posts already present in the article table.
 * Opt-in, batched, irreversible - this is what finally declutters wp_postmeta.
 *
 * @return int Rows deleted.
 */
function sita_xml_importer_cleanup_legacy_meta( $limit = 5000 ) {
    global $wpdb;
    $table   = sita_xml_importer_article_table();
    $limit   = max( 1, (int) $limit );
    $coll    = sita_xml_importer_meta_collation();
    $collate = $coll !== '' ? " COLLATE {$coll}" : '';

    // _w_id: only for posts that are indexed in the article table.
    // A multi-table DELETE ... JOIN cannot take LIMIT (MySQL/MariaDB syntax error),
    // so batch by selecting a page of meta_ids first, then delete those by primary key.
    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT pm.meta_id FROM {$wpdb->postmeta} pm
               INNER JOIN {$table} a ON a.sita_id = pm.meta_value{$collate}
             WHERE pm.meta_key = '_w_id'
             LIMIT %d",
            $limit
        )
    );
    $deleted = sita_xml_importer_delete_meta_ids( $ids );

    // _w_t_p / _w_t_m: keyed on the post; delete for indexed posts.
    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT pm.meta_id FROM {$wpdb->postmeta} pm
               INNER JOIN {$table} a ON a.post_id = pm.post_id
             WHERE pm.meta_key IN ('_w_t_p', '_w_t_m')
             LIMIT %d",
            $limit
        )
    );
    $deleted += sita_xml_importer_delete_meta_ids( $ids );

    // _w_turl: the old attachment dedup marker, replaced by the article table's
    // image_url/attachment_id. Must be deleted too, otherwise legacy_meta_count()
    // (which counts it) never reaches 0 and the auto-cleanup cron never stops.
    // Cleanup only runs once migration is complete, so these are all redundant.
    $deleted += (int) $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_w_turl' LIMIT %d",
            $limit
        )
    );

    return $deleted;
}

/**
 * Delete a batch of postmeta rows by primary key. Used by the cleanup because a
 * multi-table DELETE ... JOIN cannot be LIMIT-ed, so the ids are paged via SELECT
 * and removed here with a single-table DELETE. Ids are cast to int (injection-safe).
 *
 * @param int[] $ids
 * @return int Rows deleted.
 */
function sita_xml_importer_delete_meta_ids( $ids ) {
    global $wpdb;
    $ids = array_filter( array_map( 'intval', (array) $ids ) );
    if ( ! $ids ) {
        return 0;
    }
    return (int) $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN (" . implode( ',', $ids ) . ')' );
}

function sita_xml_importer_legacy_meta_count() {
    global $wpdb;
    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_w_id','_w_t_p','_w_t_m','_w_turl')"
    );
}

/* -------------------------------------------------------------------------
 * Automatic cleanup after a grace period
 * ---------------------------------------------------------------------- */

/**
 * Once the migration is complete, keep the legacy `_w_*` postmeta as a backup
 * for a grace period (default 30 days), then delete it automatically. Runs off
 * the hourly import (the `sita_xml_importer_end` action) so it needs no extra
 * schedule. Filter `sita_xml_importer_cleanup_grace_days` to change the window;
 * return 0 or less to disable auto-cleanup (manual only).
 */
add_action( 'sita_xml_importer_end', 'sita_xml_importer_maybe_auto_cleanup' );
function sita_xml_importer_maybe_auto_cleanup() {
    // Cheap gate: no legacy meta means nothing to do (fresh installs, or done).
    if ( sita_xml_importer_legacy_meta_count() < 1 ) {
        return;
    }

    $completed = (int) get_option( 'sita_xml_importer_migration_completed_at' );
    if ( ! $completed ) {
        // Legacy meta exists but no completion stamp yet: start the grace clock
        // only once the backfill has fully drained.
        if ( sita_xml_importer_migration_pending_count() > 0 ) {
            return;
        }
        update_option( 'sita_xml_importer_migration_completed_at', time() );
        return;
    }

    $grace_days = (int) apply_filters( 'sita_xml_importer_cleanup_grace_days', 30 );
    if ( $grace_days <= 0 ) {
        return; // auto-cleanup disabled
    }
    if ( time() < $completed + $grace_days * DAY_IN_SECONDS ) {
        return; // still within the backup window
    }
    if ( wp_next_scheduled( 'sita_xml_importer_cleanup_cron' ) ) {
        return;
    }

    wp_schedule_single_event( time(), 'sita_xml_importer_cleanup_cron' );
    spawn_cron();
}

add_action( 'sita_xml_importer_cleanup_cron', 'sita_xml_importer_run_auto_cleanup' );
function sita_xml_importer_run_auto_cleanup() {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
    }

    // Safety: never delete meta that is not yet indexed in the article table.
    if ( sita_xml_importer_migration_pending_count() > 0 ) {
        return;
    }

    $batch = (int) apply_filters( 'sita_xml_importer_cleanup_batch_size', 5000 );
    sita_xml_importer_cleanup_legacy_meta( $batch );

    if ( sita_xml_importer_legacy_meta_count() > 0 ) {
        // Spaced like the migration so the delete backlog does not crowd imports
        // out of WP-Cron on a big site.
        $delay = (int) apply_filters( 'sita_xml_importer_cleanup_batch_delay', MINUTE_IN_SECONDS );
        wp_schedule_single_event( time() + max( 5, $delay ), 'sita_xml_importer_cleanup_cron' );
        spawn_cron();
    }
}

/* -------------------------------------------------------------------------
 * Migration admin panel + handlers (rendered on the Maintenance tab)
 * ---------------------------------------------------------------------- */

add_filter( 'sita_xml_importer_admin_notices', 'sita_xml_importer_legacy_admin_notices' );
function sita_xml_importer_legacy_admin_notices( $messages ) {
    $messages['migrating'] = [ 'updated', __( 'Data migration started in the background.', 'sita-xml-importer' ) ];
    $messages['cleaned']   = [ 'updated', __( 'Legacy meta cleanup ran. Repeat until nothing remains.', 'sita-xml-importer' ) ];
    return $messages;
}

add_action( 'sita_xml_importer_maintenance_tab', 'sita_xml_importer_render_legacy_maintenance' );
/**
 * Legacy panels on the (core) Maintenance tab. Two independent concerns, each
 * shown only when relevant: a leftover old plugin (shown first) and the legacy
 * data migration/cleanup. When neither applies this adds nothing - the core
 * featured-image repair panel is always shown above it.
 */
function sita_xml_importer_render_legacy_maintenance() {
    $old_plugin = file_exists( WP_PLUGIN_DIR . '/sita-parser-xml/index.php' );
    $pending    = sita_xml_importer_migration_pending_count();
    $legacy     = sita_xml_importer_legacy_meta_count();

    if ( $old_plugin ) {
        sita_xml_importer_render_old_plugin_notice();
    }

    if ( $pending > 0 || $legacy > 0 ) {
        sita_xml_importer_render_migration_panel( $pending, $legacy );
    }
}

function sita_xml_importer_render_migration_panel( $pending = null, $legacy = null ) {
    if ( $pending === null ) {
        $pending = sita_xml_importer_migration_pending_count();
    }
    if ( $legacy === null ) {
        $legacy = sita_xml_importer_legacy_meta_count();
    }

    echo '<h2>' . esc_html__( 'Legacy data migration', 'sita-xml-importer' ) . '</h2>';
    echo '<p class="description">' . esc_html__( 'Moves the old per-post tracking meta (_w_id, _w_t_p, _w_t_m) into the indexed article table. New imports already use the table; this backfills existing posts.', 'sita-xml-importer' ) . '</p>';

    echo '<p>';
    printf(
        /* translators: 1: posts pending migration, 2: legacy meta rows */
        esc_html__( 'Posts not yet migrated: %1$s. Legacy meta rows remaining: %2$s.', 'sita-xml-importer' ),
        '<strong>' . esc_html( number_format_i18n( $pending ) ) . '</strong>',
        '<strong>' . esc_html( number_format_i18n( $legacy ) ) . '</strong>'
    );
    echo '</p>';

    // When the backup will be removed automatically.
    if ( $pending === 0 && $legacy > 0 ) {
        $grace_days = (int) apply_filters( 'sita_xml_importer_cleanup_grace_days', 30 );
        if ( $grace_days > 0 ) {
            $completed = (int) get_option( 'sita_xml_importer_migration_completed_at' );
            $when      = ( $completed ?: time() ) + $grace_days * DAY_IN_SECONDS;
            printf(
                '<p class="description">' . esc_html__( 'The remaining legacy meta is kept as a backup and is deleted automatically around %s. You can also remove it now.', 'sita-xml-importer' ) . '</p>',
                esc_html( date_i18n( get_option( 'date_format' ), $when ) )
            );
        } else {
            echo '<p class="description">' . esc_html__( 'Automatic cleanup is disabled; remove the legacy meta manually when ready.', 'sita-xml-importer' ) . '</p>';
        }
    }

    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:10px;">';
    wp_nonce_field( 'sita_xml_importer_migrate' );
    echo '<input type="hidden" name="action" value="sita_xml_importer_migrate" />';
    submit_button( __( 'Run data migration now', 'sita-xml-importer' ), 'secondary', 'submit', false, $pending > 0 ? [] : [ 'disabled' => 'disabled' ] );
    echo '</form>';

    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;" onsubmit="return confirm(' . "'" . esc_js( __( 'Delete legacy _w_* postmeta for migrated posts? This cannot be undone.', 'sita-xml-importer' ) ) . "'" . ');">';
    wp_nonce_field( 'sita_xml_importer_cleanup' );
    echo '<input type="hidden" name="action" value="sita_xml_importer_cleanup" />';
    $cleanup_disabled = ( $pending === 0 && $legacy > 0 ) ? [] : [ 'disabled' => 'disabled' ];
    submit_button( __( 'Delete legacy meta (cleanup)', 'sita-xml-importer' ), 'delete', 'submit', false, $cleanup_disabled );
    echo '</form>';
}

/**
 * If the old sita-parser-xml plugin is still present on disk, tell the user it
 * can be removed. Deliberately no delete button: removing a plugin needs
 * filesystem credentials and is exactly what the Plugins screen does safely.
 */
function sita_xml_importer_render_old_plugin_notice() {
    if ( ! file_exists( WP_PLUGIN_DIR . '/sita-parser-xml/index.php' ) ) {
        return;
    }

    echo '<div class="notice notice-warning inline"><p>';
    echo esc_html__( 'The old "SITA XML parser" plugin (sita-parser-xml) is still installed. It has been deactivated and is no longer needed. You can safely delete it from the Plugins screen.', 'sita-xml-importer' );
    echo ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Go to Plugins', 'sita-xml-importer' ) . '</a>';
    echo '</p></div>';
}

add_action( 'admin_post_sita_xml_importer_migrate', 'sita_xml_importer_handle_migrate' );
function sita_xml_importer_handle_migrate() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'sita-xml-importer' ) );
    }
    check_admin_referer( 'sita_xml_importer_migrate' );

    wp_schedule_single_event( time(), 'sita_xml_importer_migrate_cron' );
    spawn_cron();

    wp_safe_redirect( add_query_arg( 'sxi', 'migrating', admin_url( 'options-general.php?page=sita-xml-importer&tab=maintenance' ) ) );
    exit;
}

add_action( 'admin_post_sita_xml_importer_cleanup', 'sita_xml_importer_handle_cleanup' );
function sita_xml_importer_handle_cleanup() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'sita-xml-importer' ) );
    }
    check_admin_referer( 'sita_xml_importer_cleanup' );

    // Only clean up once the backfill has fully drained, so we never delete a
    // _w_id that has not yet been indexed in the article table.
    if ( sita_xml_importer_migration_pending_count() === 0 ) {
        sita_xml_importer_cleanup_legacy_meta( (int) apply_filters( 'sita_xml_importer_cleanup_batch_size', 5000 ) );
    }

    wp_safe_redirect( add_query_arg( 'sxi', 'cleaned', admin_url( 'options-general.php?page=sita-xml-importer&tab=maintenance' ) ) );
    exit;
}
