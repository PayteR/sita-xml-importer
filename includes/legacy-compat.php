<?php
/**
 * Backward-compatible shims for the previous plugin generation.
 *
 * Forwards the new hooks to the old `sita_parser_*` names and re-exposes the old
 * global helper functions, so existing theme code keeps working. Self-contained:
 * it can be removed once nothing references those names.
 */

defined( 'ABSPATH' ) || exit;

/*
 * Forward new hooks to the old names.
 */

add_action( 'sita_xml_importer_start', 'sita_xml_importer_compat_start' );
function sita_xml_importer_compat_start() {
    do_action( 'sita_parser_start' );
}

add_filter( 'sita_xml_importer_article', 'sita_xml_importer_compat_article' );
function sita_xml_importer_compat_article( $article ) {
    return apply_filters( 'sita_parser_article', $article );
}

add_action( 'sita_xml_importer_inserted', 'sita_xml_importer_compat_inserted' );
function sita_xml_importer_compat_inserted( $post_id ) {
    do_action( 'sita_parser_inserted', $post_id );
}

add_action( 'sita_xml_importer_end', 'sita_xml_importer_compat_end' );
function sita_xml_importer_compat_end( $result ) {
    do_action( 'sita_parser_end', $result );
}

/*
 * Old global helper functions, routed to the table lookups by the meta key each
 * was historically called with.
 */

if ( ! function_exists( 'sita_post_exists_by_meta_value' ) ) {
    function sita_post_exists_by_meta_value( $value, $key = '_original_id' ) {
        if ( $key === '_w_id' ) {
            return sita_xml_importer_find_post_by_sita_id( $value );
        }
        if ( $key === '_w_turl' ) {
            return sita_xml_importer_find_attachment_by_image_url( $value );
        }

        // Generic key: kept self-contained so this file has no dependency on the
        // data-migration module.
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                $key,
                (string) $value
            )
        );
    }
}

if ( ! function_exists( 'sita_image_download_recursive' ) ) {
    function sita_image_download_recursive( $url, $recursion = 5 ) {
        return sita_xml_importer_download_image( $url, $recursion );
    }
}

if ( ! function_exists( 'sita_thumbnail_http_fix' ) ) {
    function sita_thumbnail_http_fix( $url, $protocol = 'https://' ) {
        return sita_xml_importer_fix_thumbnail_url( $url, $protocol );
    }
}
