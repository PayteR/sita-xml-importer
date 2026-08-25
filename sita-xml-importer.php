<?php
/**
 * Plugin Name: SITA XML Importer
 * Description: Import news articles from SITA (Slovak News Agency) XML feeds into WordPress. Automatically creates posts with categories and featured images on a configurable schedule, with an on-demand trigger and a per-run import log.
 * Version: 2.1.6
 * Author: SITA
 * Author URI: https://sita.sk
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sita-xml-importer
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'SITA_XML_IMPORTER_VERSION', '2.1.6' );
define( 'SITA_XML_IMPORTER_DB_VERSION', '2.0.0' );
define( 'SITA_XML_IMPORTER_DB_VERSION_OPT', 'sita_xml_importer_db_version' );
define( 'SITA_XML_IMPORTER_OPTION', 'sita_xml_importer' );
define( 'SITA_XML_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'SITA_XML_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

// Framework-free feed parser - loaded first, functions.php depends on it.
require_once SITA_XML_IMPORTER_PATH . 'includes/class-sita-xml-importer-feed-parser.php';
require_once SITA_XML_IMPORTER_PATH . 'includes/functions.php';
require_once SITA_XML_IMPORTER_PATH . 'includes/db.php';
require_once SITA_XML_IMPORTER_PATH . 'includes/logger.php';

// Removable legacy modules - loaded only if present, so either file can be
// deleted (or omitted from the public build) once its job is done, with no code
// changes anywhere else. They attach to the core via filters/actions only.
foreach ( [ 'legacy-migration.php', 'legacy-compat.php' ] as $sita_xml_importer_legacy_file ) {
    $sita_xml_importer_legacy_path = SITA_XML_IMPORTER_PATH . 'includes/' . $sita_xml_importer_legacy_file;
    if ( file_exists( $sita_xml_importer_legacy_path ) ) {
        require_once $sita_xml_importer_legacy_path;
    }
}
unset( $sita_xml_importer_legacy_file, $sita_xml_importer_legacy_path );

if ( is_admin() ) {
    require_once SITA_XML_IMPORTER_PATH . 'includes/admin.php';
    require_once SITA_XML_IMPORTER_PATH . 'includes/diagnostics.php';
}

/**
 * Handle in-place upgrades (plugin files replaced without a deactivate/activate
 * cycle): ensure the tables exist and kick the legacy-meta migration.
 */
add_action( 'admin_init', 'sita_xml_importer_maybe_upgrade' );
function sita_xml_importer_maybe_upgrade() {
    if ( get_option( 'sita_xml_importer_version' ) === SITA_XML_IMPORTER_VERSION ) {
        return;
    }

    sita_xml_importer_create_tables();
    update_option( 'sita_xml_importer_version', SITA_XML_IMPORTER_VERSION );
    sita_xml_importer_reschedule();

    /**
     * Fires after an in-place upgrade. The removable legacy module hooks this
     * to start the data migration. No-op when the legacy files are absent.
     */
    do_action( 'sita_xml_importer_upgraded' );
}

// Apply schema changes that bump only the DB version (e.g. a new column) even
// when the plugin version is unchanged, so admin pages see the current schema.
add_action( 'admin_init', 'sita_xml_importer_maybe_create_tables' );

/**
 * Re-arm the recurring import event when it is missing. Called from the plugin's
 * admin page; a no-op once the event is scheduled.
 */
function sita_xml_importer_ensure_scheduled() {
    if ( ! wp_next_scheduled( 'sita_xml_importer_cron' ) ) {
        sita_xml_importer_reschedule();
    }
}

register_activation_hook( __FILE__, 'sita_xml_importer_activate' );
function sita_xml_importer_activate() {
    sita_xml_importer_create_tables();
    update_option( 'sita_xml_importer_version', SITA_XML_IMPORTER_VERSION );

    /**
     * Fires on activation after core setup. The removable legacy module hooks
     * this to migrate old settings, deactivate the old plugin, and start the
     * data migration. No-op when the legacy files are absent.
     */
    do_action( 'sita_xml_importer_activated' );

    // Schedule the recurring import last, after the legacy module has run:
    // deactivating another plugin mid-activation rewrites the cron option and can
    // drop an event scheduled earlier in the same request.
    sita_xml_importer_reschedule();
}

register_deactivation_hook( __FILE__, 'sita_xml_importer_deactivate' );
function sita_xml_importer_deactivate() {
    wp_clear_scheduled_hook( 'sita_xml_importer_cron' );
    wp_clear_scheduled_hook( 'sita_xml_importer_continue' );
    wp_clear_scheduled_hook( 'sita_xml_importer_repair_cron' );
    do_action( 'sita_xml_importer_deactivated' );
}

// The hourly schedule and the manual/continuation single events all resolve to
// the same handler; $run_type distinguishes them in the log ($pass is unused,
// present only to make continuation events unique for WP-Cron's dedupe guard).
add_action( 'sita_xml_importer_cron', 'sita_xml_importer_cron_handler', 10, 3 );
add_action( 'sita_xml_importer_continue', 'sita_xml_importer_cron_handler', 10, 3 );
function sita_xml_importer_cron_handler( $run_type = 'cron', $pass = 0, $force = false ) {
    if ( ! in_array( $run_type, [ 'cron', 'manual', 'cli' ], true ) ) {
        $run_type = 'cron';
    }
    if ( 'cron' === $run_type && defined( 'WP_CLI' ) && WP_CLI ) {
        $run_type = 'cli';
    }

    return sita_xml_importer_run_import( $run_type, (bool) $force );
}

/**
 * Register the sub-hourly cron intervals the importer offers. Needed in every
 * context (cron runs outside admin), so this lives in the always-loaded bootstrap.
 */
add_filter( 'cron_schedules', 'sita_xml_importer_cron_schedules' );
function sita_xml_importer_cron_schedules( $schedules ) {
    $schedules['sita_xml_importer_15min'] = [ 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Every 15 minutes (SITA XML Importer)', 'sita-xml-importer' ) ];
    $schedules['sita_xml_importer_30min'] = [ 'interval' => 30 * MINUTE_IN_SECONDS, 'display' => __( 'Every 30 minutes (SITA XML Importer)', 'sita-xml-importer' ) ];
    return $schedules;
}

/**
 * The WP-Cron recurrence name for the configured import frequency.
 */
function sita_xml_importer_cron_recurrence() {
    $map = [
        '15min'  => 'sita_xml_importer_15min',
        '30min'  => 'sita_xml_importer_30min',
        'hourly' => 'hourly',
    ];
    $interval = sita_xml_importer_get_option( 'import_interval', SITA_XML_IMPORTER_OPTION, '30min' );
    return $map[ $interval ] ?? 'sita_xml_importer_30min';
}

/**
 * (Re)schedule the recurring import at the configured frequency.
 */
function sita_xml_importer_reschedule() {
    wp_clear_scheduled_hook( 'sita_xml_importer_cron' );
    wp_schedule_event( time(), sita_xml_importer_cron_recurrence(), 'sita_xml_importer_cron' );
}

/**
 * Re-schedule when the import frequency setting changes.
 */
add_action( 'update_option_' . SITA_XML_IMPORTER_OPTION, 'sita_xml_importer_maybe_reschedule', 10, 2 );
function sita_xml_importer_maybe_reschedule( $old_value, $new_value ) {
    $old = is_array( $old_value ) ? ( $old_value['import_interval'] ?? '' ) : '';
    $new = is_array( $new_value ) ? ( $new_value['import_interval'] ?? '' ) : '';
    if ( $old !== $new ) {
        sita_xml_importer_reschedule();
    }
}
