<?php
/**
 * Standalone test for extension/content reconciliation before sideload.
 *
 * This is the safety net behind the Accept header: even when a CDN ignores the
 * header, or the feed names a file wrong, the extension must end up matching the
 * bytes - otherwise WordPress refuses the upload with a generic error.
 *
 * Run from the plugin root:
 *
 *     php tests/filename-mime-test.php
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
 * WordPress stubs.
 * ------------------------------------------------------------------------ */

// What wp_get_image_mime() should report for the file under test.
$GLOBALS['real_mime'] = 'image/jpeg';

function wp_get_image_mime( $file ) {
    return $GLOBALS['real_mime'];
}

function wp_get_mime_types() {
    return [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'gif'          => 'image/gif',
        'png'          => 'image/png',
        'webp'         => 'image/webp',
        'avif'         => 'image/avif',
        'pdf'          => 'application/pdf',
    ];
}

/* ---------------------------------------------------------------------------
 * Load only the function under test.
 * ------------------------------------------------------------------------ */
$src = file_get_contents( __DIR__ . '/../includes/functions.php' );
preg_match( '/^function sita_xml_importer_filename_for_content.*?\n\}/ms', $src, $m );

if ( empty( $m[0] ) ) {
    echo "FAILED: could not extract sita_xml_importer_filename_for_content()\n";
    exit( 1 );
}

eval( $m[0] );

// A real file has to exist - the function refuses to guess without one.
$tmp = tempnam( sys_get_temp_dir(), 'sxi' );
file_put_contents( $tmp, 'not really an image, the mime is stubbed' );

/* ---------------------------------------------------------------------------
 * Tests
 * ------------------------------------------------------------------------ */
echo "The reported bug: .webp URL, JPEG bytes\n";
$GLOBALS['real_mime'] = 'image/jpeg';
check(
    'renamed to match the content',
    sita_xml_importer_filename_for_content( '568210_dongfeng_mage_20-jpg-676x447.webp', $tmp ),
    '568210_dongfeng_mage_20-jpg-676x447.jpg'
);

echo "\nExtension already correct\n";
$GLOBALS['real_mime'] = 'image/webp';
check( 'webp stays webp', sita_xml_importer_filename_for_content( '1_photo.webp', $tmp ), '1_photo.webp' );

$GLOBALS['real_mime'] = 'image/jpeg';
check( 'jpg stays jpg', sita_xml_importer_filename_for_content( '1_photo.jpg', $tmp ), '1_photo.jpg' );
// jpeg and jpe are equally valid spellings of image/jpeg and must not be rewritten.
check( 'jpeg is not rewritten to jpg', sita_xml_importer_filename_for_content( '1_photo.jpeg', $tmp ), '1_photo.jpeg' );

echo "\nOther mismatches\n";
$GLOBALS['real_mime'] = 'image/webp';
check( 'jpg holding webp', sita_xml_importer_filename_for_content( '1_photo.jpg', $tmp ), '1_photo.webp' );

$GLOBALS['real_mime'] = 'image/avif';
check( 'png holding avif', sita_xml_importer_filename_for_content( '1_photo.png', $tmp ), '1_photo.avif' );

echo "\nAwkward names\n";
$GLOBALS['real_mime'] = 'image/png';
check( 'no extension at all', sita_xml_importer_filename_for_content( '1_photo', $tmp ), '1_photo.png' );
check( 'uppercase extension accepted as-is', sita_xml_importer_filename_for_content( '1_photo.PNG', $tmp ), '1_photo.PNG' );
check( 'dots in the name', sita_xml_importer_filename_for_content( '1_photo.v2.final.jpg', $tmp ), '1_photo.v2.final.png' );

echo "\nWhen nothing can be decided, leave it alone\n";
$GLOBALS['real_mime'] = false;   // not an image / undetectable
check( 'undetectable type is untouched', sita_xml_importer_filename_for_content( '1_photo.webp', $tmp ), '1_photo.webp' );

$GLOBALS['real_mime'] = 'image/x-unknown';   // core has no extension for it
check( 'unknown mime is untouched', sita_xml_importer_filename_for_content( '1_photo.webp', $tmp ), '1_photo.webp' );

$GLOBALS['real_mime'] = 'image/jpeg';
check( 'missing file is untouched', sita_xml_importer_filename_for_content( '1_photo.webp', '/no/such/file' ), '1_photo.webp' );

unlink( $tmp );

echo "\n" . ( $failures ? "FAILED: $failures of $checks checks\n" : "PASSED: all $checks checks\n" );
exit( $failures ? 1 : 0 );
