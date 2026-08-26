<?php
/**
 * WP-CLI commands.
 *
 * OPTIONAL EXTRA. The plugin is fully usable without ever touching WP-CLI - the
 * scheduled import and the "Importovať teraz" button cover normal operation.
 * This exists for sites that already run WP-CLI and want the import driven by a
 * system cron instead of WP-Cron, which is traffic-dependent.
 *
 * The whole file is loaded only when WP-CLI is present, so it costs nothing on
 * a normal page load.
 *
 * @package sita-xml-importer
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

/**
 * Run and inspect the SITA feed importer.
 */
class Sita_Xml_Importer_CLI_Command {

    /**
     * Imports articles from the configured SITA XML feeds.
     *
     * Runs synchronously and exits non-zero if the run finished with errors, so
     * a system cron or monitoring wrapper can react to a failure.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Re-import every article in the feed, overwriting existing posts even
     * when the source has not changed. Without this flag unchanged articles are
     * skipped.
     *
     * ## EXAMPLES
     *
     *     # Import new and changed articles.
     *     $ wp sita-xml-importer run
     *
     *     # Re-import everything currently in the feed.
     *     $ wp sita-xml-importer run --force
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Associative arguments.
     */
    public function run( $args, $assoc_args ) {
        $force = isset( $assoc_args['force'] );

        if ( $force ) {
            WP_CLI::log( 'Running import with --force (existing articles will be overwritten).' );
        }

        $summary = sita_xml_importer_run_import( 'cli', $force );

        if ( empty( $summary ) ) {
            // run_import() returns an empty array when the lock could not be
            // acquired - another run (cron, manual button, or a second CLI call)
            // is still in progress.
            WP_CLI::warning( 'Another import is already running; nothing was done.' );
            return;
        }

        WP_CLI::log(
            sprintf(
                'Created: %d, updated: %d, skipped: %d, errors: %d.',
                $summary['created'],
                $summary['updated'],
                $summary['skipped'],
                $summary['errors']
            )
        );

        if ( ! empty( $summary['errors'] ) ) {
            WP_CLI::error(
                sprintf(
                    'Import finished with %d error(s). See Nastavenia > SITA XML Importer > Záznam importov.',
                    $summary['errors']
                )
            );
        }

        WP_CLI::success( 'Import finished.' );
    }

    /**
     * Shows the last import result and when the next scheduled run is due.
     *
     * ## EXAMPLES
     *
     *     $ wp sita-xml-importer status
     */
    public function status() {
        $next = wp_next_scheduled( 'sita_xml_importer_cron' );

        WP_CLI::log(
            $next
                ? sprintf( 'Next scheduled run: %s (site time).', get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i:s' ) )
                : 'No run is scheduled. Open the plugin settings page to re-arm the schedule.'
        );

        if ( sita_xml_importer_log_has_running() ) {
            WP_CLI::log( 'An import is running right now.' );
        }

        $last = sita_xml_importer_log_last_finished();

        if ( ! $last ) {
            WP_CLI::log( 'No import has finished yet.' );
            return;
        }

        WP_CLI::log(
            sprintf(
                'Last finished run: %s - %s (created: %d, updated: %d, skipped: %d, errors: %d).',
                $last->finished_at,
                $last->status,
                (int) $last->created_count,
                (int) $last->updated_count,
                (int) $last->skipped_count,
                (int) $last->error_count
            )
        );
    }
}

WP_CLI::add_command( 'sita-xml-importer', 'Sita_Xml_Importer_CLI_Command' );
