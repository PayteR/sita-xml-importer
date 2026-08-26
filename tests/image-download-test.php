<?php
/**
 * Standalone test for the image download filter handling.
 *
 * No PHPUnit, no WordPress, no database. WordPress's hook functions are stubbed
 * with a faithful-enough implementation to answer the questions that matter:
 *
 *   - is the Accept header actually applied to our own request?
 *   - is it applied ONLY to our request?
 *   - is the filter always removed - including on the retry path and when
 *     download_url() errors or throws?
 *
 * The last one is the real point: sita_xml_importer_download_image() recurses on
 * retry, so a leaked http_request_args filter would silently attach an Accept
 * header to every later HTTP call the site makes.
 *
 * Run from the plugin root:
 *
 *     php tests/image-download-test.php
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
 * Minimal WordPress hook system.
 * ------------------------------------------------------------------------ */
$GLOBALS['hooks'] = [];

function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
    $GLOBALS['hooks'][ $tag ][ $priority ][] = $cb;
    return true;
}

function remove_filter( $tag, $cb, $priority = 10 ) {
    if ( empty( $GLOBALS['hooks'][ $tag ][ $priority ] ) ) {
        return false;
    }
    foreach ( $GLOBALS['hooks'][ $tag ][ $priority ] as $i => $existing ) {
        if ( $existing === $cb ) {
            unset( $GLOBALS['hooks'][ $tag ][ $priority ][ $i ] );
            return true;
        }
    }
    return false;
}

function apply_filters( $tag, $value ) {
    $extra = array_slice( func_get_args(), 2 );
    if ( empty( $GLOBALS['hooks'][ $tag ] ) ) {
        return $value;
    }
    foreach ( $GLOBALS['hooks'][ $tag ] as $callbacks ) {
        foreach ( $callbacks as $cb ) {
            $value = $cb( $value, ...$extra );
        }
    }
    return $value;
}

/** Number of callbacks currently attached to a hook. */
function hook_count( $tag ) {
    $n = 0;
    foreach ( $GLOBALS['hooks'][ $tag ] ?? [] as $callbacks ) {
        $n += count( $callbacks );
    }
    return $n;
}

/* ---------------------------------------------------------------------------
 * Stubs for the WordPress pieces the function under test touches.
 * ------------------------------------------------------------------------ */
class WP_Error {
    private $code;
    private $message;
    public function __construct( $code = '', $message = '' ) {
        $this->code    = $code;
        $this->message = $message;
    }
    public function get_error_message() {
        return $this->message;
    }
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}

function wp_delete_file( $file ) {
    if ( file_exists( $file ) ) {
        unlink( $file );
    }
}

// Controls what the stubbed download_url() does, and records the args each call saw.
$GLOBALS['download_behaviour'] = 'ok';
$GLOBALS['download_calls']     = [];

function download_url( $url, $timeout = 300 ) {
    // Mirror what WP_Http does: run the request args through the filter.
    $args = apply_filters( 'http_request_args', [ 'headers' => [] ], $url );

    $GLOBALS['download_calls'][] = [ 'url' => $url, 'args' => $args ];

    switch ( $GLOBALS['download_behaviour'] ) {
        case 'error':
            return new WP_Error( 'http_404', 'not found' );

        case 'throw':
            throw new RuntimeException( 'network exploded' );

        default:
            // A real temp file, big enough to pass the truncation check.
            $tmp = tempnam( sys_get_temp_dir(), 'sxi' );
            file_put_contents( $tmp, str_repeat( 'x', 1024 ) );
            return $tmp;
    }
}

/* ---------------------------------------------------------------------------
 * Load only the function under test - functions.php as a whole needs WordPress.
 * ------------------------------------------------------------------------ */
$src = file_get_contents( __DIR__ . '/../includes/functions.php' );
preg_match( '/^function sita_xml_importer_download_image.*?\n\}/ms', $src, $m );

if ( empty( $m[0] ) ) {
    echo "FAILED: could not extract sita_xml_importer_download_image() from functions.php\n";
    exit( 1 );
}

eval( $m[0] );

/* ---------------------------------------------------------------------------
 * Tests
 * ------------------------------------------------------------------------ */
$url = 'https://cdn.example.test/photo-676x447.webp';

echo "Successful download\n";
$GLOBALS['download_behaviour'] = 'ok';
$GLOBALS['download_calls']     = [];

$file = sita_xml_importer_download_image( $url );

check( 'returns a real file', is_string( $file ) && is_file( $file ), true );
check( 'one request made', count( $GLOBALS['download_calls'] ), 1 );
check(
    'Accept header sent on our request',
    $GLOBALS['download_calls'][0]['args']['headers']['Accept'] ?? null,
    'image/avif,image/webp,image/apng,image/*,*/*;q=0.8'
);
check( 'no filter left behind', hook_count( 'http_request_args' ), 0 );
wp_delete_file( $file );

echo "\nOther URLs are untouched during our download\n";
$GLOBALS['download_calls'] = [];
// Attach an observer that records what an unrelated request would receive while
// our filter is live. Our filter must not touch it.
$seen = null;
add_filter( 'http_request_args', static function ( $args, $request_url ) use ( &$seen ) {
    if ( 'https://other.example.test/api' === $request_url ) {
        $seen = $args;
    }
    return $args;
}, 5, 2 );

$file = sita_xml_importer_download_image( $url );
apply_filters( 'http_request_args', [ 'headers' => [] ], 'https://other.example.test/api' );

check( 'unrelated URL got no Accept header', isset( $seen['headers']['Accept'] ), false );
check( 'only our observer remains', hook_count( 'http_request_args' ), 1 );
wp_delete_file( $file );
$GLOBALS['hooks'] = [];

echo "\nDownload error, with retries\n";
$GLOBALS['download_behaviour'] = 'error';
$GLOBALS['download_calls']     = [];

$result = sita_xml_importer_download_image( $url, 2 );

check( 'returns WP_Error', is_wp_error( $result ), true );
// Initial attempt + 2 retries: the function recurses, so this is the path where a
// leaked filter would pile up one copy per attempt.
check( 'retried until exhausted', count( $GLOBALS['download_calls'] ), 3 );
check( 'no filter left behind after retries', hook_count( 'http_request_args' ), 0 );

echo "\nException mid-download\n";
$GLOBALS['download_behaviour'] = 'throw';
$threw                        = false;

try {
    sita_xml_importer_download_image( $url, 0 );
} catch ( RuntimeException $e ) {
    $threw = true;
}

check( 'exception propagates', $threw, true );
check( 'no filter left behind after exception', hook_count( 'http_request_args' ), 0 );

echo "\nFilter can disable the header\n";
$GLOBALS['download_behaviour'] = 'ok';
$GLOBALS['download_calls']     = [];
add_filter( 'sita_xml_importer_image_accept_header', static function () {
    return false;
}, 10, 2 );

$file = sita_xml_importer_download_image( $url );

check( 'no Accept header when filtered off', isset( $GLOBALS['download_calls'][0]['args']['headers']['Accept'] ), false );
check( 'nothing attached when filtered off', hook_count( 'http_request_args' ), 0 );
wp_delete_file( $file );

echo "\n" . ( $failures ? "FAILED: $failures of $checks checks\n" : "PASSED: all $checks checks\n" );
exit( $failures ? 1 : 0 );
