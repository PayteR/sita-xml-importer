<?php
/**
 * Per-run import logging.
 *
 * One row per import run in {prefix}sita_xml_importer_log. The row always
 * carries the summary counts; the `details` JSON holds per-article errors
 * always, and per-article successes only when verbose logging is enabled. The
 * run is accumulated in memory during the run and written once at the end
 * (never one INSERT per article).
 *
 * Size is bounded by two independent levers: verbose-off keeps each row small,
 * retention + a hard row cap keep the number of rows small.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SITA_XML_IMPORTER_LOG_MAX_ROWS' ) ) {
    define( 'SITA_XML_IMPORTER_LOG_MAX_ROWS', 20000 );
}

/**
 * In-memory record of the import run in progress. A single instance is held
 * statically for the duration of a run; counters are mutated on the object
 * (handle-passed, so no by-reference plumbing) and flushed to the log row once
 * at the end.
 */
final class Sita_Xml_Importer_Run {

    private static $current = null;

    public $id          = 0;
    public $run_type    = 'cron';
    public $started     = 0.0;
    public $feeds_total = 0;
    public $created     = 0;
    public $updated     = 0;
    public $skipped     = 0;
    public $errors      = 0;
    public $error_items = [];
    public $items       = [];

    public static function current() {
        return self::$current;
    }

    public static function start( $run_type ) {
        self::$current           = new self();
        self::$current->run_type = $run_type;
        return self::$current;
    }

    public static function stop() {
        self::$current = null;
    }
}

function sita_xml_importer_verbose_logging_enabled() {
    return (bool) sita_xml_importer_get_option( 'verbose_logging', SITA_XML_IMPORTER_OPTION, '' );
}

function sita_xml_importer_log_retention_days() {
    $days = (int) sita_xml_importer_get_option( 'log_retention_days', SITA_XML_IMPORTER_OPTION, 180 );
    return $days > 0 ? $days : 180;
}

/**
 * Strip the query string (which may carry a subscriber access token) from a
 * feed URL before it is stored in a log that may be exported and shared.
 */
function sita_xml_importer_redact_url( $url ) {
    $url = (string) $url;
    $pos = strpos( $url, '?' );
    return $pos === false ? $url : substr( $url, 0, $pos ) . '?…';
}

/**
 * Open a run: insert a "running" row and start the in-memory record.
 *
 * @param string $run_type cron|manual|cli
 * @return int Run id (0 on failure).
 */
function sita_xml_importer_run_begin( $run_type = 'cron' ) {
    global $wpdb;

    sita_xml_importer_maybe_create_tables();

    $run_type = in_array( $run_type, [ 'cron', 'manual', 'cli' ], true ) ? $run_type : 'cron';

    $wpdb->insert(
        sita_xml_importer_log_table(),
        [
            'started_at' => current_time( 'mysql' ),
            'run_type'   => $run_type,
            'status'     => 'running',
        ],
        [ '%s', '%s', '%s' ]
    );

    $run          = Sita_Xml_Importer_Run::start( $run_type );
    $run->id      = (int) $wpdb->insert_id;
    $run->started = microtime( true );

    return $run->id;
}

function sita_xml_importer_run_id() {
    $run = Sita_Xml_Importer_Run::current();
    return $run ? (int) $run->id : 0;
}

function sita_xml_importer_run_set_feeds_total( $count ) {
    $run = Sita_Xml_Importer_Run::current();
    if ( $run ) {
        $run->feeds_total = (int) $count;
    }
}

/**
 * Record a per-article outcome.
 *
 * @param string $outcome created|updated|skipped
 * @param array  $article Parsed article (for title/id/url in verbose mode).
 * @param int    $post_id
 */
function sita_xml_importer_run_record( $outcome, array $article = [], $post_id = 0 ) {
    $run = Sita_Xml_Importer_Run::current();
    if ( ! $run ) {
        return;
    }

    if ( in_array( $outcome, [ 'created', 'updated', 'skipped' ], true ) ) {
        $run->$outcome++;
    }

    if ( sita_xml_importer_verbose_logging_enabled() ) {
        $run->items[] = [
            'outcome' => $outcome,
            'sita_id' => (string) ( $article['_w_id'] ?? '' ),
            'title'   => (string) ( $article['title'] ?? '' ),
            'url'     => ( $outcome !== 'skipped' && $post_id ) ? get_permalink( $post_id ) : '',
            'post_id' => (int) $post_id,
        ];
    }
}

/**
 * Record an error. Always stored in the run details (not just verbose) and also
 * mirrored to the debug log.
 *
 * @param string $message
 * @param array  $context_data Optional {sita_id, title, url}.
 */
function sita_xml_importer_run_error( $message, array $context_data = [] ) {
    sita_xml_importer_log( $message );

    $run = Sita_Xml_Importer_Run::current();
    if ( ! $run ) {
        return;
    }

    $run->errors++;
    $run->error_items[] = [
        'sita_id' => (string) ( $context_data['sita_id'] ?? '' ),
        'title'   => (string) ( $context_data['title'] ?? '' ),
        'url'     => (string) ( $context_data['url'] ?? '' ),
        'message' => (string) $message,
    ];
}

/**
 * Close the run: compute status, write counts + details JSON, prune.
 *
 * @param bool $incomplete True when the run stopped on the time budget with
 *                         work still pending (status becomes "partial").
 * @return array Summary counts.
 */
function sita_xml_importer_run_end( $incomplete = false ) {
    global $wpdb;

    $run = Sita_Xml_Importer_Run::current();
    if ( ! $run ) {
        return [];
    }

    if ( $run->errors > 0 && ( $run->created + $run->updated ) === 0 ) {
        $status = 'failed';
    } elseif ( $run->errors > 0 || $incomplete ) {
        $status = 'partial';
    } else {
        $status = 'success';
    }

    $details = [ 'errors' => $run->error_items ];
    if ( sita_xml_importer_verbose_logging_enabled() ) {
        $details['items'] = $run->items;
    }

    $wpdb->update(
        sita_xml_importer_log_table(),
        [
            'finished_at'   => current_time( 'mysql' ),
            'status'        => $status,
            'feeds_total'   => (int) $run->feeds_total,
            'created_count' => (int) $run->created,
            'updated_count' => (int) $run->updated,
            'skipped_count' => (int) $run->skipped,
            'error_count'   => (int) $run->errors,
            'details'       => wp_json_encode( $details ),
        ],
        [ 'id' => (int) $run->id ],
        [ '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' ],
        [ '%d' ]
    );

    $summary = [
        'id'      => (int) $run->id,
        'status'  => $status,
        'created' => (int) $run->created,
        'updated' => (int) $run->updated,
        'skipped' => (int) $run->skipped,
        'errors'  => (int) $run->errors,
    ];

    Sita_Xml_Importer_Run::stop();

    sita_xml_importer_log_prune();

    return $summary;
}

/**
 * Enforce retention (age) and the hard row cap.
 */
function sita_xml_importer_log_prune() {
    global $wpdb;
    $table = sita_xml_importer_log_table();

    $days = sita_xml_importer_log_retention_days();
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table} WHERE started_at < ( NOW() - INTERVAL %d DAY )",
            $days
        )
    );

    $max = (int) apply_filters( 'sita_xml_importer_log_max_rows', SITA_XML_IMPORTER_LOG_MAX_ROWS );
    if ( $max < 1 ) {
        return;
    }

    $threshold_id = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $max )
    );
    if ( $threshold_id > 0 ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $threshold_id ) );
    }
}

/**
 * Recent run rows for the admin list.
 *
 * @return array
 */
function sita_xml_importer_log_recent( $limit = 20, $offset = 0 ) {
    global $wpdb;
    $table = sita_xml_importer_log_table();

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
            max( 1, (int) $limit ),
            max( 0, (int) $offset )
        )
    );
}

function sita_xml_importer_log_total_rows() {
    global $wpdb;
    return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . sita_xml_importer_log_table() );
}

/**
 * The most recently finished run (for the "import now" polling UI).
 *
 * @return object|null
 */
function sita_xml_importer_log_last_finished() {
    global $wpdb;
    $table = sita_xml_importer_log_table();

    return $wpdb->get_row(
        "SELECT * FROM {$table} WHERE status <> 'running' ORDER BY id DESC LIMIT 1"
    );
}

function sita_xml_importer_log_has_running() {
    global $wpdb;
    $table = sita_xml_importer_log_table();
    return (bool) $wpdb->get_var( "SELECT id FROM {$table} WHERE status = 'running' ORDER BY id DESC LIMIT 1" );
}

/* -------------------------------------------------------------------------
 * CSV export
 * ---------------------------------------------------------------------- */

/**
 * Neutralise CSV/formula injection: a cell that a spreadsheet would treat as a
 * formula (leading = + - @, or a control char) is prefixed with a quote.
 */
function sita_xml_importer_csv_cell( $value ) {
    $value = (string) $value;
    if ( $value !== '' && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
        $value = "'" . $value;
    }
    return $value;
}

/**
 * Stream a CSV export of runs (and their per-article detail) in a date range
 * directly to the browser. Caller handles capability + nonce and must not have
 * emitted output yet.
 *
 * @param string $from Y-m-d (inclusive) or ''.
 * @param string $to   Y-m-d (inclusive) or ''.
 */
function sita_xml_importer_log_export_csv( $from = '', $to = '' ) {
    global $wpdb;
    $table = sita_xml_importer_log_table();

    $where  = [];
    $params = [];
    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
        $where[]  = 'started_at >= %s';
        $params[] = $from . ' 00:00:00';
    }
    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
        $where[]  = 'started_at <= %s';
        $params[] = $to . ' 23:59:59';
    }
    $sql = "SELECT * FROM {$table}";
    if ( $where ) {
        $sql .= ' WHERE ' . implode( ' AND ', $where );
    }
    $sql .= ' ORDER BY id ASC';
    $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

    $filename = 'sita-xml-importer-log-' . gmdate( 'Ymd-His' ) . '.csv';

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

    $out = fopen( 'php://output', 'w' );
    // UTF-8 BOM so Excel reads Slovak diacritics correctly.
    fwrite( $out, "\xEF\xBB\xBF" );

    // System-info header (as # comment rows) so a shared export is self-describing.
    fputcsv( $out, [ '# SITA XML Importer ' . SITA_XML_IMPORTER_VERSION ] );
    fputcsv( $out, [ '# WordPress', get_bloginfo( 'version' ), 'PHP', PHP_VERSION ] );
    fputcsv( $out, [ '# Site', home_url() ] );
    fputcsv( $out, [ '# Exported', gmdate( 'Y-m-d H:i:s' ) . ' UTC', 'Range', ( $from ?: '-' ) . ' … ' . ( $to ?: '-' ) ] );
    fputcsv( $out, [] );

    fputcsv( $out, [
        'run_id', 'started_at', 'finished_at', 'run_type', 'status',
        'created', 'updated', 'skipped', 'errors',
        'item_type', 'sita_id', 'title', 'url', 'message',
    ] );

    foreach ( (array) $rows as $row ) {
        fputcsv( $out, array_map( 'sita_xml_importer_csv_cell', [
            $row->id, $row->started_at, $row->finished_at, $row->run_type, $row->status,
            $row->created_count, $row->updated_count, $row->skipped_count, $row->error_count,
            'summary', '', '', '', '',
        ] ) );

        $details = json_decode( (string) $row->details, true );
        if ( ! is_array( $details ) ) {
            continue;
        }

        foreach ( (array) ( $details['errors'] ?? [] ) as $err ) {
            fputcsv( $out, array_map( 'sita_xml_importer_csv_cell', [
                $row->id, $row->started_at, $row->finished_at, $row->run_type, $row->status,
                '', '', '', '',
                'error', $err['sita_id'] ?? '', $err['title'] ?? '', $err['url'] ?? '', $err['message'] ?? '',
            ] ) );
        }

        foreach ( (array) ( $details['items'] ?? [] ) as $item ) {
            fputcsv( $out, array_map( 'sita_xml_importer_csv_cell', [
                $row->id, $row->started_at, $row->finished_at, $row->run_type, $row->status,
                '', '', '', '',
                $item['outcome'] ?? 'item', $item['sita_id'] ?? '', $item['title'] ?? '', $item['url'] ?? '', '',
            ] ) );
        }
    }

    fclose( $out );
}
