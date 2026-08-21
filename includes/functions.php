<?php

defined( 'ABSPATH' ) || exit;

function sita_xml_importer_get_option( $option, $section, $default = '' ) {
    $options = get_option( $section );

    if ( isset( $options[ $option ] ) ) {
        return $options[ $option ];
    }

    return $default;
}

function sita_xml_importer_get_xml_feeds() {
    $feeds = sita_xml_importer_get_option( 'xml_feeds', SITA_XML_IMPORTER_OPTION );

    if ( empty( $feeds ) ) {
        return [];
    }

    $lines  = preg_split( '/\r\n|\r|\n/', $feeds );
    $result = [];

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( $line !== '' && filter_var( $line, FILTER_VALIDATE_URL ) ) {
            $result[] = $line;
        }
    }

    return $result;
}

/* -------------------------------------------------------------------------
 * Run orchestration: lock, time budget, chunked continuation
 * ---------------------------------------------------------------------- */

/**
 * Entry point for one import run (cron, manual or CLI). Guards against
 * overlapping runs, opens a log run, fetches + saves, and reschedules a
 * continuation when it stops on the time budget with work still pending.
 *
 * @param string $run_type cron|manual|cli
 * @return array Run summary.
 */
function sita_xml_importer_run_import( $run_type = 'cron', $force = false ) {
    if ( ! sita_xml_importer_acquire_lock() ) {
        sita_xml_importer_log( 'Import skipped - another run is already in progress.' );
        return [];
    }

    // Best-effort: lift the time limit where the host allows it. We still honour
    // our own budget below in case set_time_limit() is disabled.
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
    }

    $incomplete = false;
    $summary    = [];

    // finally: release the lock even if a hooked callback or WP core throws,
    // so one failing run cannot block imports for the full lock TTL.
    try {
        sita_xml_importer_run_begin( $run_type );
        $articles   = sita_xml_importer_parse_feeds();
        $incomplete = sita_xml_importer_process_save( $articles, $force );
        $summary    = sita_xml_importer_run_end( $incomplete );
    } finally {
        sita_xml_importer_release_lock();
    }

    if ( $incomplete ) {
        sita_xml_importer_schedule_continuation( $run_type, $force );
    } else {
        delete_transient( 'sita_xml_importer_passes' );

        // Feed pass is done. Anything still missing an image is no longer in the
        // feed, so queue a background repair that works from the stored URL.
        if (
            ! wp_next_scheduled( 'sita_xml_importer_repair_cron' )
            && sita_xml_importer_missing_image_count() > 0
        ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'sita_xml_importer_repair_cron' );
        }
    }

    return $summary;
}

function sita_xml_importer_acquire_lock() {
    if ( sita_xml_importer_is_running() ) {
        return false;
    }
    set_transient( 'sita_xml_importer_lock', time(), 10 * MINUTE_IN_SECONDS );
    return true;
}

function sita_xml_importer_release_lock() {
    delete_transient( 'sita_xml_importer_lock' );
}

/**
 * Timestamp-based so a stale lock self-clears after the TTL even where the object
 * cache does not honour transient expiry.
 */
function sita_xml_importer_is_running() {
    $since = (int) get_transient( 'sita_xml_importer_lock' );
    return $since > 0 && ( time() - $since ) < 10 * MINUTE_IN_SECONDS;
}

/**
 * Seconds of work a single pass may spend in the save loop before yielding.
 * Capped to 80% of the host's execution limit when one is set.
 */
function sita_xml_importer_time_budget() {
    $limit  = (int) ini_get( 'max_execution_time' );
    $target = 45;
    if ( $limit > 0 ) {
        $target = min( $target, max( 5, (int) floor( $limit * 0.8 ) ) );
    }
    return (float) apply_filters( 'sita_xml_importer_time_budget', $target, $limit );
}

/**
 * Queue an immediate follow-up pass, bounded so a persistently failing feed
 * cannot reschedule forever.
 */
function sita_xml_importer_schedule_continuation( $run_type, $force = false ) {
    $passes = (int) get_transient( 'sita_xml_importer_passes' );
    $max    = (int) apply_filters( 'sita_xml_importer_max_passes', 20 );

    if ( $passes >= $max ) {
        sita_xml_importer_log( 'Import continuation cap reached (' . $max . ' passes) - stopping until next scheduled run.' );
        delete_transient( 'sita_xml_importer_passes' );
        return;
    }

    $next = $passes + 1;
    set_transient( 'sita_xml_importer_passes', $next, HOUR_IN_SECONDS );

    // Dedicated hook + unique pass number so WP-Cron's 10-minute duplicate-event
    // guard cannot silently drop back-to-back continuation passes. Carry $force
    // so a forced overwrite keeps overwriting across passes.
    wp_schedule_single_event( time(), 'sita_xml_importer_continue', [ $run_type, $next, $force ] );
    spawn_cron();
}

/* -------------------------------------------------------------------------
 * Feed parsing
 * ---------------------------------------------------------------------- */

function sita_xml_importer_parse_feeds() {
    $articles = [];
    $feeds    = sita_xml_importer_get_xml_feeds();

    sita_xml_importer_run_set_feeds_total( count( $feeds ) );

    if ( empty( $feeds ) ) {
        return $articles;
    }

    $allowable_tags = sita_xml_importer_get_option(
        'allowable_tags',
        SITA_XML_IMPORTER_OPTION,
        Sita_Xml_Importer_Feed_Parser::DEFAULT_ALLOWABLE_TAGS
    );

    $parser = new Sita_Xml_Importer_Feed_Parser( $allowable_tags );

    foreach ( $feeds as $feed_url ) {
        $response = wp_remote_get( $feed_url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            sita_xml_importer_run_error(
                'Feed fetch failed for ' . sita_xml_importer_redact_url( $feed_url ) . ': ' . $response->get_error_message(),
                [ 'url' => sita_xml_importer_redact_url( $feed_url ) ]
            );
            continue;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $response_code ) {
            sita_xml_importer_run_error(
                'Feed returned HTTP ' . $response_code . ' for ' . sita_xml_importer_redact_url( $feed_url ),
                [ 'url' => sita_xml_importer_redact_url( $feed_url ) ]
            );
            continue;
        }

        $body = wp_remote_retrieve_body( $response );

        if ( empty( $body ) ) {
            sita_xml_importer_run_error(
                'Empty response body from feed: ' . sita_xml_importer_redact_url( $feed_url ),
                [ 'url' => sita_xml_importer_redact_url( $feed_url ) ]
            );
            continue;
        }

        // Parsing itself is WordPress-free and lives in the feed parser class -
        // fetching, error reporting and everything downstream stays here.
        $parsed = $parser->parse( $body );

        foreach ( $parser->get_errors() as $parse_error ) {
            sita_xml_importer_run_error(
                'XML parse failed for ' . sita_xml_importer_redact_url( $feed_url ) . ': ' . $parse_error,
                [ 'url' => sita_xml_importer_redact_url( $feed_url ) ]
            );
        }

        if ( $parsed ) {
            $articles = array_merge( $articles, $parsed );
        }
    }

    return $articles;
}

/* -------------------------------------------------------------------------
 * Persisting articles
 * ---------------------------------------------------------------------- */

/**
 * @param array $articles
 * @return bool True when the pass yielded on the time budget with articles left
 *              to process (the caller then schedules a continuation).
 */
function sita_xml_importer_process_save( $articles = [], $force = false ) {
    if ( empty( $articles ) ) {
        return false;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $result = [];
    $run_id = sita_xml_importer_run_id();

    $general_post_status = sita_xml_importer_get_option( 'xml_status', SITA_XML_IMPORTER_OPTION, 'pending' );
    $general_post_type   = sita_xml_importer_get_option( 'post_type', SITA_XML_IMPORTER_OPTION, 'post' );
    $general_post_user   = (int) sita_xml_importer_get_option( 'xml_user', SITA_XML_IMPORTER_OPTION );

    // Never create author-0 posts: fall back to the first administrator when no
    // author is configured.
    if ( ! $general_post_user ) {
        $general_post_user = sita_xml_importer_fallback_author();
    }

    $current_user = wp_get_current_user();

    if ( $general_post_user ) {
        wp_set_current_user( $general_post_user );
    }

    /**
     * Fires before import processing begins.
     *
     * @since 1.0.0
     */
    do_action( 'sita_xml_importer_start' );

    $category_saving_lowest = ! sita_xml_importer_get_option( 'xml_categorysaving', SITA_XML_IMPORTER_OPTION );

    $budget     = sita_xml_importer_time_budget();
    $started    = microtime( true );
    $did_work   = 0;
    $incomplete = false;

    foreach ( $articles as $article ) {

        /**
         * Filter an article before processing. Return false to skip.
         *
         * @since 1.0.0
         * @param array $article Article data parsed from XML feed.
         */
        $article = apply_filters( 'sita_xml_importer_article', $article );

        if ( ! $article ) {
            continue;
        }

        $sita_id      = (string) $article['_w_id'];
        $post_id      = sita_xml_importer_find_post_by_sita_id( $sita_id );
        $current_post = $post_id ? get_post( $post_id ) : null;

        // Self-heal: if the dedup row points at a post that was permanently
        // deleted, stamp it deleted (audit) and re-import. Catches rows left by
        // deletions that predate the before_delete_post hook. Trashed posts still
        // exist (status 'trash'), so they are kept and not re-imported.
        if ( $post_id && ! $current_post ) {
            sita_xml_importer_article_mark_deleted_by_post( $post_id );
            $post_id = 0;
        }

        // Unchanged articles are a cheap skip that never triggers a yield, so a
        // continuation pass always advances to genuinely new work instead of
        // re-doing the front of the feed.
        if ( $post_id && ! $force ) {
            $existing = sita_xml_importer_article_get( $sita_id );
            $prev_tm  = $existing ? (string) $existing->time_modified : '';
            if ( $prev_tm === (string) $article['time_modified'] ) {
                // Verify the featured image is actually in place: it may never
                // have been downloaded, never linked, or point at a deleted
                // attachment. The matching fast path costs one cached meta read.
                $thumb_id = (int) get_post_thumbnail_id( $post_id );
                $row_att  = $existing ? (int) $existing->attachment_id : 0;

                // Fast path: the post's featured image is exactly the attachment we
                // recorded. The delete hook clears attachment_id when an attachment
                // is deleted, so a match means it is present and valid - nothing to
                // check. This is the overwhelming majority of skipped articles.
                if ( ! $thumb_id || $thumb_id !== $row_att ) {
                    if ( $thumb_id && get_post( $thumb_id ) ) {
                        // A valid image is attached that we did not record (set by
                        // hand, or before this bookkeeping existed). Never override
                        // an editor's choice - adopt it so later runs take the fast
                        // path instead of re-checking every time.
                        sita_xml_importer_article_upsert( $sita_id, [ 'attachment_id' => $thumb_id ] );
                    } elseif ( $row_att && get_post( $row_att ) ) {
                        // Downloaded already, but not linked (or the post points at
                        // a deleted image): just (re)link it. No download, so this
                        // does not need the time budget.
                        sita_xml_importer_set_featured_image( $post_id, $row_att );
                    } else {
                        $heal_url = $article['thumbnail']['url'] ?? '';
                        if ( $heal_url ) {
                            // Genuinely missing: fetch + attach it. Real work, so
                            // respect the time budget like the insert path below.
                            if ( $did_work > 0 && ( microtime( true ) - $started ) > $budget ) {
                                $incomplete = true;
                                break;
                            }
                            $did_work++;

                            $healed_id = sita_xml_importer_handle_thumbnail( $post_id, $heal_url );
                            if ( $healed_id ) {
                                sita_xml_importer_article_upsert( $sita_id, [
                                    'image_url'     => $heal_url,
                                    'attachment_id' => $healed_id,
                                    'last_run_id'   => $run_id,
                                ] );
                            }
                        }
                    }
                }

                sita_xml_importer_run_record( 'skipped', $article );
                continue;
            }
        }

        // About to insert/update (+ download the image). Yield if over budget,
        // having done at least one item this pass; the rest continues next pass.
        if ( $did_work > 0 && ( microtime( true ) - $started ) > $budget ) {
            $incomplete = true;
            break;
        }
        $did_work++;

        $post = [
            'post_title'   => wp_strip_all_tags( $article['title'] ),
            'post_content' => $article['content'],
            'post_excerpt' => wp_strip_all_tags( $article['perex'] ),
            'post_type'    => $general_post_type ?: 'post',
            'post_status'  => $general_post_status,
            'post_author'  => $general_post_user,
        ];

        if ( $post_id ) {
            $post['ID']          = $post_id;
            $post['post_status'] = $current_post->post_status;
            $post['post_author'] = (int) $current_post->post_author;

            // Attribute the update to the post's own author (some hooks care),
            // then restore the import user for the next iteration.
            wp_set_current_user( (int) $current_post->post_author );
            $update_result = wp_update_post( $post, true );
            wp_set_current_user( $general_post_user ?: $current_user->ID );

            if ( is_wp_error( $update_result ) ) {
                sita_xml_importer_run_error(
                    'Failed to update post ' . $post_id . ': ' . $update_result->get_error_message(),
                    [ 'sita_id' => $sita_id, 'title' => $post['post_title'] ]
                );
                continue;
            }

            sita_xml_importer_run_record( 'updated', $article, $post_id );
            $result[] = 'Updated: ' . $post['post_title'] . ' (ID: ' . $post_id . ')';
        } else {
            $post_id = wp_insert_post( $post, true );

            if ( is_wp_error( $post_id ) ) {
                sita_xml_importer_run_error(
                    'Failed to create post "' . $post['post_title'] . '": ' . $post_id->get_error_message(),
                    [ 'sita_id' => $sita_id, 'title' => $post['post_title'] ]
                );
                continue;
            }

            sita_xml_importer_run_record( 'created', $article, $post_id );
            $result[] = 'Created: ' . $post['post_title'] . ' (ID: ' . $post_id . ')';

            /**
             * Fires when a new post is created from an imported article.
             *
             * @since 1.0.0
             * @param int $post_id The newly created post ID.
             */
            do_action( 'sita_xml_importer_inserted', $post_id );
        }

        $image_url     = $article['thumbnail']['url'] ?? '';
        $attachment_id = $image_url ? sita_xml_importer_handle_thumbnail(
            $post_id,
            $image_url,
            $article['thumbnail']['caption'] ?? null,
            $article['thumbnail']['source'] ?? null
        ) : 0;

        sita_xml_importer_article_upsert(
            $sita_id,
            [
                'post_id'        => $post_id,
                'time_published' => $article['time_publish'],
                'time_modified'  => $article['time_modified'],
                'image_url'      => $image_url ?: null,
                'attachment_id'  => $attachment_id,
                'last_run_id'    => $run_id,
            ]
        );

        sita_xml_importer_assign_categories( $post_id, $article['section'], $category_saving_lowest );
    }

    wp_set_current_user( $current_user->ID );

    /**
     * Fires after all articles in this pass have been processed.
     *
     * @since 1.0.0
     * @param array $result Array of result messages.
     */
    do_action( 'sita_xml_importer_end', $result );

    return $incomplete;
}

/**
 * Resolve a category name to a term id, creating it if missing. Memoised per
 * request so a feed that repeats the same section names doesn't re-run
 * get_term_by()/wp_create_category() for every article. Lookup is by name only
 * (matching the historical behaviour) so existing category structure is reused.
 *
 * @return int Term id, or 0 on failure.
 */
function sita_xml_importer_resolve_category( $name, $parent = 0 ) {
    static $cache = [];

    if ( array_key_exists( $name, $cache ) ) {
        return $cache[ $name ];
    }

    $term = get_term_by( 'name', $name, 'category' );
    if ( $term ) {
        $id = (int) $term->term_id;
    } else {
        $created = wp_create_category( $name, $parent );
        $id      = is_wp_error( $created ) ? 0 : (int) $created;
    }

    return $cache[ $name ] = $id;
}

function sita_xml_importer_assign_categories( $post_id, $section, $lowest_only = true ) {
    $cat_main = trim( $section['category'] );
    $cat_sub  = trim( $section['sub_category'] );

    $main_cat_id = $cat_main ? sita_xml_importer_resolve_category( $cat_main ) : 0;

    $sub_cat_id = 0;
    if ( $main_cat_id && $cat_sub ) {
        $cat_sub    = trim( str_replace( $cat_main . ' - ', '', $cat_sub ) );
        $sub_cat_id = $cat_sub ? sita_xml_importer_resolve_category( $cat_sub, $main_cat_id ) : 0;
    }

    if ( $lowest_only ) {
        $term_id = $sub_cat_id ?: $main_cat_id;
        if ( $term_id ) {
            wp_set_object_terms( $post_id, [ (int) $term_id ], 'category' );
        }
    } else {
        $terms = array_filter( [ (int) $main_cat_id, (int) $sub_cat_id ] );
        if ( $terms ) {
            wp_set_object_terms( $post_id, $terms, 'category' );
        }
    }
}

/**
 * Download + attach the featured image, reusing an existing attachment when the
 * same source URL was already imported.
 *
 * $caption / $source come from the feed. A string (including '') overrides the
 * attachment's caption/credit; null leaves WordPress's defaults untouched.
 *
 * @return int Attachment id (0 on failure).
 */
function sita_xml_importer_handle_thumbnail( $post_id, $image_url, $caption = null, $source = null ) {
    $attachment_id = sita_xml_importer_find_attachment_by_image_url( $image_url );

    // The reusable attachment may be gone: an editor deleted the image (e.g. to
    // force a clean re-download), or the id came from stale legacy `_w_turl` meta.
    // Reusing a dead id would attach a featured image that renders nothing and
    // would never self-correct, so drop it and download the image again.
    if ( $attachment_id && ! get_post( $attachment_id ) ) {
        $attachment_id = 0;
    }

    if ( ! $attachment_id ) {
        // SSRF guard: image URLs come from remote feed content. Reject non-HTTP
        // schemes and hosts that resolve to private/reserved IPs before fetching.
        if ( ! wp_http_validate_url( $image_url ) ) {
            sita_xml_importer_run_error(
                'Image URL rejected (unsafe or non-HTTP host): ' . $image_url,
                [ 'url' => $image_url ]
            );
            return 0;
        }

        $tmp_file = sita_xml_importer_download_image( $image_url );

        if ( ! $tmp_file || is_wp_error( $tmp_file ) ) {
            $error_msg = is_wp_error( $tmp_file ) ? $tmp_file->get_error_message() : 'download returned empty';
            sita_xml_importer_run_error(
                'Image download failed for post ' . $post_id . ' (' . $image_url . '): ' . $error_msg,
                [ 'url' => $image_url ]
            );
            return 0;
        }

        // Drop any query string so the extension is detected correctly on sideload.
        $clean_name = strtok( basename( $image_url ), '?' );

        $file_array = [
            'name'     => $post_id . '_' . sanitize_file_name( $clean_name ),
            'tmp_name' => $tmp_file,
        ];

        $attachment_id = media_handle_sideload( $file_array, $post_id );

        if ( is_wp_error( $attachment_id ) ) {
            sita_xml_importer_run_error(
                'Attachment failed for post ' . $post_id . ': ' . $attachment_id->get_error_message(),
                [ 'url' => $image_url ]
            );
            if ( file_exists( $tmp_file ) ) {
                wp_delete_file( $tmp_file );
            }
            return 0;
        }
    }

    if ( $attachment_id ) {
        sita_xml_importer_set_featured_image( $post_id, (int) $attachment_id );

        // Feed metadata is the source of truth for the caption + credit: apply it to a
        // freshly sideloaded attachment (overriding any embedded IPTC/EXIF) and to a
        // reused one, so a re-import refreshes them. '' clears a value, null leaves it
        // alone. Written only when it differs, so an unchanged image costs no write.
        $att         = get_post( (int) $attachment_id );
        $meta_update = [];
        if ( $att && null !== $caption && (string) $att->post_excerpt !== (string) $caption ) {
            $meta_update['post_excerpt'] = $caption;
        }
        if ( $att && null !== $source && (string) $att->post_content !== (string) $source ) {
            $meta_update['post_content'] = $source;
        }
        if ( $meta_update ) {
            $meta_update['ID'] = (int) $attachment_id;
            wp_update_post( $meta_update );
        }
    }

    return (int) $attachment_id;
}

/**
 * Link an attachment as a post's featured image, reliably in any context.
 *
 * set_post_thumbnail() deletes _thumbnail_id when its internal
 * wp_get_attachment_image() check returns empty, which can happen outside the
 * admin even for a valid image. So call the API, verify it took, and only set the
 * meta directly when it did not.
 */
function sita_xml_importer_set_featured_image( $post_id, $attachment_id ) {
    $post_id       = (int) $post_id;
    $attachment_id = (int) $attachment_id;

    if ( ! $post_id || ! $attachment_id || (int) get_post_thumbnail_id( $post_id ) === $attachment_id ) {
        return;
    }

    set_post_thumbnail( $post_id, $attachment_id );

    // Only patch when the API left the link unset AND the attachment really
    // exists - never write a _thumbnail_id pointing at a deleted attachment.
    if ( (int) get_post_thumbnail_id( $post_id ) !== $attachment_id && get_post( $attachment_id ) ) {
        update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
    }
}

/**
 * Kept as a function because it is part of the public/legacy surface:
 * `legacy-compat.php` exposes it as the old `sita_thumbnail_http_fix()`.
 * The implementation itself lives in the (WordPress-free) parser class.
 */
function sita_xml_importer_fix_thumbnail_url( $url, $protocol = 'https://' ) {
    return Sita_Xml_Importer_Feed_Parser::normalize_image_url( $url, $protocol );
}

/**
 * Download with a short bounded retry, so a feed full of dead image URLs cannot
 * burn the time budget. A 200 response with an empty body counts as a failure.
 */
function sita_xml_importer_download_image( $url, $retries = 2 ) {
    // Bounded timeout: download_url defaults to 300s - long enough for one slow
    // or hung image to stall an entire run on shared hosts.
    $image = download_url( $url, 30 );

    if ( ! is_wp_error( $image ) && is_string( $image ) && ( ! is_file( $image ) || filesize( $image ) < 512 ) ) {
        if ( is_string( $image ) && file_exists( $image ) ) {
            wp_delete_file( $image );
        }
        $image = new WP_Error( 'sita_xml_importer_empty_image', 'downloaded image was empty or truncated' );
    }

    if ( $retries > 0 && ( ! $image || is_wp_error( $image ) ) ) {
        sleep( 1 );
        return sita_xml_importer_download_image( $url, $retries - 1 );
    }

    return $image;
}

/* -------------------------------------------------------------------------
 * Featured-image repair (backfill missing images from the stored URL)
 * ---------------------------------------------------------------------- */

/**
 * Re-download featured images that are missing, working from the image_url stored
 * on each article row - so unlike a forced re-run this also covers articles that
 * are no longer in the feed. Time-budgeted; the repair cron repeats until it drains.
 *
 * @param int $limit Max rows to consider this pass.
 * @return array{repaired:int,adopted:int,failed:int,remaining:int}
 */
function sita_xml_importer_repair_missing_images( $limit = 50 ) {
    $rows     = sita_xml_importer_missing_image_rows( $limit );
    $repaired = 0;
    $adopted  = 0;
    $failed   = 0;

    $budget  = sita_xml_importer_time_budget();
    $started = microtime( true );
    $did     = 0;

    foreach ( $rows as $row ) {
        // Stop once over budget (having done at least one), so a slow batch never
        // overruns; unprocessed rows keep their old timestamp and lead next pass.
        if ( $did > 0 && ( microtime( true ) - $started ) > $budget ) {
            break;
        }

        $post_id   = (int) $row->post_id;
        $image_url = (string) $row->image_url;
        $sita_id   = (string) $row->sita_id;

        // The post was deleted since the row was written: stamp it and drop out.
        if ( ! get_post( $post_id ) ) {
            sita_xml_importer_article_mark_deleted_by_post( $post_id );
            continue;
        }

        // An editor set a thumbnail by hand: adopt it into the row so it stops
        // counting as missing, and never overwrite their choice.
        $manual = (int) get_post_thumbnail_id( $post_id );
        if ( $manual ) {
            sita_xml_importer_article_upsert( $sita_id, [ 'attachment_id' => $manual ] );
            $adopted++;
            continue;
        }

        $did++;
        $attachment_id = sita_xml_importer_handle_thumbnail( $post_id, $image_url );

        if ( $attachment_id ) {
            sita_xml_importer_article_upsert( $sita_id, [
                'attachment_id' => $attachment_id,
                'image_url'     => $image_url,
            ] );
            $repaired++;
        } else {
            // Still unavailable: move this row to the back of the queue so the
            // next pass tries the others first.
            sita_xml_importer_article_upsert( $sita_id, [] );
            $failed++;
        }
    }

    return [
        'repaired'  => $repaired,
        'adopted'   => $adopted,
        'failed'    => $failed,
        'remaining' => sita_xml_importer_missing_image_count(),
    ];
}

add_action( 'sita_xml_importer_repair_cron', 'sita_xml_importer_repair_cron_handler' );
/**
 * Background repair pass, scheduled at the end of a completed import. Stops when a
 * pass fixes nothing, so it never spins on image URLs that stay unavailable.
 */
function sita_xml_importer_repair_cron_handler() {
    // Defer to an in-progress import so the two never fetch the same image at
    // once; retry shortly. is_running() is timestamp-based and self-clears after
    // its TTL, so a crashed import can't block repair forever.
    if ( sita_xml_importer_is_running() ) {
        wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'sita_xml_importer_repair_cron' );
        return;
    }

    $batch  = (int) apply_filters( 'sita_xml_importer_repair_batch_size', 50 );
    $result = sita_xml_importer_repair_missing_images( $batch );

    if ( $result['remaining'] > 0 && ( $result['repaired'] > 0 || $result['adopted'] > 0 ) ) {
        wp_schedule_single_event( time() + 30, 'sita_xml_importer_repair_cron' );
        spawn_cron();
    }
}

/**
 * First administrator id, used as the post author when none is configured so
 * imports never create author-0 posts.
 *
 * @return int User id, or 0 when no administrator exists.
 */
function sita_xml_importer_fallback_author() {
    $admins = get_users( [
        'role'    => 'administrator',
        'orderby' => 'ID',
        'order'   => 'ASC',
        'number'  => 1,
        'fields'  => 'ID',
    ] );

    return $admins ? (int) $admins[0] : 0;
}

function sita_xml_importer_log( $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[SITA XML Importer] ' . $message );
    }
}
