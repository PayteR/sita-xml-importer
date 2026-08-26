<?php
/**
 * Standalone test for the multisite upload_filetypes handling.
 *
 * The function writes a NETWORK-WIDE option, so the guards matter more than the
 * happy path. This checks that it:
 *
 *   - adds only webp/avif, and only when missing
 *   - preserves every value already there, in order
 *   - never runs on single site, for a non-super-admin, or when filtered off
 *   - writes nothing at all when there is nothing to add
 *
 * Run from the plugin root:
 *
 *     php tests/upload-filetypes-test.php
 *
 * Exits 0 when everything passes, 1 on the first failure.
 * This directory is excluded from the WordPress.org build via .distignore.
 */

define( 'ABSPATH', __DIR__ );

$failures = 0;
$checks   = 0;

function check( $label, $actual, $expected ) {
    global $failures, $checks;
    $checks++;

    if ( $actual === $expected ) {
        echo "  ok   $label\n";
        return;
    }

    $failures++;
    echo "  FAIL $label\n";
    echo "       expected: " . var_export( $expected, true ) . "\n";
    echo "       actual:   " . var_export( $actual, true ) . "\n";
}

/* ---------------------------------------------------------------------------
 * WordPress stubs. The environment is driven by these globals.
 * ------------------------------------------------------------------------ */
$GLOBALS['is_multisite']   = true;
$GLOBALS['is_super_admin'] = true;
$GLOBALS['site_options']   = [];
$GLOBALS['writes']         = 0;
$GLOBALS['hooks']          = [];

function is_multisite() {
    return $GLOBALS['is_multisite'];
}

function is_super_admin() {
    return $GLOBALS['is_super_admin'];
}

function get_site_option( $key, $default = false ) {
    return array_key_exists( $key, $GLOBALS['site_options'] ) ? $GLOBALS['site_options'][ $key ] : $default;
}

function update_site_option( $key, $value ) {
    $GLOBALS['site_options'][ $key ] = $value;
    $GLOBALS['writes']++;
    return true;
}

function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
    $GLOBALS['hooks'][ $tag ][] = $cb;
    return true;
}

function apply_filters( $tag, $value ) {
    foreach ( $GLOBALS['hooks'][ $tag ] ?? [] as $cb ) {
        $value = $cb( $value );
    }
    return $value;
}

function __return_false() {
    return false;
}

// Core's known mime types, trimmed to what the function looks at.
function wp_get_mime_types() {
    return [
        'jpg|jpeg' => 'image/jpeg',
        'png'      => 'image/png',
        'gif'      => 'image/gif',
        'webp'     => 'image/webp',
        'avif'     => 'image/avif',
    ];
}

/* ---------------------------------------------------------------------------
 * Load only the function under test.
 * ------------------------------------------------------------------------ */
$src = file_get_contents( __DIR__ . '/../sita-xml-importer.php' );
preg_match( '/^function sita_xml_importer_maybe_allow_modern_image_uploads.*?\n\}/ms', $src, $m );

if ( empty( $m[0] ) ) {
    echo "FAILED: could not extract sita_xml_importer_maybe_allow_modern_image_uploads()\n";
    exit( 1 );
}

eval( $m[0] );

/** Reset the environment between cases. */
function reset_env( $current = 'jpg jpeg png gif' ) {
    $GLOBALS['is_multisite']   = true;
    $GLOBALS['is_super_admin'] = true;
    $GLOBALS['site_options']   = [ 'upload_filetypes' => $current ];
    $GLOBALS['writes']         = 0;
    $GLOBALS['hooks']          = [];
}

/* ---------------------------------------------------------------------------
 * Tests
 * ------------------------------------------------------------------------ */
echo "Default multisite value\n";
reset_env();
sita_xml_importer_maybe_allow_modern_image_uploads();
check( 'webp and avif appended', get_site_option( 'upload_filetypes' ), 'jpg jpeg png gif webp avif' );
check( 'written once', $GLOBALS['writes'], 1 );

echo "\nExisting custom value is preserved\n";
reset_env( 'jpg jpeg png gif mp3 mov avi wmv midi mid pdf svg mp4' );
sita_xml_importer_maybe_allow_modern_image_uploads();
check(
    'nothing dropped, new types appended at the end',
    get_site_option( 'upload_filetypes' ),
    'jpg jpeg png gif mp3 mov avi wmv midi mid pdf svg mp4 webp avif'
);

echo "\nPartially present\n";
reset_env( 'jpg png webp' );
sita_xml_importer_maybe_allow_modern_image_uploads();
check( 'only the missing one is added', get_site_option( 'upload_filetypes' ), 'jpg png webp avif' );

echo "\nNothing to do\n";
reset_env( 'jpg png webp avif' );
sita_xml_importer_maybe_allow_modern_image_uploads();
check( 'value unchanged', get_site_option( 'upload_filetypes' ), 'jpg png webp avif' );
check( 'no write at all', $GLOBALS['writes'], 0 );

echo "\nMessy whitespace and casing\n";
reset_env( "  JPG   png \t gif  " );
sita_xml_importer_maybe_allow_modern_image_uploads();
check( 'normalised, no empty entries', get_site_option( 'upload_filetypes' ), 'jpg png gif webp avif' );

echo "\nGuards\n";
reset_env();
$GLOBALS['is_multisite'] = false;
sita_xml_importer_maybe_allow_modern_image_uploads();
check( 'single site: no write', $GLOBALS['writes'], 0 );

reset_env();
$GLOBALS['is_super_admin'] = false;
sita_xml_importer_maybe_allow_modern_image_uploads();
check( 'non-super-admin: no write', $GLOBALS['writes'], 0 );
check( 'non-super-admin: value untouched', get_site_option( 'upload_filetypes' ), 'jpg jpeg png gif' );

reset_env();
add_filter( 'sita_xml_importer_add_network_upload_filetypes', '__return_false' );
sita_xml_importer_maybe_allow_modern_image_uploads();
check( 'filtered off: no write', $GLOBALS['writes'], 0 );
check( 'filtered off: value untouched', get_site_option( 'upload_filetypes' ), 'jpg jpeg png gif' );

echo "\n" . ( $failures ? "FAILED: $failures of $checks checks\n" : "PASSED: all $checks checks\n" );
exit( $failures ? 1 : 0 );
