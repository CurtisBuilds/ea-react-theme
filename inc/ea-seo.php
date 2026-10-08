<?php
/**
 * EA search basics — what Google, Facebook and messaging apps read about a page.
 *
 * Adds to <head> (front-end only):
 *   - <meta name="description">        page excerpt, else a generated line
 *   - Open Graph + Twitter card tags     link previews on Facebook, WhatsApp, iMessage
 *   - JSON-LD Organization + WebSite     on the home page
 *   - JSON-LD Event per program          on a page whose URL is that program's page
 *                                        in the public program feed (city pages)
 *
 * SAFETY
 *   - Never runs on registration / payment pages: any URL starting with /register,
 *     any page holding a WPForms form, the membership page, and every admin,
 *     AJAX, REST, feed or preview request. Those pages get exactly what they had.
 *   - Off switch: Settings → EA Search → untick "Add search tags". Instant, no
 *     theme reinstall. (Or define EA_SEO_DISABLED in wp-config.php.)
 *   - Never blocks a page: the program feed is fetched server-side with a short
 *     timeout and cached; if it fails, the program markup is simply skipped.
 *   - Steps aside if an SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress) is active.
 *   - Read-only: reads the public program feed. Writes nothing except its own
 *     cache and its two settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EA_SEO_FEED_URL' ) ) {
    define( 'EA_SEO_FEED_URL', 'https://curtisbuilds.github.io/ea-programs-json/data/programs.json' );
}

function ea_seo_enabled() {
    if ( defined( 'EA_SEO_DISABLED' ) && EA_SEO_DISABLED ) {
        return false;
    }
    if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ) {
        return false;
    }
    return '1' === (string) get_option( 'ea_seo_enabled', '1' );
}

/**
 * Theme templates that draw the whole page themselves: they never print the
 * page's stored content and contain no form. On these pages, leftover editor
 * content (old Elementor data etc.) is invisible, so it is not inspected.
 */
function ea_seo_content_free_templates() {
    return array(
        'template-city-programs.php',
        'template-city-programs-free-trial.php',
        'template-league-hub.php',
        'template-basketball-guide.php',
        'template-camps.php',
        'template-volleyball.php',
        'template-directory-home.php',
        'template-community-partnerships.php',
    );
}

/** Why this request must be left untouched ('' = go ahead). */
function ea_seo_skip_reason() {
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_preview() || is_customize_preview()
        || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_404() ) {
        return 'not a normal page view';
    }
    $path = strtolower( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ) );
    if ( preg_match( '#^/(register|registration|checkout|membership)#', $path ) || false !== strpos( $path, 'membership' ) ) {
        return 'registration / membership address';
    }
    if ( is_singular() ) {
        $post = get_queried_object();
        if ( $post instanceof WP_Post ) {
            if ( in_array( (string) get_page_template_slug( $post ), ea_seo_content_free_templates(), true ) ) {
                return '';
            }
            // An actual form in the page content: [wpforms] shortcode, WPForms block, or a WPCode insert.
            // (A mention of "WPForms" in a CSS comment is not a form.)
            $content = (string) $post->post_content;
            if ( has_shortcode( $content, 'wpforms' ) || false !== strpos( $content, '<!-- wp:wpforms/' )
                || preg_match( '/\[wpforms[\s\]]/i', $content ) || preg_match( '/\[wpcode[\s\]]/i', $content ) ) {
                return 'page content has a form';
            }
            // Elementor-built pages keep their widgets in post meta.
            $elementor = (string) get_post_meta( $post->ID, '_elementor_data', true );
            if ( '' !== $elementor && false !== stripos( $elementor, 'wpforms' ) ) {
                return 'Elementor content has a form';
            }
        }
    }
    return '';
}

function ea_seo_skip_request() {
    return '' !== ea_seo_skip_reason();
}

// ─── Program feed (read-only, cached) ────────────────────────────────────────

function ea_seo_programs() {
    $cached = get_transient( 'ea_seo_feed' );
    if ( is_array( $cached ) ) {
        return $cached;
    }
    $resp = wp_remote_get( EA_SEO_FEED_URL, array( 'timeout' => 4 ) );
    $list = array();
    if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        $list = is_array( $data ) ? $data : array();
    }
    // Cache failures briefly so a feed outage never slows every page.
    set_transient( 'ea_seo_feed', $list, $list ? 30 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
    return $list;
}

function ea_seo_norm_url( $url ) {
    $p = wp_parse_url( (string) $url );
    if ( empty( $p['host'] ) ) {
        return '';
    }
    $host = preg_replace( '/^www\./', '', strtolower( $p['host'] ) );
    $path = '/' . trim( strtolower( $p['path'] ?? '/' ), '/' );
    return $host . ( '/' === $path ? '/' : $path . '/' );
}

/** Programs whose own page (feed "URL") is the page being viewed. */
function ea_seo_page_programs() {
    $here = ea_seo_norm_url( home_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ) );
    if ( '' === $here || is_front_page() ) {
        return array();
    }
    $out = array();
    foreach ( ea_seo_programs() as $p ) {
        if ( ! is_array( $p ) || empty( $p['URL'] ) || ! empty( $p['is_cancelled'] ) ) {
            continue;
        }
        if ( ea_seo_norm_url( $p['URL'] ) === $here ) {
            $out[] = $p;
        }
    }
    return $out;
}

// ─── Page facts ──────────────────────────────────────────────────────────────

function ea_seo_sport_label() {
    $code = function_exists( 'ea_app_signup_sport' )
        ? ea_app_signup_sport( '', '', home_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ) )
        : '';
    $label = function_exists( 'ea_app_sport_label' ) ? ea_app_sport_label( $code ) : '';
    return '' !== $label ? $label : 'Sports';
}

function ea_seo_clip( $text, $max = 160 ) {
    $text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
    if ( strlen( $text ) <= $max ) {
        return $text;
    }
    $cut = substr( $text, 0, $max - 1 );
    $sp  = strrpos( $cut, ' ' );
    return ( false !== $sp ? substr( $cut, 0, $sp ) : $cut ) . '…';
}

function ea_seo_description( $programs ) {
    if ( is_singular() ) {
        $post = get_queried_object();
        if ( $post instanceof WP_Post && '' !== trim( (string) $post->post_excerpt ) ) {
            return ea_seo_clip( $post->post_excerpt );
        }
    }
    $sport = ea_seo_sport_label();
    if ( $programs ) {
        $city = (string) ( $programs[0]['City'] ?? '' );
        return ea_seo_clip( sprintf(
            '%s programs in %s with Elevation Athletics: %d upcoming %s. See days, times, locations and prices, and register online.',
            $sport, $city, count( $programs ), 1 === count( $programs ) ? 'program' : 'programs'
        ) );
    }
    if ( is_front_page() ) {
        $tag = (string) get_bloginfo( 'description' );
        return ea_seo_clip( sprintf(
            '%s programs, leagues and lessons across Canada with Elevation Athletics. Find a program near you, see dates and prices, and register online.%s',
            $sport, '' !== $tag ? ' ' . $tag : ''
        ) );
    }
    if ( is_singular() ) {
        $post = get_queried_object();
        if ( $post instanceof WP_Post ) {
            $text = ea_seo_clip( strip_shortcodes( $post->post_content ) );
            if ( strlen( $text ) >= 60 ) {
                return $text;
            }
        }
    }
    return ea_seo_clip( sprintf( '%s programs with Elevation Athletics. See upcoming programs and register online.', $sport ) );
}

function ea_seo_image() {
    if ( is_singular() && has_post_thumbnail() ) {
        $img = wp_get_attachment_image_url( get_post_thumbnail_id(), 'large' );
        if ( $img ) {
            return $img;
        }
    }
    $set = trim( (string) get_option( 'ea_seo_og_image', '' ) );
    if ( '' !== $set ) {
        return $set;
    }
    $icon = get_site_icon_url( 512 );
    return $icon ? $icon : '';
}

// ─── Output ──────────────────────────────────────────────────────────────────

function ea_seo_event( $p ) {
    $start = (string) ( $p['Start Date'] ?? '' );
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
        return null;
    }
    $end      = (string) ( $p['End Date'] ?? '' );
    $place    = (string) ( $p['LocationName'] ?? '' );
    $city     = (string) ( $p['City'] ?? '' );
    $province = (string) ( $p['Province'] ?? '' );
    $price    = $p['updated_price'] ?? ( $p['TotalPrice'] ?? null );
    $register = (string) ( $p['RegisterLink'] ?? ( $p['URL'] ?? '' ) );
    $desc     = trim( implode( ' · ', array_filter( array(
        (string) ( $p['Day'] ?? '' ),
        (string) ( $p['Time'] ?? '' ),
        ( isset( $p['MinAge'], $p['MaxAge'] ) && '' !== (string) $p['MinAge'] ) ? 'Ages ' . $p['MinAge'] . ( (int) $p['MaxAge'] >= 99 ? '+' : '–' . $p['MaxAge'] ) : '',
    ) ) ) );

    $event = array(
        '@type'               => 'Event',
        'name'                => (string) ( $p['Title'] ?? '' ),
        'startDate'           => $start,
        'eventStatus'         => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'location'            => array(
            '@type'   => 'Place',
            'name'    => '' !== $place ? $place : $city,
            'address' => array(
                '@type'           => 'PostalAddress',
                'addressLocality' => $city,
                'addressRegion'   => $province,
                'addressCountry'  => 'CA',
            ),
        ),
        'organizer'           => array(
            '@type' => 'Organization',
            'name'  => 'Elevation Athletics',
            'url'   => 'https://elevationathletics.ca/',
        ),
    );
    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
        $event['endDate'] = $end;
    }
    if ( '' !== $desc ) {
        $event['description'] = $desc;
    }
    if ( is_numeric( $price ) && (float) $price > 0 ) {
        $event['offers'] = array(
            '@type'         => 'Offer',
            'price'         => (string) round( (float) $price, 2 ),
            'priceCurrency' => 'CAD',
            'availability'  => ! empty( $p['is_full'] ) ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
            'url'           => $register,
        );
    }
    return $event;
}

function ea_seo_json_ld( $data ) {
    echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}

add_action( 'wp_head', function () {
    if ( ! ea_seo_enabled() ) {
        return;
    }
    $reason = ea_seo_skip_reason();
    if ( '' !== $reason ) {
        // Admins only (never cached, never seen by visitors): why this page has no tags.
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            echo '<!-- EA search tags skipped: ' . esc_html( $reason ) . " -->\n";
        }
        return;
    }
    try {
        $programs = ea_seo_page_programs();
        $desc     = ea_seo_description( $programs );
        $title    = wp_get_document_title();
        $url      = is_singular() ? get_permalink() : home_url( isset( $_SERVER['REQUEST_URI'] ) ? strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '?' ) : '/' );
        $image    = ea_seo_image();
        $site     = get_bloginfo( 'name' );

        echo "\n<!-- EA search tags (Settings → EA Search) -->\n";
        printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $desc ) );
        printf( '<meta property="og:type" content="%s" />' . "\n", is_front_page() ? 'website' : 'article' );
        printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( $site ) );
        printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
        printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $desc ) );
        printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
        printf( '<meta property="og:locale" content="en_CA" />' . "\n" );
        if ( '' !== $image ) {
            printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
        }
        printf( '<meta name="twitter:card" content="%s" />' . "\n", '' !== $image ? 'summary_large_image' : 'summary' );

        if ( is_front_page() ) {
            $logo = get_site_icon_url( 512 );
            $org  = array(
                '@context'     => 'https://schema.org',
                '@type'        => 'SportsOrganization',
                'name'         => 'Elevation Athletics',
                'url'          => 'https://elevationathletics.ca/',
                'email'        => 'info@elevationathletics.ca',
                'areaServed'   => 'CA',
            );
            if ( $logo ) {
                $org['logo'] = $logo;
            }
            ea_seo_json_ld( $org );
            ea_seo_json_ld( array(
                '@context' => 'https://schema.org',
                '@type'    => 'WebSite',
                'name'     => $site,
                'url'      => home_url( '/' ),
            ) );
        }

        $events = array_values( array_filter( array_map( 'ea_seo_event', array_slice( $programs, 0, 30 ) ) ) );
        if ( $events ) {
            ea_seo_json_ld( array( '@context' => 'https://schema.org', '@graph' => $events ) );
        }
        echo "<!-- /EA search tags -->\n";
    } catch ( \Throwable $e ) {
        // Never break a page over search tags. Admins see why.
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            echo '<!-- EA search tags error: ' . esc_html( $e->getMessage() ) . " -->\n";
        }
        return;
    }
}, 2 );

// ─── Settings → EA Search (the off switch) ───────────────────────────────────

add_action( 'admin_menu', function () {
    add_options_page( 'EA Search', 'EA Search', 'manage_options', 'ea-search', 'ea_seo_settings_page' );
} );

add_action( 'admin_post_ea_seo_save', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Not allowed.' );
    }
    check_admin_referer( 'ea_seo_save' );
    update_option( 'ea_seo_enabled', empty( $_POST['ea_seo_enabled'] ) ? '0' : '1' );
    update_option( 'ea_seo_og_image', esc_url_raw( wp_unslash( $_POST['ea_seo_og_image'] ?? '' ) ) );
    delete_transient( 'ea_seo_feed' );
    wp_safe_redirect( admin_url( 'options-general.php?page=ea-search&saved=1' ) );
    exit;
} );

function ea_seo_settings_page() {
    $on    = '1' === (string) get_option( 'ea_seo_enabled', '1' );
    $image = (string) get_option( 'ea_seo_og_image', '' );
    ?>
    <div class="wrap">
        <h1>EA Search</h1>
        <?php if ( ! empty( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Saved. Purge the SiteGround cache to see it on public pages.</p></div><?php endif; ?>
        <p>Page descriptions, link previews and Google program markup. Registration, membership and payment pages are never touched.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="ea_seo_save" />
            <?php wp_nonce_field( 'ea_seo_save' ); ?>
            <table class="form-table">
                <tr><th scope="row">Add search tags</th>
                    <td><label><input type="checkbox" name="ea_seo_enabled" value="1" <?php checked( $on ); ?> /> On (untick to turn everything off)</label></td></tr>
                <tr><th scope="row">Link-preview image</th>
                    <td><input type="url" class="regular-text" name="ea_seo_og_image" value="<?php echo esc_attr( $image ); ?>" placeholder="https://… (1200×630 photo)" />
                    <p class="description">Shown when a page is shared on Facebook, WhatsApp or text. Pages with a featured image use that instead. Blank = site icon.</p></td></tr>
            </table>
            <?php submit_button( 'Save' ); ?>
        </form>
    </div>
    <?php
}
