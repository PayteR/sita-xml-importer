<?php
/**
 * Admin: settings screen, on-demand import trigger, run log + CSV export.
 * State-changing actions go through admin-post.php with a nonce + capability check.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Settings registration
 * ---------------------------------------------------------------------- */

add_action( 'admin_init', 'sita_xml_importer_admin_init' );
function sita_xml_importer_admin_init() {
    register_setting( SITA_XML_IMPORTER_OPTION, SITA_XML_IMPORTER_OPTION, [
        'sanitize_callback' => 'sita_xml_importer_sanitize_settings',
    ] );

    add_settings_section(
        'sita_xml_importer_main',
        '',
        '__return_false',
        'sita-xml-importer'
    );

    $users = get_users( [
        'orderby'    => 'ID',
        'order'      => 'ASC',
        'capability' => 'edit_posts',
        'fields'     => [ 'ID', 'user_login' ],
        'number'     => 200,
    ] );

    $select_users = [];
    foreach ( $users as $user ) {
        $select_users[ $user->ID ] = $user->user_login;
    }

    $fields = [
        [
            'id'    => 'xml_feeds',
            'title' => __( 'XML Feed URLs', 'sita-xml-importer' ),
            'type'  => 'textarea',
            'desc'  => __( 'Enter one XML feed URL per line', 'sita-xml-importer' ),
        ],
        [
            'id'      => 'import_interval',
            'title'   => __( 'Import Frequency', 'sita-xml-importer' ),
            'type'    => 'select',
            'default' => '30min',
            'desc'    => __( 'How often to check the feeds. With WP-Cron the actual timing depends on site traffic; for exact intervals, run WordPress cron from a system cron.', 'sita-xml-importer' ),
            'options' => [
                '15min'  => __( 'Every 15 minutes', 'sita-xml-importer' ),
                '30min'  => __( 'Every 30 minutes', 'sita-xml-importer' ),
                'hourly' => __( 'Hourly', 'sita-xml-importer' ),
            ],
        ],
        [
            'id'      => 'xml_user',
            'title'   => __( 'Post Author', 'sita-xml-importer' ),
            'type'    => 'select',
            'desc'    => __( 'Which user should own imported posts?', 'sita-xml-importer' ),
            'options' => $select_users,
        ],
        [
            'id'      => 'post_type',
            'title'   => __( 'Post Type', 'sita-xml-importer' ),
            'type'    => 'select',
            'desc'    => __( 'Which post type to use for imported articles?', 'sita-xml-importer' ),
            'default' => 'post',
            'options' => get_post_types( [ 'public' => true ] ),
        ],
        [
            'id'      => 'xml_status',
            'title'   => __( 'Post Status', 'sita-xml-importer' ),
            'type'    => 'select',
            'desc'    => __( 'Default status for newly imported posts', 'sita-xml-importer' ),
            'default' => 'pending',
            'options' => [
                'publish' => __( 'Publish', 'sita-xml-importer' ),
                'future'  => __( 'Future', 'sita-xml-importer' ),
                'pending' => __( 'Pending', 'sita-xml-importer' ),
            ],
        ],
        [
            'id'      => 'xml_categorysaving',
            'title'   => __( 'Category Saving', 'sita-xml-importer' ),
            'type'    => 'select',
            'desc'    => __( 'Save full category tree or just the lowest level?', 'sita-xml-importer' ),
            'options' => [
                ''          => __( 'Lowest category only', 'sita-xml-importer' ),
                'wholetree' => __( 'Full category tree', 'sita-xml-importer' ),
            ],
        ],
        [
            'id'      => 'allowable_tags',
            'title'   => __( 'Allowed HTML Tags', 'sita-xml-importer' ),
            'type'    => 'text',
            'default' => '<a><strong><em><i><b><br><h1><h2><h3><h4><h5>',
            'desc'    => __( 'HTML tags to preserve in imported content (for strip_tags)', 'sita-xml-importer' ),
        ],
        [
            'id'             => 'verbose_logging',
            'title'          => __( 'Verbose Logging', 'sita-xml-importer' ),
            'type'           => 'checkbox',
            'checkbox_label' => __( 'Store per-article detail (created/updated/skipped) in the run log', 'sita-xml-importer' ),
            'desc'           => __( 'Errors are always logged. Verbose mode also stores every processed article (much more data in the database); enable only for debugging.', 'sita-xml-importer' ),
        ],
        [
            'id'      => 'log_retention_days',
            'title'   => __( 'Log Retention (days)', 'sita-xml-importer' ),
            'type'    => 'number',
            'default' => 180,
            'desc'    => __( 'Import log rows older than this are pruned automatically.', 'sita-xml-importer' ),
        ],
    ];

    foreach ( $fields as $field ) {
        add_settings_field(
            $field['id'],
            $field['title'],
            'sita_xml_importer_render_field',
            'sita-xml-importer',
            'sita_xml_importer_main',
            $field
        );
    }
}

function sita_xml_importer_render_field( $args ) {
    $options = get_option( SITA_XML_IMPORTER_OPTION, [] );
    $id      = $args['id'];
    $value   = $options[ $id ] ?? ( $args['default'] ?? '' );
    $name    = SITA_XML_IMPORTER_OPTION . '[' . $id . ']';

    switch ( $args['type'] ) {
        case 'textarea':
            printf(
                '<textarea rows="5" cols="55" id="%1$s" name="%2$s">%3$s</textarea>',
                esc_attr( $id ),
                esc_attr( $name ),
                esc_textarea( $value )
            );
            break;

        case 'select':
            printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
            foreach ( $args['options'] as $key => $label ) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr( $key ),
                    selected( $value, $key, false ),
                    esc_html( $label )
                );
            }
            echo '</select>';
            break;

        case 'checkbox':
            printf(
                '<label><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> %4$s</label>',
                esc_attr( $id ),
                esc_attr( $name ),
                checked( $value, '1', false ),
                esc_html( $args['checkbox_label'] ?? '' )
            );
            break;

        case 'number':
            printf(
                '<input type="number" min="1" step="1" class="small-text" id="%1$s" name="%2$s" value="%3$s" />',
                esc_attr( $id ),
                esc_attr( $name ),
                esc_attr( $value )
            );
            break;

        default: // text
            printf(
                '<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
                esc_attr( $id ),
                esc_attr( $name ),
                esc_attr( $value )
            );
            break;
    }

    if ( ! empty( $args['desc'] ) ) {
        printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
    }
}

function sita_xml_importer_sanitize_settings( $input ) {
    $sanitized = [];

    if ( isset( $input['xml_feeds'] ) ) {
        $sanitized['xml_feeds'] = sanitize_textarea_field( $input['xml_feeds'] );
    }

    if ( isset( $input['xml_user'] ) ) {
        $sanitized['xml_user'] = absint( $input['xml_user'] );
    }

    if ( isset( $input['post_type'] ) ) {
        $sanitized['post_type'] = sanitize_key( $input['post_type'] );
    }

    if ( isset( $input['xml_status'] ) ) {
        $allowed = [ 'publish', 'future', 'pending' ];
        $sanitized['xml_status'] = in_array( $input['xml_status'], $allowed, true )
            ? $input['xml_status']
            : 'pending';
    }

    if ( isset( $input['xml_categorysaving'] ) ) {
        $sanitized['xml_categorysaving'] = in_array( $input['xml_categorysaving'], [ '', 'wholetree' ], true )
            ? $input['xml_categorysaving']
            : '';
    }

    if ( isset( $input['allowable_tags'] ) ) {
        preg_match_all( '/<[a-z][a-z0-9]*>/i', $input['allowable_tags'], $matches );
        $sanitized['allowable_tags'] = implode( '', $matches[0] ?? [] );
    }

    if ( isset( $input['import_interval'] ) ) {
        $allowed = [ '15min', '30min', 'hourly' ];
        $sanitized['import_interval'] = in_array( $input['import_interval'], $allowed, true ) ? $input['import_interval'] : '30min';
    }

    $sanitized['verbose_logging']    = ! empty( $input['verbose_logging'] ) ? '1' : '';
    $sanitized['log_retention_days'] = isset( $input['log_retention_days'] ) ? max( 1, absint( $input['log_retention_days'] ) ) : 180;

    return $sanitized;
}

/* -------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'sita_xml_importer_admin_menu' );
function sita_xml_importer_admin_menu() {
    add_options_page(
        __( 'SITA XML Importer', 'sita-xml-importer' ),
        __( 'SITA XML Importer', 'sita-xml-importer' ),
        'manage_options',
        'sita-xml-importer',
        'sita_xml_importer_settings_page'
    );
}

/**
 * Seconds before "Import now" can be used again. Filterable; 0 disables it.
 */
function sita_xml_importer_manual_cooldown_seconds() {
    return (int) apply_filters( 'sita_xml_importer_manual_cooldown', 30 );
}

/**
 * Whether the "Import now" cooldown is still in effect. Timestamp-based so it
 * clears correctly where the object cache does not honour transient expiry.
 */
function sita_xml_importer_manual_cooldown_remaining() {
    $since = (int) get_transient( 'sita_xml_importer_manual_cooldown' );
    if ( $since <= 0 ) {
        return 0;
    }
    return max( 0, ( $since + sita_xml_importer_manual_cooldown_seconds() ) - time() );
}

function sita_xml_importer_manual_cooldown_active() {
    return sita_xml_importer_manual_cooldown_remaining() > 0;
}

/**
 * The admin tabs. Core registers Settings, Import log and Maintenance; removable
 * modules can add more (or panels within Maintenance) via the filter/action.
 *
 * @return array<string,array{label:string,render:callable}>
 */
function sita_xml_importer_tabs() {
    $tabs = [
        'settings' => [
            'label'  => __( 'Settings', 'sita-xml-importer' ),
            'render' => 'sita_xml_importer_render_settings_tab',
        ],
        'log' => [
            'label'  => __( 'Import log', 'sita-xml-importer' ),
            'render' => 'sita_xml_importer_render_log_tab',
        ],
        'maintenance' => [
            'label'  => __( 'Maintenance', 'sita-xml-importer' ),
            'render' => 'sita_xml_importer_render_maintenance_tab',
        ],
    ];

    return apply_filters( 'sita_xml_importer_tabs', $tabs );
}

function sita_xml_importer_current_tab() {
    $tabs = sita_xml_importer_tabs();
    $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
    return isset( $tabs[ $tab ] ) ? $tab : 'settings';
}

function sita_xml_importer_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Re-arm the import schedule if it is missing - e.g. a multisite network
    // activation whose hook ran in another site's context.
    sita_xml_importer_ensure_scheduled();

    $tabs    = sita_xml_importer_tabs();
    $current = sita_xml_importer_current_tab();

    echo '<div class="wrap">';
    printf( '<h1>%s</h1>', esc_html__( 'SITA XML Importer', 'sita-xml-importer' ) );

    sita_xml_importer_render_admin_notice();

    echo '<h2 class="nav-tab-wrapper">';
    foreach ( $tabs as $key => $tab ) {
        $url = add_query_arg(
            [ 'page' => 'sita-xml-importer', 'tab' => $key ],
            admin_url( 'options-general.php' )
        );
        printf(
            '<a href="%s" class="nav-tab%s">%s</a>',
            esc_url( $url ),
            $key === $current ? ' nav-tab-active' : '',
            esc_html( $tab['label'] )
        );
    }
    echo '</h2>';

    if ( isset( $tabs[ $current ]['render'] ) && is_callable( $tabs[ $current ]['render'] ) ) {
        call_user_func( $tabs[ $current ]['render'] );
    }

    echo '</div>';
}

function sita_xml_importer_render_settings_tab() {
    echo '<form method="post" action="options.php">';
    settings_fields( SITA_XML_IMPORTER_OPTION );
    do_settings_sections( 'sita-xml-importer' );
    submit_button();
    echo '</form>';
}

function sita_xml_importer_render_log_tab() {
    sita_xml_importer_render_import_now_panel();
    sita_xml_importer_render_log_panel();
    sita_xml_importer_render_export_panel();
}

/**
 * Maintenance tab. Hosts the featured-image status panel and fires an action so
 * optional modules can add their own panels.
 */
function sita_xml_importer_render_maintenance_tab() {
    sita_xml_importer_render_repair_panel();

    /**
     * Add panels to the Maintenance tab. The legacy migration module hooks this
     * to render its data-migration/cleanup panel and old-plugin notice.
     */
    do_action( 'sita_xml_importer_maintenance_tab' );
}

/**
 * Featured-image status panel: a read-only count. Repair runs automatically.
 */
function sita_xml_importer_render_repair_panel() {
    $missing = sita_xml_importer_missing_image_count();

    echo '<h2>' . esc_html__( 'Featured image repair', 'sita-xml-importer' ) . '</h2>';
    echo '<p class="description" style="max-width:640px;">' . esc_html__( 'Missing featured images are re-downloaded automatically after every import, using the image address stored with each article. This also fixes articles that have already scrolled out of the feed, so there is nothing to click here - the count below is for information only.', 'sita-xml-importer' ) . '</p>';

    echo '<p>';
    printf(
        /* translators: %s: number of articles with no featured image */
        esc_html__( 'Articles missing their featured image: %s.', 'sita-xml-importer' ),
        '<strong>' . esc_html( number_format_i18n( $missing ) ) . '</strong>'
    );
    echo '</p>';
}

function sita_xml_importer_render_admin_notice() {
    $code = isset( $_GET['sxi'] ) ? sanitize_key( wp_unslash( $_GET['sxi'] ) ) : '';
    if ( ! $code ) {
        return;
    }

    $messages = [
        'started'  => [ 'updated', __( 'Import started in the background. The result appears in the log below.', 'sita-xml-importer' ) ],
        'done'     => [ 'updated', __( 'Import finished. See the log below.', 'sita-xml-importer' ) ],
        'cooldown' => [ 'notice-warning', __( 'Please wait - an import was triggered recently (cooldown active).', 'sita-xml-importer' ) ],
        'running'  => [ 'notice-warning', __( 'An import is already running.', 'sita-xml-importer' ) ],
    ];

    /**
     * Lets removable modules (e.g. the legacy migration) add their own admin
     * notices without the core admin needing to know about them.
     */
    $messages = apply_filters( 'sita_xml_importer_admin_notices', $messages );

    if ( isset( $messages[ $code ] ) ) {
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr( $messages[ $code ][0] ),
            esc_html( $messages[ $code ][1] )
        );
    }
}

function sita_xml_importer_render_import_now_panel() {
    $cooldown = sita_xml_importer_manual_cooldown_remaining();
    $running  = sita_xml_importer_is_running();
    $disabled = ( $cooldown > 0 || $running );

    echo '<h2>' . esc_html__( 'Import now', 'sita-xml-importer' ) . '</h2>';
    echo '<p class="description">' . esc_html__( 'Runs the importer immediately in the background (it does not block this page). Watch the status and the log below.', 'sita-xml-importer' ) . '</p>';

    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
    wp_nonce_field( 'sita_xml_importer_run_now' );
    echo '<input type="hidden" name="action" value="sita_xml_importer_run_now" />';
    echo '<p><label><input type="checkbox" name="force" value="1" /> ' . esc_html__( 'Overwrite existing articles', 'sita-xml-importer' ) . '</label></p>';
    echo '<p class="description" style="max-width:640px;">' . esc_html__( 'Re-imports every article currently in the feed even if it has not changed - overwriting the title, content, excerpt and categories, and re-downloading a missing featured image. The publish date, author and status are kept. Use this to fix articles whose images did not download or that need refreshing from the source.', 'sita-xml-importer' ) . '</p>';
    $attrs = [ 'id' => 'sxi-import-now' ];
    if ( $disabled ) {
        $attrs['disabled'] = 'disabled';
    }
    submit_button( __( 'Import now', 'sita-xml-importer' ), 'primary', 'submit', false, $attrs );
    echo ' <span id="sxi-cooldown" data-remaining="' . (int) $cooldown . '" style="margin-left:6px;color:#646970;"></span>';
    echo '</form>';

    echo '<p id="sxi-status" style="margin-top:8px;font-weight:600;">';
    echo $running
        ? esc_html__( 'Status: import running…', 'sita-xml-importer' )
        : esc_html__( 'Status: idle', 'sita-xml-importer' );
    echo '</p>';
}

function sita_xml_importer_render_log_panel() {
    $paged   = isset( $_GET['sxi_page'] ) ? max( 1, absint( $_GET['sxi_page'] ) ) : 1;
    $per     = 20;
    $rows    = sita_xml_importer_log_recent( $per, ( $paged - 1 ) * $per );
    $total   = sita_xml_importer_log_total_rows();

    echo '<hr /><h2>' . esc_html__( 'Import log', 'sita-xml-importer' ) . '</h2>';

    if ( ! $rows ) {
        echo '<p>' . esc_html__( 'No import runs recorded yet.', 'sita-xml-importer' ) . '</p>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>';
    foreach ( [
        __( 'Started', 'sita-xml-importer' ),
        __( 'Type', 'sita-xml-importer' ),
        __( 'Status', 'sita-xml-importer' ),
        __( 'Created', 'sita-xml-importer' ),
        __( 'Updated', 'sita-xml-importer' ),
        __( 'Skipped', 'sita-xml-importer' ),
        __( 'Errors', 'sita-xml-importer' ),
        __( 'Details', 'sita-xml-importer' ),
    ] as $heading ) {
        echo '<th>' . esc_html( $heading ) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ( $rows as $row ) {
        $details = json_decode( (string) $row->details, true );
        $errors  = is_array( $details ) ? ( $details['errors'] ?? [] ) : [];
        $items   = is_array( $details ) ? ( $details['items'] ?? [] ) : [];

        echo '<tr>';
        echo '<td>' . esc_html( $row->started_at ) . '</td>';
        echo '<td>' . esc_html( $row->run_type ) . '</td>';
        echo '<td>' . esc_html( $row->status ) . '</td>';
        echo '<td>' . (int) $row->created_count . '</td>';
        echo '<td>' . (int) $row->updated_count . '</td>';
        echo '<td>' . (int) $row->skipped_count . '</td>';
        echo '<td>' . (int) $row->error_count . '</td>';
        echo '<td>';
        if ( $errors || $items ) {
            echo '<details><summary>' . esc_html__( 'view', 'sita-xml-importer' ) . '</summary>';
            if ( $errors ) {
                echo '<strong>' . esc_html__( 'Errors', 'sita-xml-importer' ) . '</strong><ul style="margin:4px 0 8px;">';
                foreach ( $errors as $err ) {
                    echo '<li>' . esc_html( ( $err['title'] ?? '' ) !== '' ? $err['title'] . ' - ' : '' ) . esc_html( $err['message'] ?? '' ) . '</li>';
                }
                echo '</ul>';
            }
            if ( $items ) {
                echo '<strong>' . esc_html__( 'Articles', 'sita-xml-importer' ) . '</strong><ul style="margin:4px 0 8px;">';
                foreach ( $items as $item ) {
                    echo '<li>' . esc_html( ( $item['outcome'] ?? '' ) . ': ' . ( $item['title'] ?? '' ) ) . '</li>';
                }
                echo '</ul>';
            }
            echo '</details>';
        } else {
            echo '&mdash;';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';

    // Pagination - native WP list-table markup (.tablenav-pages with .button
    // arrows), so it uses the core admin styles and matches the dashboard tables
    // with no custom CSS. Prev/next/first/last scales to any page count.
    $pages = (int) ceil( $total / $per );
    if ( $pages > 1 ) {
        $page_link = static function ( $p ) {
            return esc_url( add_query_arg(
                [ 'page' => 'sita-xml-importer', 'tab' => 'log', 'sxi_page' => (int) $p ],
                admin_url( 'options-general.php' )
            ) );
        };

        echo '<div class="tablenav bottom"><div class="tablenav-pages"><span class="pagination-links">';

        if ( $paged <= 1 ) {
            echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span> ';
            echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span> ';
        } else {
            printf(
                '<a class="first-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&laquo;</span></a> ',
                $page_link( 1 ),
                esc_html__( 'First page', 'sita-xml-importer' )
            );
            printf(
                '<a class="prev-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&lsaquo;</span></a> ',
                $page_link( $paged - 1 ),
                esc_html__( 'Previous page', 'sita-xml-importer' )
            );
        }

        printf(
            '<span class="paging-input"><span class="tablenav-paging-text">%s</span></span>',
            sprintf(
                /* translators: 1: current page number, 2: total number of pages */
                esc_html__( '%1$s of %2$s', 'sita-xml-importer' ),
                number_format_i18n( $paged ),
                number_format_i18n( $pages )
            )
        );

        if ( $paged >= $pages ) {
            echo ' <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
            echo ' <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
        } else {
            printf(
                ' <a class="next-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&rsaquo;</span></a>',
                $page_link( $paged + 1 ),
                esc_html__( 'Next page', 'sita-xml-importer' )
            );
            printf(
                ' <a class="last-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">&raquo;</span></a>',
                $page_link( $pages ),
                esc_html__( 'Last page', 'sita-xml-importer' )
            );
        }

        echo '</span></div></div>';
    }
}

function sita_xml_importer_render_export_panel() {
    echo '<hr /><h2>' . esc_html__( 'Export & diagnostics', 'sita-xml-importer' ) . '</h2>';

    // CSV log export (date range) - for spreadsheets.
    echo '<p class="description">' . esc_html__( 'Export runs in a date range as CSV - opens in a spreadsheet.', 'sita-xml-importer' ) . '</p>';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
    wp_nonce_field( 'sita_xml_importer_export' );
    echo '<input type="hidden" name="action" value="sita_xml_importer_export" />';
    echo '<label>' . esc_html__( 'From', 'sita-xml-importer' ) . ' <input type="date" name="from" /></label> ';
    echo '<label>' . esc_html__( 'To', 'sita-xml-importer' ) . ' <input type="date" name="to" /></label> ';
    submit_button( __( 'Export CSV', 'sita-xml-importer' ), 'secondary', 'submit', false );
    echo '</form>';

    // Markdown diagnostic report - for a developer or a coding agent.
    echo '<p class="description" style="margin-top:16px;">' . esc_html__( 'Download a Markdown diagnostic report: environment, database, schema, health checks and recent errors. Ideal for sending to a developer or a coding agent.', 'sita-xml-importer' ) . '</p>';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
    wp_nonce_field( 'sita_xml_importer_diagnostics' );
    echo '<input type="hidden" name="action" value="sita_xml_importer_diagnostics" />';
    submit_button( __( 'Export diagnostic report (.md)', 'sita-xml-importer' ), 'secondary', 'submit', false );
    echo '</form>';
}

/**
 * Enqueue the import-log status script on the plugin's own admin screen only.
 *
 * The script lives in assets/js/admin-status.js and receives its runtime
 * configuration (ajax URL, nonce and translated strings) via wp_localize_script().
 */
add_action( 'admin_enqueue_scripts', 'sita_xml_importer_enqueue_admin_assets' );
function sita_xml_importer_enqueue_admin_assets( $hook_suffix ) {
    if ( 'settings_page_sita-xml-importer' !== $hook_suffix ) {
        return;
    }

    // The status UI only exists on the Import log tab.
    $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
    if ( 'log' !== $tab ) {
        return;
    }

    wp_enqueue_script(
        'sita-xml-importer-admin-status',
        SITA_XML_IMPORTER_URL . 'assets/js/admin-status.js',
        [],
        SITA_XML_IMPORTER_VERSION,
        true
    );

    $just_started = isset( $_GET['sxi'] ) && 'started' === sanitize_key( wp_unslash( $_GET['sxi'] ) );

    wp_localize_script(
        'sita-xml-importer-admin-status',
        'sitaXmlImporterStatus',
        [
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'sita_xml_importer_status' ),
            'idleText'        => __( 'Status: idle', 'sita-xml-importer' ),
            'runningText'     => __( 'Status: import running…', 'sita-xml-importer' ),
            'blockedHintText' => __( 'Import was queued but has not started - this site\'s background tasks (WP-Cron) may be blocked. Run it via WP-CLI or set up a real system cron.', 'sita-xml-importer' ),
            'cooldownText'    => __( 'Available in %s', 'sita-xml-importer' ),
            'justStarted'     => $just_started,
        ]
    );
}

/* -------------------------------------------------------------------------
 * admin-post.php handlers
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_sita_xml_importer_run_now', 'sita_xml_importer_handle_run_now' );
function sita_xml_importer_handle_run_now() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'sita-xml-importer' ) );
    }
    check_admin_referer( 'sita_xml_importer_run_now' );

    $redirect = admin_url( 'options-general.php?page=sita-xml-importer&tab=log' );

    if ( sita_xml_importer_manual_cooldown_active() ) {
        wp_safe_redirect( add_query_arg( 'sxi', 'cooldown', $redirect ) );
        exit;
    }
    if ( sita_xml_importer_is_running() ) {
        wp_safe_redirect( add_query_arg( 'sxi', 'running', $redirect ) );
        exit;
    }

    set_transient( 'sita_xml_importer_manual_cooldown', time(), sita_xml_importer_manual_cooldown_seconds() );

    $force = ! empty( $_POST['force'] );

    if ( sita_xml_importer_should_run_inline() ) {
        // Loopback WP-Cron may be unavailable here, so run one pass inline
        // (bounded by the time budget); any remainder continues on the site's cron.
        sita_xml_importer_cron_handler( 'manual', 0, $force );
        wp_safe_redirect( add_query_arg( 'sxi', 'done', $redirect ) );
        exit;
    }

    wp_schedule_single_event( time(), 'sita_xml_importer_cron', [ 'manual', 0, $force ] );
    spawn_cron();

    wp_safe_redirect( add_query_arg( 'sxi', 'started', $redirect ) );
    exit;
}

/**
 * Whether the manual trigger should run inline rather than via a background
 * WP-Cron event. Defaults to inline when WP-Cron is disabled; filterable so a
 * site with blocked loopback requests can force it on.
 */
function sita_xml_importer_should_run_inline() {
    $disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    return (bool) apply_filters( 'sita_xml_importer_run_inline', $disabled );
}

add_action( 'admin_post_sita_xml_importer_export', 'sita_xml_importer_handle_export' );
function sita_xml_importer_handle_export() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'sita-xml-importer' ) );
    }
    check_admin_referer( 'sita_xml_importer_export' );

    $from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
    $to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';

    sita_xml_importer_log_export_csv( $from, $to );
    exit;
}

/* -------------------------------------------------------------------------
 * AJAX status (polling)
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_sita_xml_importer_status', 'sita_xml_importer_ajax_status' );
function sita_xml_importer_ajax_status() {
    check_ajax_referer( 'sita_xml_importer_status' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error();
    }

    $last = sita_xml_importer_log_last_finished();

    wp_send_json_success( [
        'running' => sita_xml_importer_is_running(),
        'last'    => $last ? [
            'id'      => (int) $last->id,
            'status'  => $last->status,
            'created' => (int) $last->created_count,
            'updated' => (int) $last->updated_count,
            'skipped' => (int) $last->skipped_count,
            'errors'  => (int) $last->error_count,
        ] : null,
    ] );
}
