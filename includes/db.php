<?php
/**
 * Custom tables for SITA XML Importer.
 *
 *   - {prefix}sita_xml_importer_article - one row per imported article: the dedup
 *     index (UNIQUE sita_id) and the post/attachment mapping.
 *   - {prefix}sita_xml_importer_log     - one row per import run (see logger.php).
 *
 * Schema work uses CREATE TABLE IF NOT EXISTS plus explicit, version-gated ALTERs
 * verified through information_schema, rather than dbDelta().
 */

defined( 'ABSPATH' ) || exit;

function sita_xml_importer_article_table() {
    global $wpdb;
    return $wpdb->prefix . 'sita_xml_importer_article';
}

function sita_xml_importer_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'sita_xml_importer_log';
}

/**
 * The `CHARACTER SET ... COLLATE ...` clause of wp_postmeta.meta_value, copied so
 * the columns compared against it during the legacy migration share its collation.
 *
 * @return string e.g. " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", or ''.
 */
function sita_xml_importer_meta_column_def() {
    global $wpdb;
    static $def = null;
    if ( $def !== null ) {
        return $def;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT CHARACTER_SET_NAME AS cs, COLLATION_NAME AS co
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'meta_value'
              LIMIT 1",
            $wpdb->postmeta
        )
    );

    $def = '';
    if ( $row && ! empty( $row->cs ) && ! empty( $row->co ) ) {
        $cs = preg_replace( '/[^A-Za-z0-9_]/', '', $row->cs );
        $co = preg_replace( '/[^A-Za-z0-9_]/', '', $row->co );
        if ( $cs !== '' && $co !== '' ) {
            $def = " CHARACTER SET {$cs} COLLATE {$co}";
        }
    }

    return $def;
}

/**
 * The collation name of wp_postmeta.meta_value, used to force the collation in the
 * migration JOINs. Sanitised to an identifier so it is safe to interpolate.
 *
 * @return string Collation name, or '' when undetectable.
 */
function sita_xml_importer_meta_collation() {
    global $wpdb;
    static $coll = null;
    if ( $coll !== null ) {
        return $coll;
    }

    $raw  = (string) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COLLATION_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'meta_value' LIMIT 1",
            $wpdb->postmeta
        )
    );
    $coll = preg_replace( '/[^A-Za-z0-9_]/', '', $raw );

    return $coll;
}

/**
 * Create both tables and run any pending schema migrations.
 *
 * @return bool True when both tables exist and migrations succeeded.
 */
function sita_xml_importer_create_tables() {
    global $wpdb;

    $charset  = $wpdb->get_charset_collate();
    $article  = sita_xml_importer_article_table();
    $log      = sita_xml_importer_log_table();
    $col      = sita_xml_importer_meta_column_def();

    $article_sql = "CREATE TABLE IF NOT EXISTS {$article} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sita_id VARCHAR(64){$col} NOT NULL,
        post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        time_published VARCHAR(32) NOT NULL DEFAULT '',
        time_modified VARCHAR(32) NOT NULL DEFAULT '',
        image_url TEXT{$col} NULL,
        attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        first_imported_at DATETIME NULL DEFAULT NULL,
        last_imported_at DATETIME NULL DEFAULT NULL,
        deleted_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY sita_id (sita_id),
        KEY post_id (post_id),
        KEY attachment_id (attachment_id),
        KEY image_url (image_url(191)),
        KEY deleted_at (deleted_at)
    ) {$charset};";

    $log_sql = "CREATE TABLE IF NOT EXISTS {$log} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL DEFAULT NULL,
        run_type VARCHAR(16) NOT NULL DEFAULT 'cron',
        status VARCHAR(16) NOT NULL DEFAULT 'running',
        feeds_total INT UNSIGNED NOT NULL DEFAULT 0,
        created_count INT UNSIGNED NOT NULL DEFAULT 0,
        updated_count INT UNSIGNED NOT NULL DEFAULT 0,
        skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
        error_count INT UNSIGNED NOT NULL DEFAULT 0,
        details LONGTEXT NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY started_at (started_at),
        KEY status (status)
    ) {$charset};";

    $previous = $wpdb->suppress_errors( true );
    $wpdb->query( $article_sql );
    $wpdb->query( $log_sql );
    $wpdb->suppress_errors( $previous );

    if ( ! sita_xml_importer_table_exists( $article ) || ! sita_xml_importer_table_exists( $log ) ) {
        sita_xml_importer_log( 'Schema: CREATE TABLE failed - a plugin table is still missing.' );
        return false;
    }

    return sita_xml_importer_migrate_schema();
}

/**
 * Version-gated schema migrator, run after CREATE TABLE. The current schema needs
 * no migration; this records the version and is the seam for future changes.
 *
 * @return bool
 */
function sita_xml_importer_migrate_schema() {
    $current = (string) get_option( SITA_XML_IMPORTER_DB_VERSION_OPT, '0' );

    if ( $current !== SITA_XML_IMPORTER_DB_VERSION ) {
        update_option( SITA_XML_IMPORTER_DB_VERSION_OPT, SITA_XML_IMPORTER_DB_VERSION );
    }

    return true;
}

function sita_xml_importer_table_exists( $table ) {
    global $wpdb;

    $found = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table
        )
    );

    return $found === $table;
}

/**
 * Ensure the tables exist. Cheap guard called from the runtime paths so a
 * missing table (e.g. install that never fired the activation hook) self-heals
 * rather than fataling. Runs the CREATE at most once per request.
 */
function sita_xml_importer_maybe_create_tables() {
    static $checked = false;
    if ( $checked ) {
        return;
    }
    $checked = true;

    if ( get_option( SITA_XML_IMPORTER_DB_VERSION_OPT ) !== SITA_XML_IMPORTER_DB_VERSION ) {
        sita_xml_importer_create_tables();
    }
}

/* -------------------------------------------------------------------------
 * Article record CRUD + dedup lookups
 * ---------------------------------------------------------------------- */

/**
 * Fetch the article row for a SITA article id, or null.
 *
 * @param string $sita_id
 * @return object|null
 */
function sita_xml_importer_article_get( $sita_id ) {
    global $wpdb;
    $table = sita_xml_importer_article_table();

    return $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE sita_id = %s LIMIT 1", (string) $sita_id )
    );
}

/**
 * Insert or update the article row keyed by sita_id. $data may contain any of:
 * post_id, time_published, time_modified, image_url, attachment_id, last_run_id.
 *
 * @param string $sita_id
 * @param array  $data
 * @return void
 */
function sita_xml_importer_article_upsert( $sita_id, array $data ) {
    global $wpdb;
    $table = sita_xml_importer_article_table();
    $now   = current_time( 'mysql' );

    $existing = sita_xml_importer_article_get( $sita_id );

    $columns = [
        'post_id'        => isset( $data['post_id'] ) ? (int) $data['post_id'] : null,
        'time_published' => isset( $data['time_published'] ) ? (string) $data['time_published'] : null,
        'time_modified'  => isset( $data['time_modified'] ) ? (string) $data['time_modified'] : null,
        'image_url'      => array_key_exists( 'image_url', $data ) ? ( $data['image_url'] !== null ? (string) $data['image_url'] : null ) : null,
        'attachment_id'  => isset( $data['attachment_id'] ) ? (int) $data['attachment_id'] : null,
        'last_run_id'    => isset( $data['last_run_id'] ) ? (int) $data['last_run_id'] : null,
    ];

    if ( $existing ) {
        $update  = [];
        $formats = [];
        foreach ( $columns as $key => $value ) {
            if ( $value !== null ) {
                $update[ $key ] = $value;
                $formats[]      = in_array( $key, [ 'time_published', 'time_modified', 'image_url' ], true ) ? '%s' : '%d';
            }
        }
        $update['last_imported_at'] = $now;
        $formats[]                  = '%s';

        $wpdb->update( $table, $update, [ 'sita_id' => (string) $sita_id ], $formats, [ '%s' ] );
        return;
    }

    $wpdb->insert(
        $table,
        [
            'sita_id'           => (string) $sita_id,
            'post_id'           => (int) ( $columns['post_id'] ?? 0 ),
            'time_published'    => (string) ( $columns['time_published'] ?? '' ),
            'time_modified'     => (string) ( $columns['time_modified'] ?? '' ),
            'image_url'         => $columns['image_url'],
            'attachment_id'     => (int) ( $columns['attachment_id'] ?? 0 ),
            'last_run_id'       => (int) ( $columns['last_run_id'] ?? 0 ),
            'first_imported_at' => $now,
            'last_imported_at'  => $now,
        ],
        [ '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
    );
}

add_action( 'before_delete_post', 'sita_xml_importer_on_post_deleted' );
/**
 * When a post is permanently deleted, stamp its article row as deleted rather than
 * removing it. Trashing does not fire this, so a trashed post is left alone.
 */
function sita_xml_importer_on_post_deleted( $post_id ) {
    sita_xml_importer_article_mark_deleted_by_post( (int) $post_id );
}

add_action( 'delete_attachment', 'sita_xml_importer_on_attachment_deleted' );
/**
 * An imported image was deleted from the media library: forget the attachment so
 * the next import downloads it again. Hooks `delete_attachment`, which is what
 * WordPress fires for attachments (`before_delete_post` is not). `image_url` is
 * kept so the repair pass can re-download from it.
 */
function sita_xml_importer_on_attachment_deleted( $attachment_id ) {
    global $wpdb;

    $wpdb->update(
        sita_xml_importer_article_table(),
        [ 'attachment_id' => 0 ],
        [ 'attachment_id' => (int) $attachment_id ],
        [ '%d' ],
        [ '%d' ]
    );
}

/**
 * Stamp the dedup row(s) for a post id as deleted (soft delete, kept for audit).
 * Used by the delete hook and by the import loop's self-heal for rows whose post
 * no longer exists.
 */
function sita_xml_importer_article_mark_deleted_by_post( $post_id ) {
    global $wpdb;
    $wpdb->update(
        sita_xml_importer_article_table(),
        [ 'deleted_at' => current_time( 'mysql' ) ],
        [ 'post_id' => (int) $post_id ],
        [ '%s' ],
        [ '%d' ]
    );
}

/**
 * How many article rows have a recorded deletion (audit trail).
 */
function sita_xml_importer_article_deleted_count() {
    global $wpdb;
    $table = sita_xml_importer_article_table();

    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NOT NULL" );
}

/**
 * How many imported articles are missing their featured image: the feed carried an
 * image URL and the post still exists, but no attachment is recorded.
 */
function sita_xml_importer_missing_image_count() {
    global $wpdb;
    $table = sita_xml_importer_article_table();

    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$table}
          WHERE attachment_id = 0
            AND post_id > 0
            AND deleted_at IS NULL
            AND image_url IS NOT NULL
            AND image_url != ''"
    );
}

/**
 * A batch of article rows still missing their featured image, least recently
 * attempted first.
 *
 * @param int $limit
 * @return array<int,object> rows with sita_id, post_id, image_url
 */
function sita_xml_importer_missing_image_rows( $limit = 50 ) {
    global $wpdb;
    $table = sita_xml_importer_article_table();
    $limit = max( 1, (int) $limit );

    return (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT sita_id, post_id, image_url FROM {$table}
              WHERE attachment_id = 0
                AND post_id > 0
                AND deleted_at IS NULL
                AND image_url IS NOT NULL
                AND image_url != ''
              ORDER BY last_imported_at ASC, id ASC
              LIMIT %d",
            $limit
        )
    );
}

/**
 * Resolve a post id from a SITA article id (the dedup key). Table-only; the
 * `sita_xml_importer_resolve_post_id` filter is the seam the removable legacy
 * module hooks to fall back to old `_w_id` postmeta and backfill the row.
 *
 * @param string $sita_id
 * @return int Post id, or 0 when unknown.
 */
function sita_xml_importer_find_post_by_sita_id( $sita_id ) {
    $row = sita_xml_importer_article_get( $sita_id );
    if ( $row ) {
        return (int) $row->post_id;
    }

    /**
     * Filter the resolved post id when the article table has no row yet.
     * The legacy migration module uses this to read old postmeta + backfill.
     *
     * @param int    $post_id 0 (no table row).
     * @param string $sita_id
     */
    return (int) apply_filters( 'sita_xml_importer_resolve_post_id', 0, $sita_id );
}

/**
 * Resolve an existing attachment id for a source image URL (dedup so the same
 * image is downloaded once). Table-only; the `sita_xml_importer_resolve_attachment_id`
 * filter is the legacy seam for the old `_w_turl` postmeta.
 *
 * @param string $image_url
 * @return int Attachment id, or 0.
 */
function sita_xml_importer_find_attachment_by_image_url( $image_url ) {
    global $wpdb;
    $table = sita_xml_importer_article_table();

    $attachment_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT attachment_id FROM {$table} WHERE image_url = %s AND attachment_id > 0 LIMIT 1",
            (string) $image_url
        )
    );

    if ( $attachment_id ) {
        return $attachment_id;
    }

    /**
     * Filter the resolved attachment id when the article table has no row yet.
     *
     * @param int    $attachment_id 0 (no table row).
     * @param string $image_url
     */
    return (int) apply_filters( 'sita_xml_importer_resolve_attachment_id', 0, $image_url );
}
