<?php
/**
 * Elevation Athletics React Theme — functions.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function ea_react_theme_setup() {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', array( 'search-form', 'comment-form', 'gallery', 'caption', 'script', 'style' ) );
    add_theme_support( 'responsive-embeds' );
    add_theme_support( 'align-wide' );
    register_nav_menus( array(
        'primary'            => __( 'Primary Menu', 'ea-react-theme' ),
        'footer_quick_links' => __( 'Footer: Quick Links', 'ea-react-theme' ),
        'footer_more_sports' => __( 'Footer: More Sports', 'ea-react-theme' ),
        'footer_contact'     => __( 'Footer: Contact', 'ea-react-theme' ),
    ) );
}
add_action( 'after_setup_theme', 'ea_react_theme_setup' );

function ea_react_get_menu_items( $location ) {
    $locations = get_nav_menu_locations();
    if ( empty( $locations[ $location ] ) ) return array();
    $menu = wp_get_nav_menu_object( $locations[ $location ] );
    if ( ! $menu || is_wp_error( $menu ) ) return array();
    $items = wp_get_nav_menu_items( $menu->term_id );
    if ( ! $items ) return array();
    return array_map( function( $item ) {
        return array(
            'ID'      => (int) $item->ID,
            // Parent menu-item id (0 for top level). React nests children under
            // their parent to build dropdown submenus.
            'parent'  => (int) $item->menu_item_parent,
            'title'   => $item->title,
            'url'     => $item->url,
            'target'  => $item->target,
            'classes' => implode( ' ', array_filter( (array) $item->classes ) ),
        );
    }, $items );
}

// ─── Design System: enqueue files that actually exist in ea_ds/ ───────────────
function ea_enqueue_ds() {
    $ds_uri = get_template_directory_uri() . '/ea_ds';
    $ds_dir = get_template_directory()     . '/ea_ds';
    $v      = '1.0.0';

    // CSS token files
    wp_enqueue_style( 'ea-fonts',      $ds_uri . '/tokens/fonts.css',      array(),                           $v );
    wp_enqueue_style( 'ea-colors',     $ds_uri . '/tokens/colors.css',     array('ea-fonts'),                 $v );
    wp_enqueue_style( 'ea-typography', $ds_uri . '/tokens/typography.css', array('ea-colors'),                $v );
    wp_enqueue_style( 'ea-spacing',    $ds_uri . '/tokens/spacing.css',    array('ea-colors'),                $v );
    wp_enqueue_style( 'ea-base',       $ds_uri . '/tokens/base.css',       array('ea-typography','ea-spacing'), $v );
    // NOTE: components/fig-tokens.css is referenced by the Figma export but was never
    // shipped in this DS bundle, and nothing uses its --fig* aliases. Enqueueing it only 404s.

    // React UMD (sets window.React and window.ReactDOM)
    wp_enqueue_script( 'react',     'https://unpkg.com/react@18.3.1/umd/react.production.min.js',         array(),        '18.3.1', true );
    wp_enqueue_script( 'react-dom', 'https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js', array('react'), '18.3.1', true );

    // EA Design System component bundle
    // Exposes: window.ElevationAthleticsDesignSystem_58666d.{NavBar, Button, Card, ...}
    $bundle_ver = file_exists( $ds_dir . '/_ds_bundle.js' )
        ? filemtime( $ds_dir . '/_ds_bundle.js' )
        : $v;
    wp_enqueue_script( 'ea-components', $ds_uri . '/_ds_bundle.js', array('react','react-dom'), $bundle_ver, true );
}
add_action( 'wp_enqueue_scripts', 'ea_enqueue_ds' );

// ─── Preload the display webfont so headings render without the swap flash ────
// BBH Bogle is used for every headline; preloading the WOFF2 lets the browser
// fetch it in parallel with CSS instead of waiting until fonts.css is parsed.
// Note: crossorigin is required on font preloads even for same-origin requests,
// otherwise the browser fetches the font twice.
function ea_preload_fonts() {
    $font_uri = get_template_directory_uri() . '/ea_ds/assets/fonts/BBHBogle-Regular.woff2';
    printf(
        '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
        esc_url( $font_uri )
    );
}
add_action( 'wp_head', 'ea_preload_fonts', 1 );

// ─── Main React app (IIFE build — plain <script>, no type="module" needed) ────
function ea_react_enqueue_assets() {
    $main_js  = get_template_directory()     . '/assets/js/main.js';
    $main_uri = get_template_directory_uri() . '/assets/js/main.js';

    if ( ! file_exists( $main_js ) ) return;

    wp_enqueue_style(
        'ea-react-style',
        get_stylesheet_uri(),
        array('ea-base'),
        filemtime( get_stylesheet_directory() . '/style.css' )
    );

    // IIFE output: must load AFTER React + DS bundle (declared as dependencies)
    wp_enqueue_script(
        'ea-react-app',
        $main_uri,
        array('ea-components'),   // guarantees React + DS bundle load first
        filemtime( $main_js ),
        true                      // footer = true
    );

    wp_localize_script(
        'ea-react-app',
        'eaReactData',
        array(
            'siteUrl'  => get_site_url(),
            'themeUrl' => get_template_directory_uri(),
            'apiUrl'   => esc_url_raw( get_rest_url() ),
            // Nonce so logged-in requests to our REST routes are authenticated
            // (sent as the X-WP-Nonce header from the React app).
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'defaults' => array(
                'sport'  => ea_default_sport_value(),
                'region' => ea_default_region_value(),
                'city'   => ea_default_city_value(),
            ),
            'menus'    => array(
                'primary'          => ea_react_get_menu_items( 'primary' ),
                'footerQuickLinks' => ea_react_get_menu_items( 'footer_quick_links' ),
                'footerMoreSports' => ea_react_get_menu_items( 'footer_more_sports' ),
                'footerContact'    => ea_react_get_menu_items( 'footer_contact' ),
            ),
            // Admin-swappable images (Appearance → Customize → EA Images).
            // Empty string = "not set", and React falls back to the bundled asset.
            'images'   => ea_react_images(),
            // Optional links for each image in the New Programs carousel.
            'carouselLinks' => ea_react_carousel_links(),
            // Card and social destinations for the standalone EA directory page.
            'directoryLinks' => ea_react_directory_links(),
            // Community Partnerships page fields (Appearance -> Customize -> EA Partnerships Page).
            'partnerships' => ea_react_partnerships(),
            // Volleyball landing page fields (Appearance -> Customize -> EA Volleyball Page).
            'volleyball' => ea_react_volleyball(),
            // Rep Development Teams page fields (Appearance -> Customize -> EA Rep Development Teams).
            'repDevelopment' => ea_react_rep_development(),
            // Admin-editable marketing copy (Appearance → Customize → EA Text).
            'texts'    => ea_react_texts(),
            // Dedicated Camps page content (Appearance → Customize → EA Camps Page).
            'camps'    => ea_react_camps(),
            // Layout toggles (Appearance → Customize → EA Options).
            'options'  => ea_react_options(),
            // Social profile links (Appearance → Customize → EA Social Links).
            'social'   => ea_react_social(),
            // Button destinations (Appearance → Customize → EA Button Links).
            'links'    => ea_react_links(),
            // FAQ page questions & answers (Appearance → Customize → EA FAQ).
            'faqs'     => ea_react_faqs(),
        )
    );
}
add_action( 'wp_enqueue_scripts', 'ea_react_enqueue_assets' );

// NOTE: No script_loader_tag filter needed — IIFE is a plain script tag.

// ─── Swappable images via the Customizer (Appearance → Customize → EA Images) ──
// Each control stores an image URL as a theme_mod. ea_react_images() collects them
// for wp_localize_script so the React app can read them from window.eaReactData.images.
function ea_react_image_fields() {
    return array(
        'ea_img_logo'        => array( 'key' => 'logo',       'label' => 'Logo (replaces the wordmark in the nav)' ),
        'ea_img_hero'        => array( 'key' => 'hero',       'label' => 'Hero image (desktop)' ),
        'ea_img_hero_mobile' => array( 'key' => 'heroMobile', 'label' => 'Hero image (mobile)' ),
        'ea_img_spotlight_1' => array( 'key' => 'spotlight1', 'label' => 'Spotlight tile 1' ),
        'ea_img_spotlight_2' => array( 'key' => 'spotlight2', 'label' => 'Spotlight tile 2' ),
        'ea_img_spotlight_3' => array( 'key' => 'spotlight3', 'label' => 'Spotlight tile 3' ),
        'ea_img_carousel_1'  => array( 'key' => 'carousel1',  'label' => 'Carousel image 1' ),
        'ea_img_carousel_2'  => array( 'key' => 'carousel2',  'label' => 'Carousel image 2' ),
        'ea_img_carousel_3'  => array( 'key' => 'carousel3',  'label' => 'Carousel image 3' ),
        'ea_img_section'          => array( 'key' => 'sectionImage',         'label' => 'Free Trial / Carousel image' ),
        'ea_img_section_mobile'   => array( 'key' => 'sectionImageMobile',   'label' => 'Free Trial / Carousel image (mobile)' ),
        'ea_img_newsletter'       => array( 'key' => 'newsletter',           'label' => 'Newsletter image' ),
        'ea_img_newsletter_mobile'=> array( 'key' => 'newsletterMobile',     'label' => 'Newsletter image (mobile)' ),
        'ea_img_ball' => array( 'key' => 'ball', 'label' => 'ball' ),
        'ea_img_net' => array( 'key' => 'net', 'label' => 'net' ),
        'ea_img_community_top' => array( 'key' => 'communityTop', 'label' => 'Community top image' ),
        'ea_img_community_left' => array( 'key' => 'communityLeft', 'label' => 'Community left image' ),
        'ea_img_community_right' => array( 'key' => 'communityRight', 'label' => 'Community right image' ),
        'ea_img_league_hub_backdrop' => array( 'key' => 'leagueHubBackdrop', 'label' => 'League Hub — subtle background' ),
        'ea_img_basketball_guide_backdrop' => array( 'key' => 'basketballGuideBackdrop', 'label' => 'Basketball Guide — subtle background' ),
        'ea_img_guide_box_1' => array( 'key' => 'guideBox1', 'label' => 'Basketball Guide — Box 1 image' ),
        'ea_img_guide_box_2' => array( 'key' => 'guideBox2', 'label' => 'Basketball Guide — Box 2 image' ),
        'ea_img_guide_box_3' => array( 'key' => 'guideBox3', 'label' => 'Basketball Guide — Box 3 image' ),
        'ea_img_guide_box_4' => array( 'key' => 'guideBox4', 'label' => 'Basketball Guide — Box 4 image' ),
        'ea_img_guide_box_5' => array( 'key' => 'guideBox5', 'label' => 'Basketball Guide — Box 5 image' ),
        'ea_img_guide_box_6' => array( 'key' => 'guideBox6', 'label' => 'Basketball Guide — Box 6 image' ),
        'ea_img_rep_tryouts_photo_1' => array( 'key' => 'repTryoutsPhoto1', 'label' => 'Basketball Rep Tryouts — Photo 1' ),
        'ea_img_rep_tryouts_photo_2' => array( 'key' => 'repTryoutsPhoto2', 'label' => 'Basketball Rep Tryouts — Photo 2' ),
        'ea_img_rep_tryouts_photo_3' => array( 'key' => 'repTryoutsPhoto3', 'label' => 'Basketball Rep Tryouts — Photo 3' ),
        'ea_img_rep_tryouts_photo_4' => array( 'key' => 'repTryoutsPhoto4', 'label' => 'Basketball Rep Tryouts — Photo 4' ),
        'ea_img_directory_card_1' => array( 'key' => 'directoryCard1', 'label' => 'EA Directory — Card 1 image' ),
        'ea_img_directory_card_2' => array( 'key' => 'directoryCard2', 'label' => 'EA Directory — Card 2 image' ),
        'ea_img_directory_card_3' => array( 'key' => 'directoryCard3', 'label' => 'EA Directory — Card 3 image' ),
        'ea_img_directory_card_4' => array( 'key' => 'directoryCard4', 'label' => 'EA Directory — Card 4 image' ),
        'ea_img_directory_card_5' => array( 'key' => 'directoryCard5', 'label' => 'EA Directory — Card 5 image' ),
        'ea_img_directory_card_6' => array( 'key' => 'directoryCard6', 'label' => 'EA Directory — Card 6 image' ),
        'ea_img_directory_card_7' => array( 'key' => 'directoryCard7', 'label' => 'EA Directory — Card 7 image' ),
        'ea_img_directory_card_8' => array( 'key' => 'directoryCard8', 'label' => 'EA Directory — Card 8 image' ),
        'ea_img_directory_card_9' => array( 'key' => 'directoryCard9', 'label' => 'EA Directory — Card 9 image' ),
        'ea_img_camp_card_1' => array( 'key' => 'campCard1', 'label' => 'Camps — Card 1 image' ),
        'ea_img_camp_card_2' => array( 'key' => 'campCard2', 'label' => 'Camps — Card 2 image' ),
        'ea_img_camp_card_3' => array( 'key' => 'campCard3', 'label' => 'Camps — Card 3 image' ),
        'ea_img_camp_card_4' => array( 'key' => 'campCard4', 'label' => 'Camps — Card 4 image' ),
    );
}

function ea_react_images() {
    $images = array();
    foreach ( ea_react_image_fields() as $setting => $meta ) {
        $images[ $meta['key'] ] = esc_url( get_theme_mod( $setting, '' ) );
    }
    return $images;
}

function ea_customize_images( $wp_customize ) {
    $wp_customize->add_section( 'ea_images', array(
        'title'       => __( 'EA Images', 'ea-react-theme' ),
        'description' => __( 'Upload images used across the site. Leave blank to use the theme defaults.', 'ea-react-theme' ),
        'priority'    => 30,
    ) );

    foreach ( ea_react_image_fields() as $setting => $meta ) {
        if ( 0 === strpos( $setting, 'ea_img_camp_card_' ) ) {
            continue;
        }

        $wp_customize->add_setting( $setting, array(
            'default'           => '',
            'sanitize_callback' => 'esc_url_raw',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, $setting, array(
            'label'    => $meta['label'],
            'section'  => 'ea_images',
            'settings' => $setting,
        ) ) );
    }
}
add_action( 'customize_register', 'ea_customize_images' );

// ─── Optional carousel image links (Appearance → Customize → EA Carousel Links) ─
function ea_react_carousel_link_fields() {
    return array(
        'ea_carousel_link_1' => array( 'key' => 'carousel1', 'label' => 'Carousel image 1 link' ),
        'ea_carousel_link_2' => array( 'key' => 'carousel2', 'label' => 'Carousel image 2 link' ),
        'ea_carousel_link_3' => array( 'key' => 'carousel3', 'label' => 'Carousel image 3 link' ),
    );
}

function ea_react_carousel_links() {
    $links = array();
    foreach ( ea_react_carousel_link_fields() as $setting => $meta ) {
        $links[ $meta['key'] ] = esc_url( get_theme_mod( $setting, '' ) );
    }
    return $links;
}

function ea_customize_carousel_links( $wp_customize ) {
    $wp_customize->add_section( 'ea_carousel_links', array(
        'title'       => __( 'EA Carousel Links', 'ea-react-theme' ),
        'description' => __( 'Optional destination URLs for each New Programs carousel image. Leave blank to keep that image unlinked.', 'ea-react-theme' ),
        'priority'    => 31,
    ) );

    foreach ( ea_react_carousel_link_fields() as $setting => $meta ) {
        $wp_customize->add_setting( $setting, array(
            'default'           => '',
            'sanitize_callback' => 'esc_url_raw',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( $setting, array(
            'type'    => 'url',
            'label'   => $meta['label'],
            'section' => 'ea_carousel_links',
        ) );
    }
}
add_action( 'customize_register', 'ea_customize_carousel_links' );

// ─── Directory page card + social links (Appearance → Customize → EA Directory Links) ─
function ea_react_directory_link_fields() {
    $fields = array();
    $cards = array(
        1 => array( 'label' => 'Basketball', 'url' => 'https://elevationathletics.ca/home/' ),
        2 => array( 'label' => 'Pickleball', 'url' => 'https://eapickleball.com/' ),
        3 => array( 'label' => 'Badminton', 'url' => 'https://eabadminton.com/' ),
        4 => array( 'label' => 'Annual Membership', 'url' => 'https://elevationathletics.ca/annual-membership/' ),
        9 => array( 'label' => 'Volleyball', 'url' => 'https://elevationathletics.ca/volleyball/' ),
        5 => array( 'label' => 'Become a Coach', 'url' => 'https://elevationathletics.ca/join-our-team/' ),
        6 => array( 'label' => 'Become a Community Leader', 'url' => 'https://elevationathletics.ca/become-a-community-leader/' ),
        7 => array( 'label' => 'Community Partnerships', 'url' => 'https://elevationathletics.ca/partnerships/' ),
        8 => array( 'label' => 'Work With Us', 'url' => 'https://elevationathletics.ca/join-our-team/' ),
    );

    foreach ( $cards as $index => $card ) {
        if ( 4 === $index ) {
            $setting_base = 'ea_directory_annual_membership';
        } elseif ( 6 === $index ) {
            $setting_base = 'ea_directory_community_leader';
        } else {
            $setting_base = "ea_directory_card_{$index}";
        }

        $fields[ "{$setting_base}_url" ] = array(
            'key'     => "card{$index}Url",
            'label'   => sprintf( 'EA Directory — %s image link', $card['label'] ),
            'default' => $card['url'],
        );
        $fields[ "{$setting_base}_instagram" ] = array(
            'key'     => "card{$index}Instagram",
            'label'   => sprintf( 'EA Directory — %s Instagram link', $card['label'] ),
            'default' => '',
        );
        $fields[ "{$setting_base}_facebook" ] = array(
            'key'     => "card{$index}Facebook",
            'label'   => sprintf( 'EA Directory — %s Facebook link', $card['label'] ),
            'default' => '',
        );
    }

    return $fields;
}

function ea_react_directory_links() {
    $links = array();
    foreach ( ea_react_directory_link_fields() as $setting => $meta ) {
        $links[ $meta['key'] ] = esc_url( get_theme_mod( $setting, $meta['default'] ) );
    }
    return $links;
}

function ea_customize_directory_links( $wp_customize ) {
    $wp_customize->add_section( 'ea_directory_links', array(
        'title'       => __( 'EA Directory Links', 'ea-react-theme' ),
        'description' => __( 'Card, image, Instagram, and Facebook links for the standalone Elevation Athletics directory page.', 'ea-react-theme' ),
        'priority'    => 36,
    ) );

    foreach ( ea_react_directory_link_fields() as $setting => $meta ) {
        $wp_customize->add_setting( $setting, array(
            'default'           => $meta['default'],
            'sanitize_callback' => 'esc_url_raw',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( $setting, array(
            'type'    => 'url',
            'label'   => $meta['label'],
            'section' => 'ea_directory_links',
        ) );
    }
}
add_action( 'customize_register', 'ea_customize_directory_links' );

// ─── Community Partnerships page (Appearance → Customize → EA Partnerships Page) ─
function ea_partnership_field_defaults() {
    return array(
        'ea_partnership_hero_heading' => array(
            'key' => 'heroHeading', 'label' => 'Hero — Heading', 'type' => 'text',
            'default' => 'Community Partnership Programs',
        ),
        'ea_partnership_hero_lead' => array(
            'key' => 'heroLead', 'label' => 'Hero — Lead text', 'type' => 'textarea',
            'default' => 'Bring inclusive, high-quality sport programming to your community with a team that can help plan, promote, and deliver it.',
        ),
        'ea_partnership_hero_body' => array(
            'key' => 'heroBody', 'label' => 'Hero — Body text', 'type' => 'textarea',
            'default' => 'Elevation Athletics partners with township and municipal recreation teams to offer accessible basketball, pickleball, badminton, and seasonal sport programs for families across Canada.',
        ),
        'ea_partnership_cta_label' => array(
            'key' => 'ctaLabel', 'label' => 'CTA — Button text', 'type' => 'text',
            'default' => 'Partner With Us',
        ),
        'ea_partnership_cta_url' => array(
            'key' => 'ctaUrl', 'label' => 'CTA — Button URL', 'type' => 'url',
            'default' => 'mailto:municipal@elevationathletics.ca',
        ),
        'ea_partnership_map_markers' => array(
            'key' => 'mapMarkers', 'label' => 'Map — fallback markers', 'type' => 'textarea',
            'description' => 'The page normally uses programs.json rows where Category = TS. These fallback markers are used only if the live feed is unavailable. One marker per line: Label | latitude | longitude',
            'default' => "Aurora, ON|44.0065|-79.4504\nBarrie, ON|44.3894|-79.6903\nBradford, ON|44.1113|-79.5614\nCambridge, ON|43.3436|-80.3063\nEast Gwillimbury, ON|44.1279|-79.4518\nEssa, ON|44.3138|-79.8846\nGeorgina, ON|44.2963|-79.4360\nInnisfil, ON|44.3001|-79.6117\nKing City, ON|43.9285|-79.5269\nNewmarket, ON|44.0592|-79.4613\nOro-Medonte, ON|44.5590|-79.6545\nRichmond Hill, ON|43.8687|-79.4352\nVaughan, ON|43.8563|-79.5085",
        ),
        'ea_partnership_programs_heading' => array(
            'key' => 'programsHeading', 'label' => 'Program cards — Heading', 'type' => 'text',
            'default' => 'Programs We Can Bring to Your Community',
        ),
        'ea_partnership_programs_intro' => array(
            'key' => 'programsIntro', 'label' => 'Program cards — Intro text', 'type' => 'textarea',
            'default' => 'Choose the sports and formats that match your community. Each card can be edited, linked, or removed from the Customizer.',
        ),
        'ea_partnership_program_rows' => array(
            'key' => 'programRows', 'label' => 'Program cards', 'type' => 'textarea',
            'description' => 'One card per line: Title | description | optional link URL | optional image URL. Remove a line to hide that card.',
            'default' => "Basketball|Youth lessons, house leagues, rep pathways, camps, and community events.|https://elevationathletics.ca/home|\nPickleball|Adult and youth lessons, beginner clinics, leagues, and social play.|https://eapickleball.com|\nBadminton|Introductory lessons, junior leagues, camps, and seasonal programs.|https://eabadminton.com|\nSeasonal Sport Programs|Flexible pilot programs built around your community space, schedule, and demand.||",
        ),
        'ea_partnership_process_heading' => array(
            'key' => 'processHeading', 'label' => 'How it works — Heading', 'type' => 'text',
            'default' => 'How We Do It',
        ),
        'ea_partnership_process_intro' => array(
            'key' => 'processIntro', 'label' => 'How it works — Intro text', 'type' => 'textarea',
            'default' => 'We make it easier for municipal teams to add quality sport programming without building everything from scratch.',
        ),
        'ea_partnership_process_rows' => array(
            'key' => 'processRows', 'label' => 'How it works — Steps', 'type' => 'textarea',
            'description' => 'One step per line: Step title | description.',
            'default' => "Plan|We align with your recreation team on facility access, age groups, registration goals, and seasonal timing.\nPromote|We help position the program clearly for families with simple registration paths and polished program assets.\nDeliver|Our team manages coaching, programming, communication, and on-site delivery so the experience feels organized from day one.",
        ),
        'ea_partnership_final_heading' => array(
            'key' => 'finalHeading', 'label' => 'Final CTA — Heading', 'type' => 'text',
            'default' => 'Bring Sports to Your Community',
        ),
        'ea_partnership_final_body' => array(
            'key' => 'finalBody', 'label' => 'Final CTA — Body text', 'type' => 'textarea',
            'default' => 'If you are part of a township or municipal recreation department, reach out and we can talk through what a partnership could look like.',
        ),
    );
}

function ea_sanitize_partnership_url( $value ) {
    return esc_url_raw( $value, array( 'http', 'https', 'mailto' ) );
}

function ea_react_partnerships() {
    $values = array();
    foreach ( ea_partnership_field_defaults() as $setting => $meta ) {
        $value = get_theme_mod( $setting, $meta['default'] );
        if ( 'url' === $meta['type'] ) {
            $value = ea_sanitize_partnership_url( $value );
        }
        $values[ $meta['key'] ] = $value;
    }
    return $values;
}

function ea_customize_partnerships( $wp_customize ) {
    $wp_customize->add_section( 'ea_partnerships_page', array(
        'title'       => __( 'EA Partnerships Page', 'ea-react-theme' ),
        'description' => __( 'Edit the standalone municipal/community partnerships page.', 'ea-react-theme' ),
        'priority'    => 35,
    ) );

    foreach ( ea_partnership_field_defaults() as $setting => $meta ) {
        $sanitize = 'url' === $meta['type']
            ? 'ea_sanitize_partnership_url'
            : ( 'textarea' === $meta['type'] ? 'sanitize_textarea_field' : 'sanitize_text_field' );

        $wp_customize->add_setting( $setting, array(
            'default'           => $meta['default'],
            'sanitize_callback' => $sanitize,
            'transport'         => 'refresh',
        ) );

        $wp_customize->add_control( $setting, array(
            'type'        => $meta['type'],
            'label'       => $meta['label'],
            'description' => isset( $meta['description'] ) ? $meta['description'] : '',
            'section'     => 'ea_partnerships_page',
            'settings'    => $setting,
        ) );
    }
}
add_action( 'customize_register', 'ea_customize_partnerships' );

// ─── Volleyball page (Appearance → Customize → EA Volleyball Page) ───────────
function ea_volleyball_field_defaults() {
    return array(
        'ea_volleyball_logo_image' => array(
            'key' => 'logoImage', 'label' => 'Header/footer — Logo image', 'type' => 'image',
            'default' => '',
        ),
        'ea_volleyball_logo_href' => array(
            'key' => 'logoHref', 'label' => 'Header/footer — Logo link', 'type' => 'url',
            'default' => 'https://elevationathletics.ca/',
        ),
        'ea_volleyball_nav_rows' => array(
            'key' => 'navRows', 'label' => 'Header — Navigation links', 'type' => 'textarea',
            'description' => 'One link per line: Label | URL. Use #volleyball-programs for the programs section.',
            'default' => "Programs|#volleyball-programs\nAll Sports|https://elevationathletics.ca/",
        ),
        'ea_volleyball_nav_button_label' => array(
            'key' => 'navButtonLabel', 'label' => 'Header — Button text', 'type' => 'text',
            'default' => 'View Programs',
        ),
        'ea_volleyball_nav_button_url' => array(
            'key' => 'navButtonUrl', 'label' => 'Header — Button URL', 'type' => 'url',
            'default' => '#volleyball-programs',
        ),
        'ea_volleyball_nav_contact_label' => array(
            'key' => 'navContactLabel', 'label' => 'Header — Contact link text', 'type' => 'text',
            'default' => 'Contact',
        ),
        'ea_volleyball_hero_image' => array(
            'key' => 'heroImage', 'label' => 'Hero — Optional image', 'type' => 'image',
            'default' => '',
        ),
        'ea_volleyball_hero_heading' => array(
            'key' => 'heroHeading', 'label' => 'Hero — Heading', 'type' => 'text',
            'default' => 'Play Volleyball in Canada',
        ),
        'ea_volleyball_hero_subheading' => array(
            'key' => 'heroSubheading', 'label' => 'Hero — Subheading', 'type' => 'textarea',
            'default' => 'Find youth volleyball programs run by Elevation Athletics and our community partners. Browse upcoming sessions by city and register for the program that fits your schedule.',
        ),
        'ea_volleyball_primary_button_label' => array(
            'key' => 'primaryButtonLabel', 'label' => 'Hero — Primary button text', 'type' => 'text',
            'default' => 'View All Programs',
        ),
        'ea_volleyball_primary_button_url' => array(
            'key' => 'primaryButtonUrl', 'label' => 'Hero — Primary button URL', 'type' => 'url',
            'default' => '#volleyball-programs',
        ),
        'ea_volleyball_secondary_button_label' => array(
            'key' => 'secondaryButtonLabel', 'label' => 'Hero — Secondary button text', 'type' => 'text',
            'default' => 'Community Partnerships',
        ),
        'ea_volleyball_secondary_button_url' => array(
            'key' => 'secondaryButtonUrl', 'label' => 'Hero — Secondary button URL', 'type' => 'url',
            'default' => 'https://elevationathletics.ca/partnerships/',
        ),
        'ea_volleyball_programs_heading' => array(
            'key' => 'programsHeading', 'label' => 'Programs — Heading', 'type' => 'text',
            'default' => 'Our Active Volleyball Programs',
        ),
        'ea_volleyball_programs_intro' => array(
            'key' => 'programsIntro', 'label' => 'Programs — Intro text', 'type' => 'textarea',
            'default' => 'Check out the volleyball programs coming up soon and register for the session that fits your schedule.',
        ),
        'ea_volleyball_empty_heading' => array(
            'key' => 'emptyHeading', 'label' => 'Programs — Empty state heading', 'type' => 'text',
            'default' => 'No active volleyball programs are listed right now.',
        ),
        'ea_volleyball_empty_text' => array(
            'key' => 'emptyText', 'label' => 'Programs — Empty state text', 'type' => 'textarea',
            'default' => 'Please check back soon for new volleyball programming.',
        ),
        'ea_volleyball_footer_quick_heading' => array(
            'key' => 'footerQuickHeading', 'label' => 'Footer — Quick links heading', 'type' => 'text',
            'default' => 'Quick Links',
        ),
        'ea_volleyball_footer_quick_rows' => array(
            'key' => 'footerQuickRows', 'label' => 'Footer — Quick links', 'type' => 'textarea',
            'description' => 'One link per line: Label | URL.',
            'default' => "Volleyball Programs|#volleyball-programs\nCommunity Partnerships|https://elevationathletics.ca/partnerships/",
        ),
        'ea_volleyball_footer_sports_heading' => array(
            'key' => 'footerSportsHeading', 'label' => 'Footer — More sports heading', 'type' => 'text',
            'default' => 'More Sports',
        ),
        'ea_volleyball_footer_sports_rows' => array(
            'key' => 'footerSportsRows', 'label' => 'Footer — More sports links', 'type' => 'textarea',
            'description' => 'One link per line: Label | URL.',
            'default' => "Basketball|https://elevationathletics.ca/home/\nBadminton|https://eabadminton.com/\nPickleball|https://eapickleball.com/",
        ),
        'ea_volleyball_footer_social_heading' => array(
            'key' => 'footerSocialHeading', 'label' => 'Footer — Social links heading', 'type' => 'text',
            'default' => 'Follow us on our socials!',
        ),
        'ea_volleyball_footer_social_rows' => array(
            'key' => 'footerSocialRows', 'label' => 'Footer — Social links', 'type' => 'textarea',
            'description' => 'Optional. One link per line: Instagram | URL or Facebook | URL. Leave blank to hide social links on this page.',
            'default' => '',
        ),
        'ea_volleyball_footer_contact_rows' => array(
            'key' => 'footerContactRows', 'label' => 'Footer — Contact links', 'type' => 'textarea',
            'description' => 'One link per line: Label | URL.',
            'default' => 'info@elevationathletics.ca|mailto:info@elevationathletics.ca',
        ),
    );
}

function ea_sanitize_volleyball_url( $value ) {
    $value = trim( (string) $value );
    if ( '' === $value ) {
        return '';
    }
    if ( preg_match( '/^#[A-Za-z][A-Za-z0-9_-]*$/', $value ) ) {
        return $value;
    }
    return esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
}

function ea_react_volleyball() {
    $values = array();
    foreach ( ea_volleyball_field_defaults() as $setting => $meta ) {
        $value = get_theme_mod( $setting, $meta['default'] );
        if ( 'url' === $meta['type'] || 'image' === $meta['type'] ) {
            $value = ea_sanitize_volleyball_url( $value );
        }
        $values[ $meta['key'] ] = $value;
    }
    return $values;
}

function ea_customize_volleyball( $wp_customize ) {
    $wp_customize->add_section( 'ea_volleyball_page', array(
        'title'       => __( 'EA Volleyball Page', 'ea-react-theme' ),
        'description' => __( 'Edit the standalone volleyball landing/programs page.', 'ea-react-theme' ),
        'priority'    => 36,
    ) );

    foreach ( ea_volleyball_field_defaults() as $setting => $meta ) {
        $sanitize = 'url' === $meta['type'] || 'image' === $meta['type']
            ? 'ea_sanitize_volleyball_url'
            : ( 'textarea' === $meta['type'] ? 'sanitize_textarea_field' : 'sanitize_text_field' );

        $wp_customize->add_setting( $setting, array(
            'default'           => $meta['default'],
            'sanitize_callback' => $sanitize,
            'transport'         => 'refresh',
        ) );

        $control_args = array(
            'label'       => $meta['label'],
            'description' => isset( $meta['description'] ) ? $meta['description'] : '',
            'section'     => 'ea_volleyball_page',
            'settings'    => $setting,
        );

        if ( 'image' === $meta['type'] ) {
            $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, $setting, $control_args ) );
        } else {
            $wp_customize->add_control( $setting, array_merge( $control_args, array(
                'type' => $meta['type'],
            ) ) );
        }
    }
}
add_action( 'customize_register', 'ea_customize_volleyball' );

function ea_rep_development_field_defaults() {
    return array(
        'ea_rep_development_accent_color' => array(
            'key' => 'accentColor', 'label' => 'Page — Hero background colour', 'type' => 'color',
            'default' => '#0A98D6',
        ),
        'ea_rep_development_heading' => array(
            'key' => 'heading', 'label' => 'Hero — Heading', 'type' => 'text',
            'default' => 'Newmarket Rep Development Teams',
        ),
        'ea_rep_development_subheading' => array(
            'key' => 'subheading', 'label' => 'Hero — Subheading', 'type' => 'textarea',
            'default' => 'Structured team training for athletes preparing for the next level of competitive basketball.',
        ),
        'ea_rep_development_primary_button_label' => array(
            'key' => 'primaryButtonLabel', 'label' => 'Hero — Button text', 'type' => 'text',
            'default' => 'Register Interest',
        ),
        'ea_rep_development_primary_button_url' => array(
            'key' => 'primaryButtonUrl', 'label' => 'Hero — Button URL', 'type' => 'url',
            'default' => '#rep-development-registration',
        ),
        'ea_rep_development_about_heading' => array(
            'key' => 'aboutHeading', 'label' => 'About — Heading', 'type' => 'text',
            'default' => 'Development-Focused Team Training',
        ),
        'ea_rep_development_about_body' => array(
            'key' => 'aboutBody', 'label' => 'About — Body text', 'type' => 'textarea',
            'default' => 'EA Rep Development Teams give motivated athletes a structured environment to build skills, habits, confidence, and team concepts before stepping into higher levels of competition.' . "\n\n" . 'Players train with experienced coaches, learn the standards expected in competitive basketball, and develop alongside athletes who are serious about improving.' . "\n\n" . 'This pathway is built for athletes who want more than recreational programming, but may still be preparing for full rep team competition.',
        ),
        'ea_rep_development_photo_1' => array(
            'key' => 'photo1', 'label' => 'About — Photo 1', 'type' => 'image',
            'default' => '',
        ),
        'ea_rep_development_photo_1_caption' => array(
            'key' => 'photo1Caption', 'label' => 'About — Photo 1 caption', 'type' => 'text',
            'default' => '',
        ),
        'ea_rep_development_photo_2' => array(
            'key' => 'photo2', 'label' => 'About — Photo 2', 'type' => 'image',
            'default' => '',
        ),
        'ea_rep_development_photo_2_caption' => array(
            'key' => 'photo2Caption', 'label' => 'About — Photo 2 caption', 'type' => 'text',
            'default' => '',
        ),
        'ea_rep_development_photo_3' => array(
            'key' => 'photo3', 'label' => 'About — Photo 3', 'type' => 'image',
            'default' => '',
        ),
        'ea_rep_development_photo_3_caption' => array(
            'key' => 'photo3Caption', 'label' => 'About — Photo 3 caption', 'type' => 'text',
            'default' => '',
        ),
        'ea_rep_development_photo_4' => array(
            'key' => 'photo4', 'label' => 'About — Photo 4', 'type' => 'image',
            'default' => '',
        ),
        'ea_rep_development_photo_4_caption' => array(
            'key' => 'photo4Caption', 'label' => 'About — Photo 4 caption', 'type' => 'text',
            'default' => '',
        ),
        'ea_rep_development_registration_heading' => array(
            'key' => 'registrationHeading', 'label' => 'Registration — Heading', 'type' => 'text',
            'default' => 'Tryout Registration',
        ),
        'ea_rep_development_registration_body' => array(
            'key' => 'registrationBody', 'label' => 'Registration — Body text', 'type' => 'textarea',
            'default' => 'Register for the first Newmarket Rep Development Teams tryout. Complete the form and our team will follow up with next steps, team details, and any updates families need before attending.' . "\n\n" . 'Development team placement may depend on athlete age, experience, availability, and roster needs.',
        ),
        'ea_rep_development_tryout_heading' => array(
            'key' => 'tryoutHeading', 'label' => 'Tryout schedule 1 — Heading', 'type' => 'text',
            'default' => 'Newmarket Rep Development Teams Tryout',
        ),
        'ea_rep_development_tryout_body' => array(
            'key' => 'tryoutBody', 'label' => 'Tryout schedule 1 — Intro text', 'type' => 'textarea',
            'default' => 'The first tryout will take place at TUC in Newmarket. Please register through the form on this page before attending.',
        ),
        'ea_rep_development_tryout_rows' => array(
            'key' => 'tryoutRows', 'label' => 'Tryout schedule 1 — Table rows', 'type' => 'textarea',
            'description' => 'One row per line: Date | Time | Team/program | Location.',
            'default' => 'Sept 25 | 6:00-8:00PM | U12 | TUC',
        ),
        'ea_rep_development_tryout_second_heading' => array(
            'key' => 'tryoutSecondHeading', 'label' => 'Tryout schedule 2 — Heading', 'type' => 'text',
            'default' => 'Richmond Hill Rep Development Teams Tryout',
        ),
        'ea_rep_development_tryout_second_body' => array(
            'key' => 'tryoutSecondBody', 'label' => 'Tryout schedule 2 — Intro text', 'type' => 'textarea',
            'default' => '',
        ),
        'ea_rep_development_tryout_second_rows' => array(
            'key' => 'tryoutSecondRows', 'label' => 'Tryout schedule 2 — Table rows', 'type' => 'textarea',
            'description' => 'One row per line: Date | Time | Team/program | Location.',
            'default' => 'Oct 1 | 6:00-8:00PM | U12 | Holy Name - Richmond Hill',
        ),
        'ea_rep_development_tryout_third_heading' => array(
            'key' => 'tryoutThirdHeading', 'label' => 'Tryout schedule 3 — Heading', 'type' => 'text',
            'default' => 'Simcoe',
        ),
        'ea_rep_development_tryout_third_body' => array(
            'key' => 'tryoutThirdBody', 'label' => 'Tryout schedule 3 — Intro text', 'type' => 'textarea',
            'default' => '',
        ),
        'ea_rep_development_tryout_third_rows' => array(
            'key' => 'tryoutThirdRows', 'label' => 'Tryout schedule 3 — Table rows', 'type' => 'textarea',
            'description' => 'One row per line: Date | Time | Team/program | Location.',
            'default' => '',
        ),
        'ea_rep_development_waiver_button_label' => array(
            'key' => 'waiverButtonLabel', 'label' => 'Registration — Waiver button text', 'type' => 'text',
            'default' => 'Download Waiver',
        ),
        'ea_rep_development_waiver_button_url' => array(
            'key' => 'waiverButtonUrl', 'label' => 'Registration — Waiver button URL', 'type' => 'url',
            'description' => 'Paste the waiver PDF or page URL. Leave blank to hide the waiver button.',
            'default' => '',
        ),
        'ea_rep_development_form_shortcode' => array(
            'key' => 'formShortcode', 'label' => 'Registration — Form shortcode', 'type' => 'textarea',
            'description' => 'Paste the WPForms shortcode for the Rep Development Teams form.',
            'default' => '',
        ),
        'ea_rep_development_locations_heading' => array(
            'key' => 'locationsHeading', 'label' => 'Locations — Heading', 'type' => 'text',
            'default' => 'Locations',
        ),
        'ea_rep_development_map_1_heading' => array(
            'key' => 'map1Heading', 'label' => 'Map 1 — Heading', 'type' => 'text',
            'default' => 'Primary Training Location',
        ),
        'ea_rep_development_map_1_embed' => array(
            'key' => 'map1Embed', 'label' => 'Map 1 — Embed src URL', 'type' => 'map',
            'default' => '',
        ),
        'ea_rep_development_map_2_heading' => array(
            'key' => 'map2Heading', 'label' => 'Map 2 — Heading', 'type' => 'text',
            'default' => 'Secondary Training Location',
        ),
        'ea_rep_development_map_2_embed' => array(
            'key' => 'map2Embed', 'label' => 'Map 2 — Embed src URL', 'type' => 'map',
            'default' => '',
        ),
    );
}

function ea_sanitize_rep_development_url( $value ) {
    $value = trim( (string) $value );
    if ( '' === $value ) {
        return '';
    }
    if ( preg_match( '/^#[A-Za-z][A-Za-z0-9_-]*$/', $value ) ) {
        return $value;
    }
    return esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
}

function ea_sanitize_rep_development_color( $value ) {
    $sanitized = sanitize_hex_color( $value );
    return $sanitized ? $sanitized : '#0A98D6';
}

function ea_react_rep_development() {
    $values = array();
    foreach ( ea_rep_development_field_defaults() as $setting => $meta ) {
        $value = get_theme_mod( $setting, $meta['default'] );
        if ( 'url' === $meta['type'] || 'image' === $meta['type'] ) {
            $value = ea_sanitize_rep_development_url( $value );
        } elseif ( 'color' === $meta['type'] ) {
            $value = ea_sanitize_rep_development_color( $value );
        } elseif ( 'map' === $meta['type'] ) {
            $value = ea_sanitize_map_embed_field( $value );
        }
        $values[ $meta['key'] ] = $value;
    }
    return $values;
}

function ea_customize_rep_development( $wp_customize ) {
    $wp_customize->add_section( 'ea_rep_development_page', array(
        'title'       => __( 'EA Rep Development Teams', 'ea-react-theme' ),
        'description' => __( 'Edit the standalone Rep Development Teams page.', 'ea-react-theme' ),
        'priority'    => 37,
    ) );

    foreach ( ea_rep_development_field_defaults() as $setting => $meta ) {
        if ( 'url' === $meta['type'] || 'image' === $meta['type'] ) {
            $sanitize = 'ea_sanitize_rep_development_url';
        } elseif ( 'color' === $meta['type'] ) {
            $sanitize = 'ea_sanitize_rep_development_color';
        } elseif ( 'map' === $meta['type'] ) {
            $sanitize = 'ea_sanitize_map_embed_field';
        } else {
            $sanitize = ( 'textarea' === $meta['type'] ) ? 'sanitize_textarea_field' : 'sanitize_text_field';
        }

        $wp_customize->add_setting( $setting, array(
            'default'           => $meta['default'],
            'sanitize_callback' => $sanitize,
            'transport'         => 'refresh',
        ) );

        $control_args = array(
            'label'       => $meta['label'],
            'description' => isset( $meta['description'] ) ? $meta['description'] : '',
            'section'     => 'ea_rep_development_page',
            'settings'    => $setting,
        );

        if ( 'image' === $meta['type'] ) {
            $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, $setting, $control_args ) );
        } elseif ( 'color' === $meta['type'] ) {
            $wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, $setting, $control_args ) );
        } else {
            $control_type = 'map' === $meta['type'] ? 'textarea' : $meta['type'];
            $wp_customize->add_control( $setting, array_merge( $control_args, array(
                'type' => $control_type,
            ) ) );
        }
    }
}
add_action( 'customize_register', 'ea_customize_rep_development' );

// ─── Swappable copy via the Customizer (Appearance → Customize → EA Text) ──────
// Each control stores a string as a theme_mod. ea_react_texts() collects them for
// wp_localize_script so React reads them from window.eaReactData.texts. Each field
// ships with its current copy as the default; clearing a field restores that copy
// (React keeps the same string as a final fallback).
function ea_react_text_fields() {
    return array(
        // ── Hero ──────────────────────────────────────────────────────────────
        'ea_txt_hero_heading' => array(
            'key' => 'heroHeading', 'label' => 'Hero — Heading', 'type' => 'text',
            'default' => 'Basketball Starts Here',
        ),
        'ea_txt_hero_desc' => array(
            'key' => 'heroDesc', 'label' => 'Hero — Subtext', 'type' => 'textarea',
            'default' => 'Join basketball programs built for young athletes to learn, compete, and grow with confidence.',
        ),
        'ea_txt_hero_btn_primary' => array(
            'key' => 'heroBtnPrimary', 'label' => 'Hero — Primary button', 'type' => 'text',
            'default' => 'Find a League Near You',
        ),
        'ea_txt_hero_btn_secondary' => array(
            'key' => 'heroBtnSecondary', 'label' => 'Hero — Secondary button', 'type' => 'text',
            'default' => 'Find Training Programs',
        ),

        // ── Navigation ────────────────────────────────────────────────────────
        'ea_txt_nav_cta' => array(
            'key' => 'navCta', 'label' => 'Navigation — CTA button', 'type' => 'text',
            'default' => 'Find a League Near You',
        ),
        'ea_txt_nav_connect' => array(
            'key' => 'navConnect', 'label' => 'Navigation — Connect link', 'type' => 'text',
            'default' => 'Connect with us',
        ),

        // ── New Programs / Carousel ───────────────────────────────────────────
        'ea_txt_new_programs_heading' => array(
            'key' => 'newProgramsHeading', 'label' => 'New Programs — Heading', 'type' => 'text',
            'default' => 'Check out our new programs!',
        ),

        // ── Free Trial form ───────────────────────────────────────────────────
        'ea_txt_free_trial_heading' => array(
            'key' => 'freeTrialHeading', 'label' => 'Free Trial — Heading', 'type' => 'text',
            'default' => 'Register for your free trial!',
        ),
        'ea_txt_free_trial_subheading' => array(
            'key' => 'freeTrialSubheading', 'label' => 'Free Trial — Subtitle', 'type' => 'textarea',
            'default' => 'Try a beginner-friendly basketball session at Trinity United Church. Select your athlete’s age range to see the next available trial dates.',
        ),
        'ea_txt_free_trial_name_label' => array(
            'key' => 'freeTrialNameLabel', 'label' => 'Free Trial — Name field label', 'type' => 'text',
            'default' => 'Athlete\'s Name',
        ),
        'ea_txt_free_trial_email_label' => array(
            'key' => 'freeTrialEmailLabel', 'label' => 'Free Trial — Email field label', 'type' => 'text',
            'default' => 'Email',
        ),
        'ea_txt_free_trial_session_label' => array(
            'key' => 'freeTrialSessionLabel', 'label' => 'Free Trial — Session field label', 'type' => 'text',
            'default' => 'Choose Session',
        ),
        'ea_txt_free_trial_submit' => array(
            'key' => 'freeTrialSubmit', 'label' => 'Free Trial — Submit button', 'type' => 'text',
            'default' => 'Register',
        ),
        'ea_txt_free_trial_thanks_title' => array(
            'key' => 'freeTrialThanksTitle', 'label' => 'Free Trial — Confirmation title', 'type' => 'text',
            'default' => 'Thank you!',
        ),
        'ea_txt_free_trial_thanks_body' => array(
            'key' => 'freeTrialThanksBody', 'label' => 'Free Trial — Confirmation message', 'type' => 'textarea',
            'default' => 'We\'ll be in touch about your free trial.',
        ),

        // ── Active Programs ───────────────────────────────────────────────────
        'ea_txt_programs_heading' => array(
            'key' => 'programsHeading', 'label' => 'Active Programs — Heading', 'type' => 'text',
            'default' => 'Our Active Programs',
        ),
        'ea_txt_programs_desc' => array(
            'key' => 'programsDesc', 'label' => 'Active Programs — Description', 'type' => 'textarea',
            'default' => '',
        ),
        'ea_txt_programs_view_all' => array(
            'key' => 'programsViewAll', 'label' => 'Active Programs — "View all" button', 'type' => 'text',
            'default' => 'View All Programs',
        ),
        'ea_txt_programs_near_me' => array(
            'key' => 'programsNearMe', 'label' => 'Active Programs — "Near me" button', 'type' => 'text',
            'default' => 'Programs Near Me',
        ),

        // ── League Hub ───────────────────────────────────────────────────────
        'ea_txt_league_hub_heading' => array(
            'key' => 'leagueHubHeading', 'label' => 'League Hub — Heading', 'type' => 'text',
            'default' => 'Our Programs',
        ),
        'ea_txt_league_hub_subheading' => array(
            'key' => 'leagueHubSubheading', 'label' => 'League Hub — Subheading', 'type' => 'textarea',
            'default' => sprintf( 'Find %s lessons, leagues, and camps that are currently open for registration.', strtolower( ea_default_sport_value() ) ),
        ),
        'ea_txt_league_hub_location_button' => array(
            'key' => 'leagueHubLocationButton', 'label' => 'League Hub — Location button', 'type' => 'text',
            'default' => 'Use My Location',
        ),

        // ── Basketball Guide ────────────────────────────────────────────────
        'ea_txt_basketball_guide_heading' => array(
            'key' => 'basketballGuideHeading', 'label' => 'Basketball Guide — Heading', 'type' => 'text',
            'default' => 'Basketball for Every Player',
        ),
        'ea_txt_basketball_guide_subheading' => array(
            'key' => 'basketballGuideSubheading', 'label' => 'Basketball Guide — Subheading', 'type' => 'textarea',
            'default' => 'Whether your child is picking up a basketball for the first time, looking for more opportunities to play, or preparing for competitive basketball, Elevation Athletics offers a program to support their development.',
        ),
        'ea_txt_basketball_guide_box_1_heading' => array(
            'key' => 'guideBox1Heading', 'label' => 'Basketball Guide — Box 1 heading', 'type' => 'text',
            'default' => 'EA Training Programs',
        ),
        'ea_txt_basketball_guide_box_1_subheading' => array(
            'key' => 'guideBox1Subheading', 'label' => 'Basketball Guide — Box 1 subheading', 'type' => 'text',
            'default' => 'Beginner Friendly · Ages 5 - 15',
        ),
        'ea_txt_basketball_guide_box_1_text' => array(
            'key' => 'guideBox1Text', 'label' => 'Basketball Guide — Box 1 text', 'type' => 'textarea',
            'default' => 'Our training programs are built for players who want to develop fundamentals, confidence, and game understanding in a supportive environment.',
        ),
        'ea_txt_basketball_guide_box_1_button' => array(
            'key' => 'guideBox1Button', 'label' => 'Basketball Guide — Box 1 button', 'type' => 'text',
            'default' => 'Learn More',
        ),
        'ea_txt_basketball_guide_box_2_heading' => array(
            'key' => 'guideBox2Heading', 'label' => 'Basketball Guide — Box 2 heading', 'type' => 'text',
            'default' => 'EA Development House League',
        ),
        'ea_txt_basketball_guide_box_2_subheading' => array(
            'key' => 'guideBox2Subheading', 'label' => 'Basketball Guide — Box 2 subheading', 'type' => 'text',
            'default' => 'Beginner to Intermediate · Ages 9 - 12',
        ),
        'ea_txt_basketball_guide_box_2_text' => array(
            'key' => 'guideBox2Text', 'label' => 'Basketball Guide — Box 2 text', 'type' => 'textarea',
            'default' => 'A development-first league for players who want structured games, coaching support, and a fun path to improve week by week.',
        ),
        'ea_txt_basketball_guide_box_2_button' => array(
            'key' => 'guideBox2Button', 'label' => 'Basketball Guide — Box 2 button', 'type' => 'text',
            'default' => 'Learn More',
        ),
        'ea_txt_basketball_guide_box_3_heading' => array(
            'key' => 'guideBox3Heading', 'label' => 'Basketball Guide — Box 3 heading', 'type' => 'text',
            'default' => 'EA Rep Development',
        ),
        'ea_txt_basketball_guide_box_3_subheading' => array(
            'key' => 'guideBox3Subheading', 'label' => 'Basketball Guide — Box 3 subheading', 'type' => 'text',
            'default' => 'Competitive Preparation · Ages 10 - 15',
        ),
        'ea_txt_basketball_guide_box_3_text' => array(
            'key' => 'guideBox3Text', 'label' => 'Basketball Guide — Box 3 text', 'type' => 'textarea',
            'default' => 'For motivated players looking for a higher level of training, skill development, and preparation for more competitive basketball.',
        ),
        'ea_txt_basketball_guide_box_3_button' => array(
            'key' => 'guideBox3Button', 'label' => 'Basketball Guide — Box 3 button', 'type' => 'text',
            'default' => 'Learn More',
        ),
        'ea_txt_basketball_guide_box_4_heading' => array(
            'key' => 'guideBox4Heading', 'label' => 'Basketball Guide — Box 4 heading', 'type' => 'text',
            'default' => 'EA Rep Teams',
        ),
        'ea_txt_basketball_guide_box_4_subheading' => array(
            'key' => 'guideBox4Subheading', 'label' => 'Basketball Guide — Box 4 subheading', 'type' => 'text',
            'default' => 'Advanced Team Play · Ages 12 - 17',
        ),
        'ea_txt_basketball_guide_box_4_text' => array(
            'key' => 'guideBox4Text', 'label' => 'Basketball Guide — Box 4 text', 'type' => 'textarea',
            'default' => 'Competitive team opportunities for athletes who are ready for advanced training, stronger competition, and a team-first environment.',
        ),
        'ea_txt_basketball_guide_box_4_button' => array(
            'key' => 'guideBox4Button', 'label' => 'Basketball Guide — Box 4 button', 'type' => 'text',
            'default' => 'Learn More',
        ),
        'ea_txt_basketball_guide_training_heading' => array(
            'key' => 'basketballGuideTrainingHeading', 'label' => 'Basketball Guide — More ways heading', 'type' => 'text',
            'default' => 'More Ways to Train',
        ),
        'ea_txt_basketball_guide_training_subheading' => array(
            'key' => 'basketballGuideTrainingSubheading', 'label' => 'Basketball Guide — More ways subheading', 'type' => 'textarea',
            'default' => 'Explore seasonal camps and focused training options designed to give athletes more time on the court.',
        ),
        'ea_txt_basketball_guide_box_5_heading' => array(
            'key' => 'guideBox5Heading', 'label' => 'Basketball Guide — Box 5 heading', 'type' => 'text',
            'default' => 'Camps',
        ),
        'ea_txt_basketball_guide_box_5_subheading' => array(
            'key' => 'guideBox5Subheading', 'label' => 'Basketball Guide — Box 5 subheading', 'type' => 'text',
            'default' => 'Seasonal Training · Ages vary',
        ),
        'ea_txt_basketball_guide_box_5_text' => array(
            'key' => 'guideBox5Text', 'label' => 'Basketball Guide — Box 5 text', 'type' => 'textarea',
            'default' => 'Seasonal camps give players focused time to build skills, stay active, and enjoy the game during school breaks.',
        ),
        'ea_txt_basketball_guide_box_5_button' => array(
            'key' => 'guideBox5Button', 'label' => 'Basketball Guide — Box 5 button', 'type' => 'text',
            'default' => 'Learn More',
        ),
        'ea_txt_basketball_guide_box_6_heading' => array(
            'key' => 'guideBox6Heading', 'label' => 'Basketball Guide — Box 6 heading', 'type' => 'text',
            'default' => '1-on-1 Training',
        ),
        'ea_txt_basketball_guide_box_6_subheading' => array(
            'key' => 'guideBox6Subheading', 'label' => 'Basketball Guide — Box 6 subheading', 'type' => 'text',
            'default' => 'Private Coaching · Custom ages',
        ),
        'ea_txt_basketball_guide_box_6_text' => array(
            'key' => 'guideBox6Text', 'label' => 'Basketball Guide — Box 6 text', 'type' => 'textarea',
            'default' => 'Individual and small-group training for athletes who want focused coaching, extra reps, and a personalized development plan.',
        ),
        'ea_txt_basketball_guide_box_6_button' => array(
            'key' => 'guideBox6Button', 'label' => 'Basketball Guide — Box 6 button', 'type' => 'text',
            'default' => 'Learn More',
        ),

        // ── Basketball Rep Tryouts ──────────────────────────────────────────
        'ea_txt_basketball_rep_heading' => array(
            'key' => 'basketballRepHeading', 'label' => 'Basketball Rep — Heading', 'type' => 'text',
            'default' => 'EA Rep Basketball',
        ),
        'ea_txt_basketball_rep_subheading' => array(
            'key' => 'basketballRepSubheading', 'label' => 'Basketball Rep — Subheading', 'type' => 'textarea',
            'default' => 'High-performance teams for committed athletes in Grades 4-12.',
        ),
        'ea_txt_basketball_rep_hero_button' => array(
            'key' => 'basketballRepHeroButton', 'label' => 'Basketball Rep — Hero button', 'type' => 'text',
            'default' => 'Register Now',
        ),
        'ea_txt_basketball_rep_about_heading' => array(
            'key' => 'basketballRepAboutHeading', 'label' => 'Basketball Rep — About heading', 'type' => 'text',
            'default' => 'Our Competitive Pathway for Committed Athletes',
        ),
        'ea_txt_basketball_rep_about_body' => array(
            'key' => 'basketballRepAboutBody', 'label' => 'Basketball Rep — About body', 'type' => 'textarea',
            'default' => 'Our teams compete in leagues, tournaments, and showcases such as OBL, North Pole Hoops, CYBL, Coalition Basketball League, York Basketball League, and select AAU events in the U.S., giving athletes exposure to elite competition and development pathways.' . "\n\n" . 'Many EA athletes go on to compete at the college and university level across Ontario, equipped with the skills and mindset to succeed beyond youth basketball.' . "\n\n" . 'EA Rep teams are led by experienced, high-level coaches who prioritize both performance and personal growth. Our coaches are committed to building confident athletes, strong teammates, and leaders on and off the court.',
        ),
        'ea_txt_basketball_rep_tryouts_heading' => array(
            'key' => 'basketballRepTryoutsHeading', 'label' => 'Basketball Rep — Tryouts heading', 'type' => 'text',
            'default' => 'Tryouts & Registration',
        ),
        'ea_txt_basketball_rep_schedule_button' => array(
            'key' => 'basketballRepScheduleButton', 'label' => 'Basketball Rep — Schedule button', 'type' => 'text',
            'default' => 'See Schedule',
        ),
        'ea_txt_basketball_rep_tryouts_body' => array(
            'key' => 'basketballRepTryoutsBody', 'label' => 'Basketball Rep — Tryouts body', 'type' => 'textarea',
            'default' => 'Tryouts are free of charge, but registration is required.' . "\n\n" . 'All tryout updates and changes will be communicated via email.' . "\n\n" . 'A waiver must be completed prior to attending all EA Rep Team tryouts. Please bring a completed copy of the waiver to tryout, or email it to liam@elevationathletics.ca.',
        ),
        'ea_txt_basketball_rep_waiver_button' => array(
            'key' => 'basketballRepWaiverButton', 'label' => 'Basketball Rep — Waiver button', 'type' => 'text',
            'default' => 'Download Waiver',
        ),
        'ea_txt_basketball_rep_form_shortcode' => array(
            'key' => 'basketballRepFormShortcode', 'label' => 'Basketball Rep — WPForms shortcode', 'type' => 'textarea',
            'default' => '',
        ),
        'ea_txt_basketball_rep_schedule_heading' => array(
            'key' => 'basketballRepScheduleHeading', 'label' => 'Basketball Rep — Schedule heading', 'type' => 'text',
            'default' => 'Fall 2026 Schedule',
        ),
        'ea_txt_basketball_rep_schedule_rows' => array(
            'key' => 'basketballRepScheduleRows', 'label' => 'Basketball Rep — Schedule rows', 'type' => 'textarea',
            'default' => 'August 17 | 6:30-7:30 PM | U10 | Ross Family Complex (Mt. Albert)' . "\n" . 'August 17 | 7:30-8:30 PM | U12 | Ross Family Complex (Mt. Albert)' . "\n" . 'August 17 | 8:30-10:00 PM | U15/U16 | Ross Family Complex (Mt. Albert)' . "\n" . 'August 23 | 6:00-7:00 PM | U10 | Aurora Family Leisure Complex' . "\n" . 'August 23 | 7:00-8:30 PM | U12 | Aurora Family Leisure Complex' . "\n" . 'August 23 | 8:30-10:00 PM | U15/U16 | Aurora Family Leisure Complex' . "\n" . 'August 24 | 6:30-7:30 PM | U11 | Ross Family Complex (Mt. Albert)' . "\n" . 'August 24 | 7:30-8:30 PM | U13 | Ross Family Complex (Mt. Albert)' . "\n" . 'August 24 | 8:30-10:00 PM | U17/U19 | Ross Family Complex (Mt. Albert)' . "\n" . 'August 29 | 5:30-6:30 PM | U11 | Aurora Family Leisure Complex' . "\n" . 'August 29 | 6:30-8:00 PM | U14 | Aurora Family Leisure Complex' . "\n" . 'August 29 | 8:00-9:30 PM | U17/U19 | Aurora Family Leisure Complex' . "\n" . 'September 2 | 6:00-7:30 PM | U13 | Aurora Family Leisure Complex' . "\n" . 'September 2 | 7:30-9:00 PM | U14 | Aurora Family Leisure Complex',
        ),
        'ea_txt_basketball_rep_map_1_heading' => array(
            'key' => 'basketballRepMap1Heading', 'label' => 'Basketball Rep — Map 1 heading', 'type' => 'text',
            'default' => 'Ross Family Complex',
        ),
        'ea_txt_basketball_rep_map_1_embed' => array(
            'key' => 'basketballRepMap1Embed', 'label' => 'Basketball Rep — Map 1 embed src URL', 'type' => 'textarea',
            'default' => '',
        ),
        'ea_txt_basketball_rep_map_2_heading' => array(
            'key' => 'basketballRepMap2Heading', 'label' => 'Basketball Rep — Map 2 heading', 'type' => 'text',
            'default' => 'Aurora Family Leisure Complex',
        ),
        'ea_txt_basketball_rep_map_2_embed' => array(
            'key' => 'basketballRepMap2Embed', 'label' => 'Basketball Rep — Map 2 embed src URL', 'type' => 'textarea',
            'default' => '',
        ),

        // ── Elevation Athletics Directory ───────────────────────────────────
        'ea_txt_directory_heading' => array(
            'key' => 'directoryHeading', 'label' => 'EA Directory — Heading', 'type' => 'text',
            'default' => 'Elevation Athletics',
        ),
        'ea_txt_directory_subheading' => array(
            'key' => 'directorySubheading', 'label' => 'EA Directory — Subheading', 'type' => 'textarea',
            'default' => 'Inclusive, high-quality sport programming helping athletes grow with confidence across Canada.',
        ),
        'ea_txt_directory_programs_heading' => array(
            'key' => 'directoryProgramsHeading', 'label' => 'EA Directory — Programs heading', 'type' => 'text',
            'default' => 'Our Programs',
        ),
        'ea_txt_directory_programs_subheading' => array(
            'key' => 'directoryProgramsSubheading', 'label' => 'EA Directory — Programs subheading', 'type' => 'textarea',
            'default' => 'Serving 5,000+ players with 200+ sports programs across Canada.',
        ),
        'ea_txt_directory_card_1_label' => array(
            'key' => 'directoryCard1Label', 'label' => 'EA Directory — Card 1 label', 'type' => 'text',
            'default' => 'Basketball',
        ),
        'ea_txt_directory_card_2_label' => array(
            'key' => 'directoryCard2Label', 'label' => 'EA Directory — Card 2 label', 'type' => 'text',
            'default' => 'Pickleball',
        ),
        'ea_txt_directory_card_3_label' => array(
            'key' => 'directoryCard3Label', 'label' => 'EA Directory — Card 3 label', 'type' => 'text',
            'default' => 'Badminton',
        ),
        'ea_txt_directory_annual_membership_label' => array(
            'key' => 'directoryCard4Label', 'label' => 'EA Directory — Card 4 label', 'type' => 'text',
            'default' => 'Annual Membership',
        ),
        'ea_txt_directory_volleyball_label' => array(
            'key' => 'directoryCard9Label', 'label' => 'EA Directory — Card 9 label', 'type' => 'text',
            'default' => 'Volleyball',
        ),
        'ea_txt_directory_join_heading' => array(
            'key' => 'directoryJoinHeading', 'label' => 'EA Directory — Join section heading', 'type' => 'text',
            'default' => 'Join our Team',
        ),
        'ea_txt_directory_join_subheading' => array(
            'key' => 'directoryJoinSubheading', 'label' => 'EA Directory — Join section subheading', 'type' => 'textarea',
            'default' => 'We’re looking for coaches and members of our internal teams. We also work directly with townships and municipalities to bring sports programming to a community near you.',
        ),
        'ea_txt_directory_card_5_label' => array(
            'key' => 'directoryCard5Label', 'label' => 'EA Directory — Card 5 label', 'type' => 'text',
            'default' => 'Become a Coach',
        ),
        'ea_txt_directory_community_leader_label' => array(
            'key' => 'directoryCard6Label', 'label' => 'EA Directory — Card 6 label', 'type' => 'text',
            'default' => 'Become a Community Leader',
        ),
        'ea_txt_directory_card_7_label' => array(
            'key' => 'directoryCard7Label', 'label' => 'EA Directory — Card 7 label', 'type' => 'text',
            'default' => 'Community Partnerships',
        ),
        'ea_txt_directory_card_8_label' => array(
            'key' => 'directoryCard8Label', 'label' => 'EA Directory — Card 8 label', 'type' => 'text',
            'default' => 'Work With Us',
        ),

        // ── Camps Page ──────────────────────────────────────────────────────
        'ea_txt_camps_heading' => array(
            'key' => 'campsHeading', 'label' => 'Camps — Heading', 'type' => 'text',
            'default' => 'Youth Camps',
        ),
        'ea_txt_camps_subheading' => array(
            'key' => 'campsSubheading', 'label' => 'Camps — Subheading', 'type' => 'textarea',
            'default' => 'Seasonal basketball, badminton, racquet sport, and multi-sport camps for active kids.',
        ),
        'ea_txt_camps_intro' => array(
            'key' => 'campsIntro', 'label' => 'Camps — Intro text', 'type' => 'textarea',
            'default' => 'Our camps typically run during March Break, summer weeks, PA days, and select seasonal windows. Each camp is designed to keep kids moving, learning, and building confidence in a positive team environment.',
        ),
        'ea_txt_camps_season_note' => array(
            'key' => 'campsSeasonNote', 'label' => 'Camps — Seasonal note', 'type' => 'textarea',
            'default' => 'Best for families looking for structured activity during school breaks, summer weeks, and seasonal program windows.',
        ),
        'ea_txt_camp_card_1_eyebrow' => array(
            'key' => 'campCard1Eyebrow', 'label' => 'Camps — Card 1 label', 'type' => 'text',
            'default' => 'Basketball',
        ),
        'ea_txt_camp_card_1_heading' => array(
            'key' => 'campCard1Heading', 'label' => 'Camps — Card 1 heading', 'type' => 'text',
            'default' => 'Newmarket Basketball Camp',
        ),
        'ea_txt_camp_card_1_text' => array(
            'key' => 'campCard1Text', 'label' => 'Camps — Card 1 text', 'type' => 'textarea',
            'default' => 'Skill-building, team games, and active play for young athletes during school breaks.',
        ),
        'ea_txt_camp_card_1_button' => array(
            'key' => 'campCard1Button', 'label' => 'Camps — Card 1 button', 'type' => 'text',
            'default' => 'Learn More',
        ),
        'ea_txt_camp_card_2_eyebrow' => array(
            'key' => 'campCard2Eyebrow', 'label' => 'Camps — Card 2 label', 'type' => 'text',
            'default' => 'Badminton',
        ),
        'ea_txt_camp_card_2_heading' => array(
            'key' => 'campCard2Heading', 'label' => 'Camps — Card 2 heading', 'type' => 'text',
            'default' => 'Newmarket Badminton Camp',
        ),
        'ea_txt_camp_card_2_text' => array(
            'key' => 'campCard2Text', 'label' => 'Camps — Card 2 text', 'type' => 'textarea',
            'default' => 'Introductory badminton, rallies, footwork, and court confidence in a fun camp setting.',
        ),
        'ea_txt_camp_card_2_button' => array(
            'key' => 'campCard2Button', 'label' => 'Camps — Card 2 button', 'type' => 'text',
            'default' => 'Learn More',
        ),
        'ea_txt_camp_card_3_eyebrow' => array(
            'key' => 'campCard3Eyebrow', 'label' => 'Camps — Card 3 label', 'type' => 'text',
            'default' => 'Multi-Sports',
        ),
        'ea_txt_camp_card_3_heading' => array(
            'key' => 'campCard3Heading', 'label' => 'Camps — Card 3 heading', 'type' => 'text',
            'default' => 'Uxbridge Multi-Sports Camp',
        ),
        'ea_txt_camp_card_3_text' => array(
            'key' => 'campCard3Text', 'label' => 'Camps — Card 3 text', 'type' => 'textarea',
            'default' => 'A mix of sports, movement games, teamwork, and activities for kids who like variety.',
        ),
        'ea_txt_camp_card_3_button' => array(
            'key' => 'campCard3Button', 'label' => 'Camps — Card 3 button', 'type' => 'text',
            'default' => 'Learn More',
        ),
        'ea_txt_camp_card_4_eyebrow' => array(
            'key' => 'campCard4Eyebrow', 'label' => 'Camps — Card 4 label', 'type' => 'text',
            'default' => 'Racquet Sports',
        ),
        'ea_txt_camp_card_4_heading' => array(
            'key' => 'campCard4Heading', 'label' => 'Camps — Card 4 heading', 'type' => 'text',
            'default' => 'Aurora Racquet Sports Camp',
        ),
        'ea_txt_camp_card_4_text' => array(
            'key' => 'campCard4Text', 'label' => 'Camps — Card 4 text', 'type' => 'textarea',
            'default' => 'Badminton, pickleball-inspired games, movement skills, and racquet confidence.',
        ),
        'ea_txt_camp_card_4_button' => array(
            'key' => 'campCard4Button', 'label' => 'Camps — Card 4 button', 'type' => 'text',
            'default' => 'Learn More',
        ),

        // ── Coming Soon ─────────────────────────────────────────────────────
        'ea_txt_coming_soon_heading' => array(
            'key' => 'comingSoonHeading', 'label' => 'Coming Soon — Heading', 'type' => 'text',
            'default' => 'Coming Soon',
        ),
        'ea_txt_coming_soon_subheading' => array(
            'key' => 'comingSoonSubheading', 'label' => 'Coming Soon — Subtext', 'type' => 'textarea',
            'default' => 'This page is currently under construction.',
        ),

        // ── Small Group Coaching ──────────────────────────────────────────────
        'ea_txt_coaching_heading' => array(
            'key' => 'coachingHeading', 'label' => 'Coaching — Heading', 'type' => 'text',
            'default' => 'Small Group Coaching',
        ),
        'ea_txt_coaching_desc' => array(
            'key' => 'coachingDesc', 'label' => 'Coaching — Description', 'type' => 'textarea',
            'default' => 'Small group coaching that meets every player where they are. Our sessions build skills, confidence, and a love of the game.',
        ),
        'ea_txt_coaching_cta' => array(
            'key' => 'coachingCta', 'label' => 'Coaching — Button', 'type' => 'text',
            'default' => 'Learn More',
        ),

        // ── Community ─────────────────────────────────────────────────────────
        'ea_txt_community_heading' => array(
            'key' => 'communityHeading', 'label' => 'Community — Heading', 'type' => 'text',
            'default' => 'Want to be a part of the community?',
        ),
        'ea_txt_community_desc_1' => array(
            'key' => 'communityDesc1', 'label' => 'Community — Paragraph 1', 'type' => 'textarea',
            'default' => 'From first-timers to future champions, Elevation Athletics badminton is built around fun, inclusive play for every family.',
        ),
        'ea_txt_community_desc_2' => array(
            'key' => 'communityDesc2', 'label' => 'Community — Paragraph 2', 'type' => 'textarea',
            'default' => 'Join a welcoming community of players, parents, and coaches who make every session something to look forward to.',
        ),
        'ea_txt_partnerships_title' => array(
            'key' => 'partnershipsTitle', 'label' => 'Community — Partnerships title', 'type' => 'text',
            'default' => 'Community Partnerships',
        ),
        'ea_txt_partnerships_blurb' => array(
            'key' => 'partnershipsBlurb', 'label' => 'Community — Partnerships blurb', 'type' => 'textarea',
            'default' => 'Help bring inclusive, low-cost badminton to your township. We\'ll set you up with courts, coaching, and leagues.',
        ),
        'ea_txt_partnerships_cta' => array(
            'key' => 'partnershipsCta', 'label' => 'Community — Partnerships button', 'type' => 'text',
            'default' => 'Learn More',
        ),
        'ea_txt_leaders_title' => array(
            'key' => 'leadersTitle', 'label' => 'Community — Leaders title', 'type' => 'text',
            'default' => 'Become a Community Leader',
        ),
        'ea_txt_leaders_blurb' => array(
            'key' => 'leadersBlurb', 'label' => 'Community — Leaders blurb', 'type' => 'textarea',
            'default' => 'Help bring inclusive, low-cost badminton to your township. We\'ll set you up with courts, coaching, and leagues.',
        ),
        'ea_txt_leaders_cta' => array(
            'key' => 'leadersCta', 'label' => 'Community — Leaders button', 'type' => 'text',
            'default' => 'Apply Today',
        ),

        // ── Newsletter ────────────────────────────────────────────────────────
        'ea_txt_newsletter_heading' => array(
            'key' => 'newsletterHeading', 'label' => 'Newsletter — Heading', 'type' => 'text',
            'default' => 'Join Our Newsletter!',
        ),
        'ea_txt_newsletter_desc' => array(
            'key' => 'newsletterDesc', 'label' => 'Newsletter — Description', 'type' => 'textarea',
            'default' => 'Stay updated on upcoming training sessions, leagues, and tournaments for pickleball in your area.',
        ),
        'ea_txt_newsletter_subscribe' => array(
            'key' => 'newsletterSubscribe', 'label' => 'Newsletter — Subscribe button', 'type' => 'text',
            'default' => 'Subscribe',
        ),
        'ea_txt_newsletter_thanks' => array(
            'key' => 'newsletterThanks', 'label' => 'Newsletter — Confirmation', 'type' => 'text',
            'default' => 'Thanks for subscribing!',
        ),

        // ── Footer ────────────────────────────────────────────────────────────
        'ea_txt_footer_contact_heading' => array(
            'key' => 'footerContactHeading', 'label' => 'Footer — Contact heading', 'type' => 'text',
            'default' => 'Contact Us',
        ),
        'ea_txt_footer_socials_heading' => array(
            'key' => 'footerSocialsHeading', 'label' => 'Footer — Socials heading', 'type' => 'text',
            'default' => 'Follow us on our socials!',
        ),
        'ea_txt_footer_quicklinks_title' => array(
            'key' => 'footerQuickLinksTitle', 'label' => 'Footer — Quick Links title', 'type' => 'text',
            'default' => 'Quick Links',
        ),
        'ea_txt_footer_moresports_title' => array(
            'key' => 'footerMoreSportsTitle', 'label' => 'Footer — More Sports title', 'type' => 'text',
            'default' => 'More Sports',
        ),
        'ea_txt_footer_copyright' => array(
            'key' => 'footerCopyright', 'label' => 'Footer — Copyright (year is added automatically)', 'type' => 'text',
            'default' => 'Elevation Athletics. All rights reserved.',
        ),
    );
}

function ea_react_texts() {
    $texts = array();
    foreach ( ea_react_text_fields() as $setting => $meta ) {
        $texts[ $meta['key'] ] = get_theme_mod( $setting, $meta['default'] );
    }
    return $texts;
}

// ─── Camps page content via the Customizer (Appearance → Customize → EA Camps Page) ─
const EA_CAMPS_CARD_SLOTS = 8;

function ea_camps_page_defaults() {
    return array(
        'heading'        => 'Youth Camps',
        'subheading'     => 'Seasonal basketball, badminton, racquet sport, and multi-sport camps for active kids.',
        'intro'          => '',
        'contactHeading' => 'Not sure which camp is right?',
        'contactText'    => 'Contact us and we’ll help you choose the best fit for your child.',
        'contactButton'  => 'Contact Us',
        'contactLink'    => 'mailto:info@elevationathletics.ca',
    );
}

function ea_camp_card_defaults() {
    return array(
        1 => array(
            'enabled'  => true,
            'label'    => 'Basketball',
            'heading'  => 'Newmarket Basketball Camp',
            'ageRange' => 'Ages 7-12',
            'text'     => 'Skill-building, team games, and active play during school breaks.',
            'button'   => 'Learn More',
            'link'     => '',
            'image'    => '',
        ),
        2 => array(
            'enabled'  => true,
            'label'    => 'Badminton',
            'heading'  => 'Newmarket Badminton Camp',
            'ageRange' => 'Ages 8-14',
            'text'     => 'Introductory badminton, rallies, footwork, and court confidence.',
            'button'   => 'Learn More',
            'link'     => '',
            'image'    => '',
        ),
        3 => array(
            'enabled'  => true,
            'label'    => 'Multi-Sports',
            'heading'  => 'Uxbridge Multi-Sports Camp',
            'ageRange' => 'Ages 6-12',
            'text'     => 'A mix of sports, movement games, teamwork, and active play.',
            'button'   => 'Learn More',
            'link'     => '',
            'image'    => '',
        ),
        4 => array(
            'enabled'  => true,
            'label'    => 'Racquet Sports',
            'heading'  => 'Aurora Racquet Sports Camp',
            'ageRange' => 'Ages 7-13',
            'text'     => 'Racquet skills, movement games, and beginner-friendly court play.',
            'button'   => 'Learn More',
            'link'     => '',
            'image'    => '',
        ),
    );
}

function ea_react_camps() {
    $defaults = ea_camps_page_defaults();
    $camps    = array();

    foreach ( $defaults as $key => $default ) {
        $setting = 'ea_camps_' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $key ) );
        $camps[ $key ] = get_theme_mod( $setting, $default );
    }

    $card_defaults = ea_camp_card_defaults();
    $cards         = array();

    for ( $i = 1; $i <= EA_CAMPS_CARD_SLOTS; $i++ ) {
        $default = isset( $card_defaults[ $i ] ) ? $card_defaults[ $i ] : array(
            'enabled'  => false,
            'label'    => '',
            'heading'  => '',
            'ageRange' => '',
            'text'     => '',
            'button'   => 'Learn More',
            'link'     => '',
            'image'    => '',
        );

        $cards[] = array(
            'key'      => 'campCustom' . $i,
            'enabled'  => (bool) get_theme_mod( "ea_camps_card_{$i}_enabled", $default['enabled'] ),
            'label'    => get_theme_mod( "ea_camps_card_{$i}_label", $default['label'] ),
            'heading'  => get_theme_mod( "ea_camps_card_{$i}_heading", $default['heading'] ),
            'ageRange' => get_theme_mod( "ea_camps_card_{$i}_age_range", $default['ageRange'] ),
            'text'     => get_theme_mod( "ea_camps_card_{$i}_text", $default['text'] ),
            'button'   => get_theme_mod( "ea_camps_card_{$i}_button", $default['button'] ),
            'link'     => array( 'url' => esc_url( get_theme_mod( "ea_camps_card_{$i}_link", $default['link'] ) ) ),
            'image'    => esc_url( get_theme_mod( "ea_camps_card_{$i}_image", $default['image'] ) ),
        );
    }

    $camps['cards'] = $cards;
    return $camps;
}

// ─── Layout toggles via the Customizer (Appearance → Customize → EA Options) ───
// Sports that can appear in the Active Programs feed. Keys match the codes used in
// the programs JSON (React maps common variants onto these).
function ea_sport_choices() {
    return array(
        'pb'     => __( 'Pickleball', 'ea-react-theme' ),
        'bad'    => __( 'Badminton', 'ea-react-theme' ),
        'bask'   => __( 'Basketball', 'ea-react-theme' ),
        's_camp' => __( 'Sports Camp', 'ea-react-theme' ),
    );
}

// Keep only recognised sport keys; always return a (possibly empty) array.
function ea_sanitize_sports( $value ) {
    $allowed = array_keys( ea_sport_choices() );
    $value   = is_array( $value ) ? $value : ( '' === $value || null === $value ? array() : (array) $value );
    return array_values( array_intersect( array_map( 'strval', $value ), $allowed ) );
}

function ea_default_sport_key() {
    $sport = strtolower( ea_default_sport_value() );

    if ( false !== strpos( $sport, 'pickle' ) ) {
        return 'pb';
    }

    if ( false !== strpos( $sport, 'basket' ) ) {
        return 'bask';
    }

    if ( false !== strpos( $sport, 'camp' ) ) {
        return 's_camp';
    }

    return 'bad';
}

function ea_default_sports_selection() {
    return array( ea_default_sport_key() );
}

function ea_sanitize_camps_layout( $value ) {
    $allowed = array( 'all', 'cards', 'featured', 'audience' );
    return in_array( (string) $value, $allowed, true ) ? (string) $value : 'all';
}

function ea_react_options() {
    $active_program_sports = ea_sanitize_sports( get_theme_mod( 'ea_sports', ea_default_sports_selection() ) );

    return array(
        // true = photo carousel, false = the Free Trial registration form.
        'useCarousel' => (bool) get_theme_mod( 'ea_use_carousel', true ),
        // Shows/hides only the standalone homepage hero image block.
        'showHeroImage' => (bool) get_theme_mod( 'ea_show_hero_image', true ),
        // Sports shown in the homepage Active Programs section.
        'sports'      => $active_program_sports,
        // Hide the Active Programs action buttons (both shown by default).
        'hideNearMe'  => (bool) get_theme_mod( 'ea_hide_near_me', false ),
        'hideViewAll' => (bool) get_theme_mod( 'ea_hide_view_all', false ),
        'programsShowDescription' => (bool) get_theme_mod( 'ea_programs_show_description', false ),
        // League Hub template controls.
        'leagueHubSports'        => ea_sanitize_sports( get_theme_mod( 'ea_league_hub_sports', $active_program_sports ) ),
        'leagueHubShowSubheading' => (bool) get_theme_mod( 'ea_league_hub_show_subheading', false ),
        'leagueHubFilterSearch'   => (bool) get_theme_mod( 'ea_league_hub_filter_search', true ),
        'leagueHubFilterLevel'    => (bool) get_theme_mod( 'ea_league_hub_filter_level', true ),
        'leagueHubFilterType'     => (bool) get_theme_mod( 'ea_league_hub_filter_type', true ),
        'leagueHubFilterAge'      => (bool) get_theme_mod( 'ea_league_hub_filter_age', true ),
        'leagueHubFilterTime'     => (bool) get_theme_mod( 'ea_league_hub_filter_time', true ),
        'leagueHubFilterDays'     => (bool) get_theme_mod( 'ea_league_hub_filter_days', true ),
        'leagueHubFilterLocation' => (bool) get_theme_mod( 'ea_league_hub_filter_location', true ),
        'leagueHubFilterProvince' => (bool) get_theme_mod( 'ea_league_hub_filter_province', false ),
        'leagueHubShowLocationButton' => (bool) get_theme_mod( 'ea_league_hub_show_location_button', false ),
        'leagueHubShowInactiveToggle' => false,
        'leagueHubShowMapView'      => (bool) get_theme_mod( 'ea_league_hub_show_map_view', true ),
        'leagueHubShowCalendarView' => (bool) get_theme_mod( 'ea_league_hub_show_calendar_view', true ),
        'leagueHubShowComingSoon' => (bool) get_theme_mod( 'ea_league_hub_show_coming_soon', false ),
        // Free Trial form session dropdown choices (one per line).
        'freeTrialSessions' => (string) get_theme_mod( 'ea_free_trial_sessions', EA_FREE_TRIAL_SESSIONS_DEFAULT ),
    );
}

// Default choices for the Free Trial session dropdown (one per line).
const EA_FREE_TRIAL_SESSIONS_DEFAULT = "Ontario\nBritish Columbia\nAlberta";

// Define a simple <select multiple> control (WP core has no native multi-select).
// Runs before the controls are added (priority 9 < default 10).
function ea_define_customize_controls() {
    if ( class_exists( 'EA_Multi_Select_Control' ) || ! class_exists( 'WP_Customize_Control' ) ) {
        return;
    }
    class EA_Multi_Select_Control extends WP_Customize_Control {
        public $type = 'ea_multiselect';
        public function render_content() {
            if ( empty( $this->choices ) ) {
                return;
            }
            $selected = $this->value();
            $selected = is_array( $selected ) ? array_map( 'strval', $selected ) : array();
            ?>
            <label>
                <?php if ( ! empty( $this->label ) ) : ?>
                    <span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
                <?php endif; ?>
                <?php if ( ! empty( $this->description ) ) : ?>
                    <span class="description customize-control-description"><?php echo wp_kses_post( $this->description ); ?></span>
                <?php endif; ?>
                <select multiple="multiple" style="height:auto;min-height:96px;width:100%;" <?php $this->link(); ?>>
                    <?php foreach ( $this->choices as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( in_array( (string) $val, $selected, true ) ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php
        }
    }
}
add_action( 'customize_register', 'ea_define_customize_controls', 9 );

// Registration page shell (Appearance > Customize > EA Registration Nav).
function ea_registration_nav_link_fields() {
    return array(
        'ea_registration_nav_pickleball' => array(
            'label_key'     => 'ea_registration_nav_pickleball_label',
            'default_label' => 'Pickleball',
            'default_url'   => 'https://eapickleball.com/',
        ),
        'ea_registration_nav_badminton' => array(
            'label_key'     => 'ea_registration_nav_badminton_label',
            'default_label' => 'Badminton',
            'default_url'   => 'https://eabadminton.com/',
        ),
        'ea_registration_nav_basketball' => array(
            'label_key'     => 'ea_registration_nav_basketball_label',
            'default_label' => 'Basketball',
            'default_url'   => 'https://elevationathletics.ca/home/',
        ),
    );
}

function ea_registration_nav_links() {
    $links = array();
    foreach ( ea_registration_nav_link_fields() as $url_key => $meta ) {
        $links[] = array(
            'label' => get_theme_mod( $meta['label_key'], $meta['default_label'] ),
            'url'   => get_theme_mod( $url_key, $meta['default_url'] ),
        );
    }
    return $links;
}

function ea_customize_registration_nav( $wp_customize ) {
    $wp_customize->add_section( 'ea_registration_nav', array(
        'title'       => __( 'EA Registration Nav', 'ea-react-theme' ),
        'description' => __( 'Controls the slim header used by the EA Registration Content page template.', 'ea-react-theme' ),
        'priority'    => 35,
    ) );

    $wp_customize->add_setting( 'ea_registration_nav_logo', array(
        'default'           => get_template_directory_uri() . '/assets/images/registration-logo.svg',
        'sanitize_callback' => 'esc_url_raw',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'ea_registration_nav_logo', array(
        'label'   => __( 'Registration nav logo', 'ea-react-theme' ),
        'section' => 'ea_registration_nav',
    ) ) );

    $wp_customize->add_setting( 'ea_registration_nav_logo_url', array(
        'default'           => 'https://elevationathletics.ca/',
        'sanitize_callback' => 'esc_url_raw',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( 'ea_registration_nav_logo_url', array(
        'type'    => 'url',
        'label'   => __( 'Logo link URL', 'ea-react-theme' ),
        'section' => 'ea_registration_nav',
    ) );

    foreach ( ea_registration_nav_link_fields() as $url_key => $meta ) {
        $wp_customize->add_setting( $meta['label_key'], array(
            'default'           => $meta['default_label'],
            'sanitize_callback' => 'sanitize_text_field',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( $meta['label_key'], array(
            'type'    => 'text',
            'label'   => sprintf( __( '%s label', 'ea-react-theme' ), $meta['default_label'] ),
            'section' => 'ea_registration_nav',
        ) );

        $wp_customize->add_setting( $url_key, array(
            'default'           => $meta['default_url'],
            'sanitize_callback' => 'esc_url_raw',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( $url_key, array(
            'type'    => 'url',
            'label'   => sprintf( __( '%s URL', 'ea-react-theme' ), $meta['default_label'] ),
            'section' => 'ea_registration_nav',
        ) );
    }
}
add_action( 'customize_register', 'ea_customize_registration_nav' );

// ─── Social profile links via the Customizer (Appearance → Customize → EA Social) ─
// Plain URL fields — no WP menu required. Blank fields hide that icon in the footer.
function ea_react_social_fields() {
    return array(
        'ea_social_instagram' => array(
            'key' => 'instagram', 'label' => 'Instagram URL',
            'default' => 'https://www.instagram.com/elevationathleticsbadminton/',
        ),
        'ea_social_facebook' => array(
            'key' => 'facebook', 'label' => 'Facebook URL',
            'default' => 'https://www.facebook.com/profile.php?id=61573558378113',
        ),
    );
}

function ea_react_social() {
    $social = array();
    foreach ( ea_react_social_fields() as $setting => $meta ) {
        $social[ $meta['key'] ] = esc_url( get_theme_mod( $setting, $meta['default'] ) );
    }
    return $social;
}

// ─── Button links via the Customizer (Appearance → Customize → EA Button Links) ─
// Each button can smooth-scroll to a page section OR link to a URL (section wins).
function ea_button_link_fields() {
    return array(
        // Hero buttons can't be hidden (no 'hideable') — they anchor the page.
        'ea_link_hero_primary'   => array( 'key' => 'heroPrimary',    'label' => 'Hero — Primary button' ),
        'ea_link_hero_secondary' => array( 'key' => 'heroSecondary',  'label' => 'Hero — Secondary button' ),
        'ea_link_nav_cta'        => array( 'key' => 'navCta',         'label' => 'Navigation — CTA button',          'hideable' => true ),
        'ea_link_programs_view_all' => array( 'key' => 'programsViewAll', 'label' => 'Active Programs — View All button' ),
        'ea_link_coaching'       => array( 'key' => 'coachingCta',    'label' => 'Coaching — button',                'hideable' => true ),
        'ea_link_partnerships'   => array( 'key' => 'partnershipsCta', 'label' => 'Community — Partnerships button',  'hideable' => true ),
        'ea_link_leaders'        => array( 'key' => 'leadersCta',     'label' => 'Community — Leaders button',        'hideable' => true ),
        'ea_link_guide_box_1'    => array( 'key' => 'guideBox1',      'label' => 'Basketball Guide — Box 1 button' ),
        'ea_link_guide_box_2'    => array( 'key' => 'guideBox2',      'label' => 'Basketball Guide — Box 2 button' ),
        'ea_link_guide_box_3'    => array( 'key' => 'guideBox3',      'label' => 'Basketball Guide — Box 3 button' ),
        'ea_link_guide_box_4'    => array( 'key' => 'guideBox4',      'label' => 'Basketball Guide — Box 4 button' ),
        'ea_link_guide_box_5'    => array( 'key' => 'guideBox5',      'label' => 'Basketball Guide — Box 5 button' ),
        'ea_link_guide_box_6'    => array( 'key' => 'guideBox6',      'label' => 'Basketball Guide — Box 6 button' ),
        'ea_link_rep_tryouts_hero'     => array( 'key' => 'repTryoutsHero',     'label' => 'Basketball Rep — Hero register button' ),
        'ea_link_rep_tryouts_schedule' => array( 'key' => 'repTryoutsSchedule', 'label' => 'Basketball Rep — See schedule button' ),
        'ea_link_rep_tryouts_waiver'   => array( 'key' => 'repTryoutsWaiver',   'label' => 'Basketball Rep — Waiver button' ),
        'ea_link_camp_card_1'          => array( 'key' => 'campCard1',          'label' => 'Camps — Card 1 button' ),
        'ea_link_camp_card_2'          => array( 'key' => 'campCard2',          'label' => 'Camps — Card 2 button' ),
        'ea_link_camp_card_3'          => array( 'key' => 'campCard3',          'label' => 'Camps — Card 3 button' ),
        'ea_link_camp_card_4'          => array( 'key' => 'campCard4',          'label' => 'Camps — Card 4 button' ),
    );
}

// Sections a button can scroll to. Keys match the `id`s set on the React sections.
function ea_section_choices() {
    return array(
        ''                => '— none (use URL) —',
        'hero'            => 'Hero (top of page)',
        'new-programs'    => 'New Programs / Free Trial',
        'active-programs' => 'Active Programs',
        'coaching'        => 'Coaching',
        'community'       => 'Community',
        'newsletter'      => 'Newsletter',
        'rep-tryouts'     => 'Basketball Rep Tryouts form',
        'rep-schedule'    => 'Basketball Rep Schedule',
        'camps'           => 'Camps page',
    );
}

function ea_sanitize_section( $value ) {
    return array_key_exists( (string) $value, ea_section_choices() ) ? (string) $value : '';
}

function ea_react_links() {
    $links = array();
    foreach ( ea_button_link_fields() as $slug => $meta ) {
        $links[ $meta['key'] ] = array(
            'url'     => esc_url( get_theme_mod( $slug, '' ) ),
            'section' => ea_sanitize_section( get_theme_mod( $slug . '_section', '' ) ),
            // Hideable buttons expose a "Hide this button" toggle; hero buttons never hide.
            'hidden'  => ! empty( $meta['hideable'] ) && (bool) get_theme_mod( $slug . '_hidden', false ),
        );
    }
    return $links;
}

function ea_customize_links( $wp_customize ) {
    $wp_customize->add_section( 'ea_links', array(
        'title'       => __( 'EA Button Links', 'ea-react-theme' ),
        'description' => __( 'Give each button a destination. Choose a section to smooth-scroll there, or leave it on "none" and enter a URL.', 'ea-react-theme' ),
        'priority'    => 34,
    ) );
    foreach ( ea_button_link_fields() as $slug => $meta ) {
        if ( 0 === strpos( $slug, 'ea_link_camp_card_' ) ) {
            continue;
        }

        // Scroll-to-section select.
        $wp_customize->add_setting( $slug . '_section', array(
            'default'           => '',
            'sanitize_callback' => 'ea_sanitize_section',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( $slug . '_section', array(
            'type'    => 'select',
            'label'   => $meta['label'] . ' — scroll to section',
            'choices' => ea_section_choices(),
            'section' => 'ea_links',
        ) );
        // URL (used when no section is chosen).
        $wp_customize->add_setting( $slug, array(
            'default'           => '',
            'sanitize_callback' => 'esc_url_raw',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( $slug, array(
            'type'    => 'url',
            'label'   => $meta['label'] . ' — or link URL',
            'section' => 'ea_links',
        ) );
        // Hide toggle (hero buttons are always shown, so no toggle for them).
        if ( ! empty( $meta['hideable'] ) ) {
            $wp_customize->add_setting( $slug . '_hidden', array(
                'default'           => false,
                'sanitize_callback' => 'wp_validate_boolean',
                'transport'         => 'refresh',
            ) );
            $wp_customize->add_control( $slug . '_hidden', array(
                'type'    => 'checkbox',
                'label'   => $meta['label'] . ' — hide this button',
                'section' => 'ea_links',
            ) );
        }
    }
}
add_action( 'customize_register', 'ea_customize_links' );

function ea_customize_social( $wp_customize ) {
    $wp_customize->add_section( 'ea_social', array(
        'title'       => __( 'EA Social Links', 'ea-react-theme' ),
        'description' => __( 'Links for the footer social icons. Clear a field to hide that icon.', 'ea-react-theme' ),
        'priority'    => 33,
    ) );
    foreach ( ea_react_social_fields() as $setting => $meta ) {
        $wp_customize->add_setting( $setting, array(
            'default'           => $meta['default'],
            'sanitize_callback' => 'esc_url_raw',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( $setting, array(
            'type'    => 'url',
            'label'   => $meta['label'],
            'section' => 'ea_social',
        ) );
    }
}
add_action( 'customize_register', 'ea_customize_social' );

function ea_customize_options( $wp_customize ) {
    $wp_customize->add_section( 'ea_options', array(
        'title'    => __( 'EA Options', 'ea-react-theme' ),
        'priority' => 32,
    ) );
    $wp_customize->add_setting( 'ea_use_carousel', array(
        'default'           => true,
        'sanitize_callback' => 'wp_validate_boolean',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( 'ea_use_carousel', array(
        'type'        => 'checkbox',
        'label'       => __( 'Show photo carousel', 'ea-react-theme' ),
        'description' => __( 'Uncheck to show the Free Trial registration form instead.', 'ea-react-theme' ),
        'section'     => 'ea_options',
    ) );

    $wp_customize->add_setting( 'ea_show_hero_image', array(
        'default'           => true,
        'sanitize_callback' => 'wp_validate_boolean',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( 'ea_show_hero_image', array(
        'type'        => 'checkbox',
        'label'       => __( 'Show hero image', 'ea-react-theme' ),
        'description' => __( 'Shows the standalone homepage hero image below the intro buttons on desktop and mobile.', 'ea-react-theme' ),
        'section'     => 'ea_options',
    ) );

    $wp_customize->add_setting( 'ea_hide_near_me', array(
        'default'           => false,
        'sanitize_callback' => 'wp_validate_boolean',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( 'ea_hide_near_me', array(
        'type'    => 'checkbox',
        'label'   => __( 'Hide "Programs Near Me" button', 'ea-react-theme' ),
        'section' => 'ea_options',
    ) );

    $wp_customize->add_setting( 'ea_hide_view_all', array(
        'default'           => false,
        'sanitize_callback' => 'wp_validate_boolean',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( 'ea_hide_view_all', array(
        'type'    => 'checkbox',
        'label'   => __( 'Hide "View All Programs" button', 'ea-react-theme' ),
        'section' => 'ea_options',
    ) );

    $wp_customize->add_setting( 'ea_programs_show_description', array(
        'default'           => false,
        'sanitize_callback' => 'wp_validate_boolean',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( 'ea_programs_show_description', array(
        'type'        => 'checkbox',
        'label'       => __( 'Active Programs — show description', 'ea-react-theme' ),
        'description' => __( 'Shows the Active Programs description text under the section heading.', 'ea-react-theme' ),
        'section'     => 'ea_options',
    ) );

    $league_hub_toggles = array(
        'ea_league_hub_show_subheading' => array( 'default' => false, 'label' => __( 'League Hub — show subheading', 'ea-react-theme' ) ),
        'ea_league_hub_filter_search'   => array( 'default' => true, 'label' => __( 'League Hub — show search filter', 'ea-react-theme' ) ),
        'ea_league_hub_filter_level'    => array( 'default' => true, 'label' => __( 'League Hub — show skill level filter', 'ea-react-theme' ) ),
        'ea_league_hub_filter_type'     => array( 'default' => true, 'label' => __( 'League Hub — show program type filter', 'ea-react-theme' ) ),
        'ea_league_hub_filter_age'      => array( 'default' => true, 'label' => __( 'League Hub — show age filter', 'ea-react-theme' ) ),
        'ea_league_hub_filter_time'     => array( 'default' => true, 'label' => __( 'League Hub — show time filter', 'ea-react-theme' ) ),
        'ea_league_hub_filter_days'     => array( 'default' => true, 'label' => __( 'League Hub — show days filter', 'ea-react-theme' ) ),
        'ea_league_hub_filter_location' => array( 'default' => true, 'label' => __( 'League Hub — show location filter', 'ea-react-theme' ) ),
        'ea_league_hub_filter_province' => array( 'default' => false, 'label' => __( 'League Hub — show province filter', 'ea-react-theme' ) ),
        'ea_league_hub_show_location_button' => array( 'default' => false, 'label' => __( 'League Hub — show location button', 'ea-react-theme' ) ),
        'ea_league_hub_show_map_view'    => array( 'default' => true, 'label' => __( 'League Hub — show map view', 'ea-react-theme' ) ),
        'ea_league_hub_show_calendar_view' => array( 'default' => true, 'label' => __( 'League Hub — show calendar view', 'ea-react-theme' ) ),
        'ea_league_hub_show_coming_soon' => array( 'default' => false, 'label' => __( 'League Hub — show Coming Soon programs', 'ea-react-theme' ) ),
    );
    foreach ( $league_hub_toggles as $setting => $meta ) {
        $wp_customize->add_setting( $setting, array(
            'default'           => $meta['default'],
            'sanitize_callback' => 'wp_validate_boolean',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( $setting, array(
            'type'    => 'checkbox',
            'label'   => $meta['label'],
            'section' => 'ea_options',
        ) );
    }

    $wp_customize->add_setting( 'ea_free_trial_sessions', array(
        'default'           => EA_FREE_TRIAL_SESSIONS_DEFAULT,
        'sanitize_callback' => 'sanitize_textarea_field',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( 'ea_free_trial_sessions', array(
        'type'        => 'textarea',
        'label'       => __( 'Free Trial — session choices', 'ea-react-theme' ),
        'description' => __( 'One choice per line. These populate the "Choose Session" dropdown in the Free Trial form.', 'ea-react-theme' ),
        'section'     => 'ea_options',
    ) );

    $wp_customize->add_setting( 'ea_free_trial_notification_emails', array(
        'default'           => '',
        'sanitize_callback' => 'sanitize_textarea_field',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( 'ea_free_trial_notification_emails', array(
        'type'        => 'textarea',
        'label'       => __( 'Free Trial — notification emails', 'ea-react-theme' ),
        'description' => __( 'Comma- or line-separated emails that should receive Free Trial notifications. Leave blank to use the WordPress admin email.', 'ea-react-theme' ),
        'section'     => 'ea_options',
    ) );

    $wp_customize->add_setting( 'ea_sports', array(
        'default'           => ea_default_sports_selection(),
        'sanitize_callback' => 'ea_sanitize_sports',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( new EA_Multi_Select_Control( $wp_customize, 'ea_sports', array(
        'label'       => __( 'Active Programs sports', 'ea-react-theme' ),
        'description' => __( 'Select which sports appear in the homepage Active Programs section. Hold Ctrl (Windows) or Cmd (Mac) to pick more than one.', 'ea-react-theme' ),
        'section'     => 'ea_options',
        'choices'     => ea_sport_choices(),
    ) ) );

    $wp_customize->add_setting( 'ea_league_hub_sports', array(
        'default'           => ea_default_sports_selection(),
        'sanitize_callback' => 'ea_sanitize_sports',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( new EA_Multi_Select_Control( $wp_customize, 'ea_league_hub_sports', array(
        'label'       => __( 'League Hub sports', 'ea-react-theme' ),
        'description' => __( 'Select which sports appear on the League Hub / View All Programs page. Hold Ctrl (Windows) or Cmd (Mac) to pick more than one.', 'ea-react-theme' ),
        'section'     => 'ea_options',
        'choices'     => ea_sport_choices(),
    ) ) );
}
add_action( 'customize_register', 'ea_customize_options' );

function ea_sanitize_map_embed_field( $value ) {
    $allowed = array(
        'iframe' => array(
            'src'            => true,
            'width'          => true,
            'height'         => true,
            'style'          => true,
            'allowfullscreen'=> true,
            'loading'        => true,
            'referrerpolicy' => true,
            'title'          => true,
        ),
    );

    return wp_kses( (string) $value, $allowed );
}

function ea_customize_texts( $wp_customize ) {
    $wp_customize->add_section( 'ea_text', array(
        'title'       => __( 'EA Text', 'ea-react-theme' ),
        'description' => __( 'Edit the marketing copy shown across the home page. Clear a field to restore its default.', 'ea-react-theme' ),
        'priority'    => 31,
    ) );

    foreach ( ea_react_text_fields() as $setting => $meta ) {
        if ( 0 === strpos( $setting, 'ea_txt_camps_' ) || 0 === strpos( $setting, 'ea_txt_camp_card_' ) ) {
            continue;
        }

        $map_embed_fields = array(
            'ea_txt_basketball_rep_map_1_embed',
            'ea_txt_basketball_rep_map_2_embed',
        );
        if ( in_array( $setting, $map_embed_fields, true ) ) {
            $sanitize = 'ea_sanitize_map_embed_field';
        } else {
            $sanitize = ( 'textarea' === $meta['type'] ) ? 'sanitize_textarea_field' : 'sanitize_text_field';
        }
        $wp_customize->add_setting( $setting, array(
            'default'           => $meta['default'],
            'sanitize_callback' => $sanitize,
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( $setting, array(
            'type'     => $meta['type'],
            'label'    => $meta['label'],
            'section'  => 'ea_text',
            'settings' => $setting,
        ) );
    }
}
add_action( 'customize_register', 'ea_customize_texts' );

function ea_customize_camps_page( $wp_customize ) {
    $wp_customize->add_section( 'ea_camps_page', array(
        'title'       => __( 'EA Camps Page', 'ea-react-theme' ),
        'description' => __( 'Edit the camps landing page. Toggle a camp card off to remove it from the page; toggle an empty card on to add a new one.', 'ea-react-theme' ),
        'priority'    => 32,
    ) );

    $page_fields = array(
        'heading'        => array( 'label' => 'Heading', 'type' => 'text' ),
        'subheading'     => array( 'label' => 'Subheading', 'type' => 'textarea' ),
        'intro'          => array( 'label' => 'Intro text', 'type' => 'textarea' ),
        'contactHeading' => array( 'label' => 'Contact prompt — heading', 'type' => 'text' ),
        'contactText'    => array( 'label' => 'Contact prompt — text', 'type' => 'textarea' ),
        'contactButton'  => array( 'label' => 'Contact prompt — button text', 'type' => 'text' ),
        'contactLink'    => array( 'label' => 'Contact prompt — button link', 'type' => 'url' ),
    );
    $defaults = ea_camps_page_defaults();

    foreach ( $page_fields as $key => $meta ) {
        $setting = 'ea_camps_' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $key ) );
        $sanitize = 'url' === $meta['type'] ? 'esc_url_raw' : ( 'textarea' === $meta['type'] ? 'sanitize_textarea_field' : 'sanitize_text_field' );

        $wp_customize->add_setting( $setting, array(
            'default'           => $defaults[ $key ],
            'sanitize_callback' => $sanitize,
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( $setting, array(
            'type'     => $meta['type'],
            'label'    => $meta['label'],
            'section'  => 'ea_camps_page',
            'settings' => $setting,
        ) );
    }

    $card_defaults = ea_camp_card_defaults();
    for ( $i = 1; $i <= EA_CAMPS_CARD_SLOTS; $i++ ) {
        $default = isset( $card_defaults[ $i ] ) ? $card_defaults[ $i ] : array(
            'enabled'  => false,
            'label'    => '',
            'heading'  => '',
            'ageRange' => '',
            'text'     => '',
            'button'   => 'Learn More',
            'link'     => '',
            'image'    => '',
        );

        $wp_customize->add_setting( "ea_camps_card_{$i}_enabled", array(
            'default'           => $default['enabled'],
            'sanitize_callback' => 'wp_validate_boolean',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( "ea_camps_card_{$i}_enabled", array(
            'type'    => 'checkbox',
            'label'   => sprintf( 'Camp card %d — show on page', $i ),
            'section' => 'ea_camps_page',
        ) );

        $card_text_fields = array(
            'label'     => array( 'label' => sprintf( 'Camp card %d — sport/type label', $i ), 'type' => 'text', 'default_key' => 'label' ),
            'heading'   => array( 'label' => sprintf( 'Camp card %d — heading', $i ), 'type' => 'text', 'default_key' => 'heading' ),
            'age_range' => array( 'label' => sprintf( 'Camp card %d — age range', $i ), 'type' => 'text', 'default_key' => 'ageRange' ),
            'text'      => array( 'label' => sprintf( 'Camp card %d — short description', $i ), 'type' => 'textarea', 'default_key' => 'text' ),
            'button'    => array( 'label' => sprintf( 'Camp card %d — button text', $i ), 'type' => 'text', 'default_key' => 'button' ),
            'link'      => array( 'label' => sprintf( 'Camp card %d — button/image link', $i ), 'type' => 'url', 'default_key' => 'link' ),
        );

        foreach ( $card_text_fields as $field => $meta ) {
            $setting = "ea_camps_card_{$i}_{$field}";
            $sanitize = 'url' === $meta['type'] ? 'esc_url_raw' : ( 'textarea' === $meta['type'] ? 'sanitize_textarea_field' : 'sanitize_text_field' );
            $wp_customize->add_setting( $setting, array(
                'default'           => $default[ $meta['default_key'] ],
                'sanitize_callback' => $sanitize,
                'transport'         => 'refresh',
            ) );
            $wp_customize->add_control( $setting, array(
                'type'     => $meta['type'],
                'label'    => $meta['label'],
                'section'  => 'ea_camps_page',
                'settings' => $setting,
            ) );
        }

        $wp_customize->add_setting( "ea_camps_card_{$i}_image", array(
            'default'           => $default['image'],
            'sanitize_callback' => 'esc_url_raw',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, "ea_camps_card_{$i}_image", array(
            'label'    => sprintf( 'Camp card %d — image', $i ),
            'section'  => 'ea_camps_page',
            'settings' => "ea_camps_card_{$i}_image",
        ) ) );
    }
}
add_action( 'customize_register', 'ea_customize_camps_page' );

// ─── FAQ questions & answers via the Customizer (Appearance → Customize → EA FAQ) ─
// The FAQ page (the WordPress Page with slug "faq") renders these rows as
// collapsible cards. Each slot is a question (text) + answer (basic HTML allowed,
// e.g. links) + an "open by default" toggle. Clear a question to hide that row.
// ea_react_faqs() collects the non-empty rows for wp_localize_script so React
// reads them from window.eaReactData.faqs.

// How many editable FAQ slots to expose in the Customizer.
const EA_FAQ_SLOTS = 12;

// Seed content for the first slots (mirrors the FAQ page's built-in defaults).
// A cleared question hides its row; unedited slots keep the copy below.
function ea_faq_defaults() {
    return array(
        array(
            'q'    => 'What badminton programs does Elevation Athletics offer?',
            'a'    => 'We offer badminton lessons, leagues, camps, and seasonal programs for youth players. Available programs vary by city and season, so check the active programs section for the most up-to-date options.',
            'open' => true,
        ),
        array(
            'q'    => 'Do players need their own badminton racquet?',
            'a'    => 'Players are encouraged to bring their own racquet if they have one. If your child is new and does not have equipment yet, contact us before the program starts and we can let you know what is available.',
            'open' => true,
        ),
        array( 'q' => 'What should players bring to each session?', 'a' => 'Players should bring indoor court shoes, athletic clothing, a water bottle, and a badminton racquet if they have one.' ),
        array( 'q' => 'How long is each program?', 'a' => 'Most programs run for multiple weekly sessions, and the exact number of sessions, dates, and times are listed on the registration card.' ),
        array( 'q' => 'Where do the programs take place?', 'a' => 'Program locations vary by city. Each registration card lists the school, community centre, or facility where that program runs.' ),
        array( 'q' => 'How long does a league season run?', 'a' => 'Season length varies by location. Each town’s registration page lists the exact number of weeks, dates, and times.' ),
        array( 'q' => 'Can my child join after the program has already started?', 'a' => 'Sometimes, yes. If registration is still open and spots are available, late registration may be possible. If enrollment is closed, contact info@elevationathletics.ca to ask about options.' ),
        array( 'q' => 'What happens if a session is cancelled?', 'a' => 'If a session is cancelled due to facility closures, weather, or another issue, we will communicate updates by email and provide details about the next steps.' ),
        array( 'q' => 'Are there make-up classes if my child misses a session?', 'a' => 'We generally cannot guarantee make-up classes for missed sessions, but you can contact us if there are special circumstances.' ),
        array( 'q' => 'What age groups are available?', 'a' => 'Age groups vary by program. Each registration card lists the eligible age range, such as junior programs, youth programs, or advanced junior programs.' ),
    );
}

// The slot's default (question/answer/open) or an empty row when unseeded.
function ea_faq_default_for( $i ) {
    $defaults = ea_faq_defaults();
    return isset( $defaults[ $i - 1 ] )
        ? $defaults[ $i - 1 ]
        : array( 'q' => '', 'a' => '', 'open' => false );
}

function ea_react_faqs() {
    $out = array();
    for ( $i = 1; $i <= EA_FAQ_SLOTS; $i++ ) {
        $d = ea_faq_default_for( $i );
        $q = trim( (string) get_theme_mod( "ea_faq_q_$i", $d['q'] ) );
        if ( '' === $q ) {
            continue; // cleared question → row hidden
        }
        $out[] = array(
            'q'    => $q,
            'a'    => wp_kses_post( get_theme_mod( "ea_faq_a_$i", $d['a'] ) ),
            'open' => (bool) get_theme_mod( "ea_faq_open_$i", ! empty( $d['open'] ) ),
        );
    }
    return $out;
}

function ea_customize_faqs( $wp_customize ) {
    $wp_customize->add_section( 'ea_faq', array(
        'title'       => __( 'EA FAQ', 'ea-react-theme' ),
        'description' => __( 'Questions & answers for the FAQ page (the WordPress Page with slug “faq”). Clear a question to hide that row. Answers may include basic HTML such as links.', 'ea-react-theme' ),
        'priority'    => 33,
    ) );

    for ( $i = 1; $i <= EA_FAQ_SLOTS; $i++ ) {
        $d = ea_faq_default_for( $i );

        $wp_customize->add_setting( "ea_faq_q_$i", array(
            'default'           => $d['q'],
            'sanitize_callback' => 'sanitize_text_field',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( "ea_faq_q_$i", array(
            'type'    => 'text',
            /* translators: %d: FAQ row number */
            'label'   => sprintf( __( 'Q%d — Question', 'ea-react-theme' ), $i ),
            'section' => 'ea_faq',
        ) );

        $wp_customize->add_setting( "ea_faq_a_$i", array(
            'default'           => $d['a'],
            'sanitize_callback' => 'wp_kses_post',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( "ea_faq_a_$i", array(
            'type'    => 'textarea',
            /* translators: %d: FAQ row number */
            'label'   => sprintf( __( 'Q%d — Answer (basic HTML allowed)', 'ea-react-theme' ), $i ),
            'section' => 'ea_faq',
        ) );

        $wp_customize->add_setting( "ea_faq_open_$i", array(
            'default'           => ! empty( $d['open'] ),
            'sanitize_callback' => 'wp_validate_boolean',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( "ea_faq_open_$i", array(
            'type'    => 'checkbox',
            /* translators: %d: FAQ row number */
            'label'   => sprintf( __( 'Q%d — Open by default', 'ea-react-theme' ), $i ),
            'section' => 'ea_faq',
        ) );
    }
}
add_action( 'customize_register', 'ea_customize_faqs' );

// ─── Free Trial form submissions (custom REST endpoint) ───────────────────────
// The React Free Trial form POSTs here. We validate, then email the registration
// to the site admin via wp_mail(). Locally, the email is caught by Local's Mailpit
// (Site → Tools → Open Mailpit) — no real SMTP needed for testing.
function ea_register_free_trial_route() {
    register_rest_route( 'ea/v1', '/free-trial', array(
        'methods'             => 'POST',
        'permission_callback' => '__return_true', // public form; anyone can submit
        'callback'            => 'ea_handle_free_trial',
        'args'                => array(
            'name'    => array( 'required' => true,  'type' => 'string' ),
            'email'   => array( 'required' => true,  'type' => 'string' ),
            'phone'   => array( 'required' => false, 'type' => 'string' ),
            'city'    => array( 'required' => false, 'type' => 'string' ),
            'ageRange' => array( 'required' => false, 'type' => 'string' ),
            'session' => array( 'required' => false, 'type' => 'string' ),
            'source'  => array( 'required' => false, 'type' => 'string' ),
            // Honeypot: real users leave this empty; bots tend to fill every field.
            'website' => array( 'required' => false, 'type' => 'string' ),
        ),
    ) );
}
add_action( 'rest_api_init', 'ea_register_free_trial_route' );

function ea_handle_free_trial( WP_REST_Request $request ) {
    // Spam honeypot — silently accept so bots don't retry, but send nothing.
    if ( ! empty( $request['website'] ) ) {
        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    $name    = sanitize_text_field( wp_unslash( $request['name'] ) );
    $email   = sanitize_email( wp_unslash( $request['email'] ) );
    $phone     = isset( $request['phone'] ) ? sanitize_text_field( wp_unslash( $request['phone'] ) ) : '';
    $city      = isset( $request['city'] ) ? sanitize_text_field( wp_unslash( $request['city'] ) ) : ea_default_city_value();
    $age_range = isset( $request['ageRange'] ) ? sanitize_text_field( wp_unslash( $request['ageRange'] ) ) : '';
    $session   = isset( $request['session'] ) ? sanitize_text_field( wp_unslash( $request['session'] ) ) : '';
    $source    = isset( $request['source'] ) ? sanitize_text_field( wp_unslash( $request['source'] ) ) : 'free-trial';

    if ( '' === $name || '' === $email || ! is_email( $email ) || '' === $phone || '' === $session ) {
        return new WP_Error(
            'ea_invalid',
            'Please provide a valid name, email, phone number, and session.',
            array( 'status' => 422 )
        );
    }

    // 1) Store the submission as an ea_free_trial entry (viewable in wp-admin →
    //    Free Trials). This is the record of truth, so we do it first.
    $entry_id = wp_insert_post( array(
        'post_type'   => 'ea_free_trial',
        'post_status' => 'publish',
        'post_title'  => $name,
    ), true );

    if ( is_wp_error( $entry_id ) || ! $entry_id ) {
        return new WP_Error(
            'ea_store_failed',
            'Sorry, something went wrong saving your registration. Please try again.',
            array( 'status' => 500 )
        );
    }

    update_post_meta( $entry_id, '_ea_email', $email );
    update_post_meta( $entry_id, '_ea_phone', $phone );
    update_post_meta( $entry_id, '_ea_city', $city );
    update_post_meta( $entry_id, '_ea_age_range', $age_range );
    update_post_meta( $entry_id, '_ea_session', $session );
    update_post_meta( $entry_id, '_ea_source', $source );

    // 2) Email the admin as a notification (best-effort — the entry is already
    //    saved, so a mail hiccup must not fail the submission). Locally this is
    //    caught by Local's Mailpit (Site → Tools → Open Mailpit).
    $to      = ea_free_trial_notification_emails( $source );
    $subject = 'New free trial registration';
    $body    = "A new free trial registration was submitted:\n\n"
             . "Athlete's Name: {$name}\n"
             . "Email: {$email}\n"
             . "Phone: {$phone}\n"
             . "City: {$city}\n"
             . "Age Range: " . ( '' !== $age_range ? $age_range : '(not specified)' ) . "\n"
             . "Session: " . ( '' !== $session ? $session : '(not specified)' ) . "\n";
    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        // Lets the admin hit "Reply" to respond to the registrant.
        'Reply-To: ' . $name . ' <' . $email . '>',
    );
    wp_mail( $to, $subject, $body, $headers );

    $sport = ea_default_sport_value();
    $confirmation_subject = "We've received your EA {$sport} free trial request";
    $confirmation_body    = "Hi {$name},\n\n"
        . "Thanks for registering for a free trial with EA {$sport}. We received your request with the details below:\n\n"
        . "Phone: {$phone}\n"
        . "City: {$city}\n"
        . ( '' !== $age_range ? "Age Range: {$age_range}\n" : '' )
        . "Session: {$session}\n\n"
        . "Our team will follow up if anything changes before your selected session.\n\n"
        . "Thanks,\n"
        . "Elevation Athletics";
    $confirmation_headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . ea_free_trial_reply_to_email( $source ),
    );
    wp_mail( $email, $confirmation_subject, $confirmation_body, $confirmation_headers );

    return new WP_REST_Response( array( 'ok' => true, 'id' => (int) $entry_id ), 200 );
}

function ea_free_trial_reply_to_email( $source = '' ) {
    if ( 'city-programs' === $source ) {
        return 'daniel@elevationathletics.ca';
    }

    $admin_email = sanitize_email( get_option( 'admin_email' ) );
    return is_email( $admin_email ) ? $admin_email : 'daniel@elevationathletics.ca';
}

function ea_free_trial_notification_emails( $source = '' ) {
    if ( 'city-programs' === $source ) {
        return 'daniel@elevationathletics.ca';
    }

    $raw = (string) get_theme_mod( 'ea_free_trial_notification_emails', '' );
    $emails = preg_split( '/[\s,;]+/', $raw );
    $emails = array_filter( array_map( 'sanitize_email', (array) $emails ), 'is_email' );

    if ( empty( $emails ) ) {
        return ea_free_trial_reply_to_email( $source );
    }

    return array_values( array_unique( $emails ) );
}

// ─── Venue coordinate resolver (map pins for venues not in the baked table) ───
// src/data/venueCoords.generated.js is a build-time snapshot. Any venue added to
// the programs feed after that snapshot has no coordinates, so it silently drops
// off the map ("N programs aren't shown"). Re-running the generator and
// re-uploading the theme fixes it only until the next new venue, so the gap
// always comes back.
//
// The browser can't resolve the feed's maps.app.goo.gl links itself: Google
// sends no Access-Control-Allow-Origin and marks the response same-site, so a
// cross-origin fetch is blocked. This proxies the lookup server-side instead.
//
// The coordinates live in the redirect TARGET (!3d<lat>!4d<lng>), so this only
// walks the redirect chain and reads Location headers - no API key, no
// geocoding service, and no response body is ever parsed.
//
// Each result is cached in a transient effectively forever, since a venue's
// coordinates don't change. Only the first visitor after a venue appears pays
// the lookup; everyone after that is served from cache.
define( 'EA_VENUE_COORD_TTL',       YEAR_IN_SECONDS );
define( 'EA_VENUE_COORD_FAIL_TTL',  6 * HOUR_IN_SECONDS ); // retry broken links, but not on every view
define( 'EA_VENUE_COORD_MAX_LINKS', 80 );  // per request, caps abuse
define( 'EA_VENUE_COORD_MAX_FRESH', 12 );  // new lookups per request, keeps the response quick
define( 'EA_VENUE_COORD_BUDGET',    8.0 ); // seconds spent on new lookups per request

/**
 * Only ever fetch Google's own map hosts. Without this the endpoint would be an
 * open proxy: anyone could POST an internal address and have the server fetch it.
 */
function ea_venue_coord_allowed_host( $url ) {
    $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    return in_array( $host, array(
        'maps.app.goo.gl', 'goo.gl',
        'maps.google.com', 'www.google.com', 'google.com',
        'maps.google.ca',  'www.google.ca',  'google.ca',
    ), true );
}

/** Port of extractCoords() in tools/build-venue-coords.mjs - keep the two in step. */
function ea_venue_coord_extract( $text ) {
    // !3d!4d is the authoritative place coordinate.
    if ( preg_match( '/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/', $text, $m ) ) {
        return array( 'lat' => (float) $m[1], 'lng' => (float) $m[2] );
    }
    // /@lat,lng is the viewport centre: usually identical, but drifts if the
    // link was made from a panned view. Fallback only.
    if ( preg_match( '/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', $text, $m ) ) {
        return array( 'lat' => (float) $m[1], 'lng' => (float) $m[2] );
    }
    if ( preg_match( '/[?&](?:q|ll|center)=(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', $text, $m ) ) {
        return array( 'lat' => (float) $m[1], 'lng' => (float) $m[2] );
    }
    return null;
}

/** Canada-wide gate. Catches a redirect that landed on a consent or generic page. */
function ea_venue_coord_in_bbox( $c ) {
    return $c
        && $c['lat'] >= 41 && $c['lat'] <= 84
        && $c['lng'] >= -142 && $c['lng'] <= -52;
}

function ea_venue_coord_resolve( $link ) {
    $url = $link;

    for ( $hop = 0; $hop < 5; $hop++ ) {
        // Re-checked every hop: the chain must never walk off Google's hosts.
        if ( ! ea_venue_coord_allowed_host( $url ) ) {
            return null;
        }

        $res = wp_remote_get( $url, array(
            'timeout'     => 6,
            'redirection' => 0, // walk the chain by hand so each Location is readable
            // Deliberately a bare UA. Google serves a JS app shell with no
            // coordinates to anything resembling a real browser, and a plain
            // 302 to simple clients. A full Chrome UA breaks every lookup.
            'user-agent'  => 'Mozilla/5.0',
            'headers'     => array( 'Accept-Language' => 'en-CA,en;q=0.9' ),
        ) );
        if ( is_wp_error( $res ) ) {
            return null;
        }

        $next = wp_remote_retrieve_header( $res, 'location' );
        if ( is_array( $next ) ) {
            $next = reset( $next );
        }

        $candidate = $next ? $next : $url;
        $coords    = ea_venue_coord_extract( $candidate );
        if ( $coords ) {
            return ea_venue_coord_in_bbox( $coords ) ? $coords : null;
        }

        if ( ! $next ) {
            break;
        }
        // Resolve a relative Location against the URL that produced it.
        $url = ( 0 === strpos( $next, 'http' ) )
            ? $next
            : ( 'https://' . wp_parse_url( $url, PHP_URL_HOST ) . '/' . ltrim( $next, '/' ) );
    }

    return null;
}

function ea_register_venue_coords_route() {
    register_rest_route( 'ea/v1', '/venue-coords', array(
        'methods'             => 'POST',
        'permission_callback' => '__return_true', // public map data, no user data involved
        'callback'            => 'ea_handle_venue_coords',
    ) );
}
add_action( 'rest_api_init', 'ea_register_venue_coords_route' );

function ea_handle_venue_coords( WP_REST_Request $request ) {
    $links = $request->get_param( 'links' );
    if ( ! is_array( $links ) ) {
        return new WP_Error( 'ea_invalid', 'links must be an array.', array( 'status' => 422 ) );
    }

    $links   = array_slice( array_unique( array_filter( array_map( 'strval', $links ) ) ), 0, EA_VENUE_COORD_MAX_LINKS );
    $out     = array();
    $pending = array();
    $fresh   = 0;
    $deadline = microtime( true ) + EA_VENUE_COORD_BUDGET;

    foreach ( $links as $link ) {
        $link = trim( $link );
        if ( '' === $link || ! ea_venue_coord_allowed_host( $link ) ) {
            continue;
        }

        $key    = 'ea_vc_' . md5( strtolower( rtrim( $link, '/' ) ) );
        $cached = get_transient( $key );

        if ( is_array( $cached ) ) {
            $out[ $link ] = $cached;
            continue;
        }
        if ( 'fail' === $cached ) {
            continue; // known bad, don't retry until the short TTL lapses
        }

        // Resolve a slice now and report the rest as pending, so the first view
        // after a batch of new venues stays fast instead of blocking on dozens
        // of sequential lookups. The client asks again for what's left.
        if ( $fresh >= EA_VENUE_COORD_MAX_FRESH || microtime( true ) > $deadline ) {
            $pending[] = $link;
            continue;
        }

        $fresh++;
        $coords = ea_venue_coord_resolve( $link );
        if ( $coords ) {
            set_transient( $key, $coords, EA_VENUE_COORD_TTL );
            $out[ $link ] = $coords;
        } else {
            set_transient( $key, 'fail', EA_VENUE_COORD_FAIL_TTL );
        }
    }

    return new WP_REST_Response( array(
        'coords'  => (object) $out,
        'pending' => $pending,
    ), 200 );
}

// ─── "Free Trials" admin screen (stores + lists form submissions) ─────────────
// A private post type that only the form writes to. Admins view submissions under
// wp-admin → Free Trials; "Add New" is disabled since entries come from the form.
function ea_register_free_trial_cpt() {
    register_post_type( 'ea_free_trial', array(
        'labels' => array(
            'name'          => __( 'Free Trials', 'ea-react-theme' ),
            'singular_name' => __( 'Free Trial', 'ea-react-theme' ),
            'menu_name'     => __( 'Free Trials', 'ea-react-theme' ),
            'all_items'     => __( 'All Registrations', 'ea-react-theme' ),
            'search_items'  => __( 'Search Registrations', 'ea-react-theme' ),
        ),
        'public'              => false,   // not a front-end URL
        'show_ui'             => true,    // but visible in wp-admin
        'show_in_menu'        => true,
        'menu_icon'           => 'dashicons-clipboard',
        'menu_position'       => 26,
        'supports'            => array( 'title' ),
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        // No manual creation — registrations only arrive via the form endpoint.
        'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
        'exclude_from_search' => true,
    ) );
}
add_action( 'init', 'ea_register_free_trial_cpt' );

function ea_default_sport_value() {
    return defined( 'EA_CC_DEFAULT_SPORT' ) && '' !== trim( (string) EA_CC_DEFAULT_SPORT )
        ? sanitize_text_field( EA_CC_DEFAULT_SPORT )
        : 'Basketball';
}

function ea_default_region_value() {
    return defined( 'EA_CC_DEFAULT_REGION' ) && '' !== trim( (string) EA_CC_DEFAULT_REGION )
        ? sanitize_text_field( EA_CC_DEFAULT_REGION )
        : 'York Region';
}

function ea_default_city_value() {
    return defined( 'EA_CC_DEFAULT_CITY' ) && '' !== trim( (string) EA_CC_DEFAULT_CITY )
        ? sanitize_text_field( EA_CC_DEFAULT_CITY )
        : 'Newmarket';
}

function ea_free_trial_city_value( $post_id = 0 ) {
    if ( $post_id ) {
        $city = sanitize_text_field( get_post_meta( $post_id, '_ea_city', true ) );
        if ( '' !== $city ) {
            return $city;
        }
    }

    return ea_default_city_value();
}

function ea_newsletter_city_from_location( $location ) {
    $location = trim( (string) $location );

    if ( '' === $location || 0 === stripos( $location, 'General ' ) ) {
        return ea_default_city_value();
    }

    $normalized = strtolower( $location );
    if ( 'rh' === $normalized ) {
        return 'Richmond Hill';
    }
    if ( false !== strpos( $normalized, 'richmond' ) ) {
        return 'Richmond Hill';
    }
    if ( false !== strpos( $normalized, 'georgina' ) || false !== strpos( $normalized, 'keswick' ) ) {
        return 'Georgina';
    }
    if ( false !== strpos( $normalized, 'aurora' ) && false === strpos( $normalized, 'newmarket' ) ) {
        return 'Aurora';
    }

    $city = preg_replace( '/\s+newsletter$/i', '', $location );
    $city = preg_replace( '/^ea\s+(badminton|basketball|pickleball)\s+/i', '', $city );
    $city = preg_replace( '/,\s*(on|ontario|bc|british columbia|ab|alberta)\s*$/i', '', $city );
    $city = trim( preg_replace( '/\s+/', ' ', $city ) );

    return '' !== $city ? sanitize_text_field( $city ) : ea_default_city_value();
}

function ea_newsletter_city_value( $locations ) {
    if ( ! is_array( $locations ) ) {
        $locations = array();
    }

    $cities = array();
    foreach ( $locations as $location ) {
        $city = ea_newsletter_city_from_location( $location );
        if ( '' !== $city && ! in_array( $city, $cities, true ) ) {
            $cities[] = $city;
        }
    }

    return implode( '; ', $cities );
}

// Columns for the Free Trials list table:
// Athlete | Email | Phone | City | Sport | Age Range | Session | Submitted.
function ea_free_trial_columns( $columns ) {
    return array(
        'cb'         => isset( $columns['cb'] ) ? $columns['cb'] : '',
        'title'      => __( 'Athlete', 'ea-react-theme' ),
        'ea_email'   => __( 'Email', 'ea-react-theme' ),
        'ea_phone'   => __( 'Phone', 'ea-react-theme' ),
        'ea_city'    => __( 'City', 'ea-react-theme' ),
        'ea_sport'   => __( 'Sport', 'ea-react-theme' ),
        'ea_age_range' => __( 'Age Range', 'ea-react-theme' ),
        'ea_session' => __( 'Session', 'ea-react-theme' ),
        'date'       => __( 'Submitted', 'ea-react-theme' ),
    );
}
add_filter( 'manage_ea_free_trial_posts_columns', 'ea_free_trial_columns' );

function ea_free_trial_column_content( $column, $post_id ) {
    if ( 'ea_email' === $column ) {
        $email = get_post_meta( $post_id, '_ea_email', true );
        echo $email ? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' : '—';
    } elseif ( 'ea_phone' === $column ) {
        $phone = get_post_meta( $post_id, '_ea_phone', true );
        echo $phone ? esc_html( $phone ) : '—';
    } elseif ( 'ea_city' === $column ) {
        echo esc_html( ea_free_trial_city_value( $post_id ) );
    } elseif ( 'ea_sport' === $column ) {
        echo esc_html( ea_default_sport_value() );
    } elseif ( 'ea_age_range' === $column ) {
        $age_range = get_post_meta( $post_id, '_ea_age_range', true );
        echo $age_range ? esc_html( $age_range ) : '—';
    } elseif ( 'ea_session' === $column ) {
        $session = get_post_meta( $post_id, '_ea_session', true );
        echo $session ? esc_html( $session ) : '—';
    }
}
add_action( 'manage_ea_free_trial_posts_custom_column', 'ea_free_trial_column_content', 10, 2 );

// ─── CSV exports for admin-only form records ─────────────────────────────────
// Adds an "Export CSV" button to the Free Trials and Newsletter admin list
// screens. The export reads the saved entries directly from WordPress.
function ea_admin_export_button( $which ) {
    if ( 'top' !== $which ) {
        return;
    }

    global $typenow;

    $exports = array(
        'ea_free_trial' => array(
            'action' => 'ea_export_free_trials',
            'label'  => __( 'Export Free Trials CSV', 'ea-react-theme' ),
        ),
        'ea_newsletter' => array(
            'action' => 'ea_export_newsletter',
            'label'  => __( 'Export Newsletter CSV', 'ea-react-theme' ),
        ),
    );

    if ( empty( $exports[ $typenow ] ) ) {
        return;
    }

    $export = $exports[ $typenow ];
    $url    = wp_nonce_url(
        admin_url( 'admin-post.php?action=' . $export['action'] ),
        $export['action']
    );

    echo '<a class="button" href="' . esc_url( $url ) . '" style="margin-left:8px;">'
        . esc_html( $export['label'] )
        . '</a>';
}
add_action( 'manage_posts_extra_tablenav', 'ea_admin_export_button' );

function ea_send_csv_headers( $filename ) {
    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );
}

function ea_get_export_posts( $post_type ) {
    return get_posts( array(
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );
}

function ea_export_free_trials_csv() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'You do not have permission to export this data.', 'ea-react-theme' ) );
    }
    check_admin_referer( 'ea_export_free_trials' );

    ea_send_csv_headers( 'ea-free-trials-' . gmdate( 'Y-m-d' ) . '.csv' );

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'Submission ID', 'Athlete', 'Email', 'Phone', 'City', 'Sport', 'Age Range', 'Session', 'Submitted At' ) );

    foreach ( ea_get_export_posts( 'ea_free_trial' ) as $entry ) {
        fputcsv( $out, array(
            $entry->ID,
            get_the_title( $entry ),
            get_post_meta( $entry->ID, '_ea_email', true ),
            get_post_meta( $entry->ID, '_ea_phone', true ),
            ea_free_trial_city_value( $entry->ID ),
            ea_default_sport_value(),
            get_post_meta( $entry->ID, '_ea_age_range', true ),
            get_post_meta( $entry->ID, '_ea_session', true ),
            get_date_from_gmt( $entry->post_date_gmt, 'Y-m-d H:i:s' ),
        ) );
    }

    fclose( $out );
    exit;
}
add_action( 'admin_post_ea_export_free_trials', 'ea_export_free_trials_csv' );

function ea_export_newsletter_csv() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'You do not have permission to export this data.', 'ea-react-theme' ) );
    }
    check_admin_referer( 'ea_export_newsletter' );

    ea_send_csv_headers( 'ea-newsletter-' . gmdate( 'Y-m-d' ) . '.csv' );

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'Subscriber ID', 'Email', 'City', 'Sport', 'Subscribed At' ) );

    foreach ( ea_get_export_posts( 'ea_newsletter' ) as $entry ) {
        $locations = get_post_meta( $entry->ID, '_ea_locations', true );

        fputcsv( $out, array(
            $entry->ID,
            get_post_meta( $entry->ID, '_ea_email', true ),
            ea_newsletter_city_value( $locations ),
            ea_default_sport_value(),
            get_date_from_gmt( $entry->post_date_gmt, 'Y-m-d H:i:s' ),
        ) );
    }

    fclose( $out );
    exit;
}
add_action( 'admin_post_ea_export_newsletter', 'ea_export_newsletter_csv' );

// ─── Constant Contact newsletter sync ────────────────────────────────────────
// Secrets and account-specific IDs are intentionally read from wp-config.php
// constants so they are never committed to Git with the theme.
function ea_cc_config_value( $constant_name ) {
    return defined( $constant_name ) ? constant( $constant_name ) : '';
}

function ea_cc_config() {
    return array(
        'client_id'       => ea_cc_config_value( 'EA_CC_CLIENT_ID' ),
        'client_secret'   => ea_cc_config_value( 'EA_CC_CLIENT_SECRET' ),
        'list_id'         => ea_cc_config_value( 'EA_CC_NEWSLETTER_LIST_ID' ),
    );
}

function ea_cc_is_configured() {
    $config = ea_cc_config();
    foreach ( array( 'client_id', 'client_secret', 'list_id' ) as $key ) {
        $value = $config[ $key ] ?? '';
        if ( '' === $value ) {
            return false;
        }
    }
    return true;
}

function ea_cc_redirect_uri() {
    $configured = ea_cc_config_value( 'EA_CC_REDIRECT_URI' );
    if ( '' !== $configured ) {
        return $configured;
    }

    return admin_url( 'admin-post.php?action=ea_cc_oauth_callback' );
}

function ea_cc_token_option() {
    return 'ea_cc_tokens';
}

function ea_cc_log( $message, $context = array() ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'EA Constant Contact: ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ) );
    }
}

function ea_cc_auth_url() {
    if ( ! ea_cc_is_configured() ) {
        return '';
    }

    $config = ea_cc_config();
    $state  = wp_generate_password( 32, false, false );
    set_transient( 'ea_cc_oauth_state_' . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS );

    return add_query_arg(
        array(
            'client_id'     => $config['client_id'],
            'redirect_uri'  => ea_cc_redirect_uri(),
            'response_type' => 'code',
            'scope'         => 'contact_data offline_access',
            'state'         => $state,
        ),
        'https://authz.constantcontact.com/oauth2/default/v1/authorize'
    );
}

function ea_cc_admin_menu() {
    add_submenu_page(
        'edit.php?post_type=ea_newsletter',
        __( 'Constant Contact', 'ea-react-theme' ),
        __( 'Constant Contact', 'ea-react-theme' ),
        'manage_options',
        'ea-constant-contact',
        'ea_cc_admin_page'
    );
}
add_action( 'admin_menu', 'ea_cc_admin_menu' );

function ea_cc_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to manage this integration.', 'ea-react-theme' ) );
    }

    $tokens    = get_option( ea_cc_token_option(), array() );
    $connected = ! empty( $tokens['refresh_token'] );
    $auth_url  = ea_cc_auth_url();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Constant Contact', 'ea-react-theme' ); ?></h1>
        <p><?php esc_html_e( 'Newsletter signups sync to Constant Contact after they are saved in WordPress.', 'ea-react-theme' ); ?></p>

        <table class="widefat striped" style="max-width: 760px;">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Configuration', 'ea-react-theme' ); ?></th>
                    <td><?php echo ea_cc_is_configured() ? esc_html__( 'Configured', 'ea-react-theme' ) : esc_html__( 'Missing wp-config.php constants', 'ea-react-theme' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Connection', 'ea-react-theme' ); ?></th>
                    <td><?php echo $connected ? esc_html__( 'Connected', 'ea-react-theme' ) : esc_html__( 'Not connected', 'ea-react-theme' ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Redirect URI', 'ea-react-theme' ); ?></th>
                    <td><code><?php echo esc_html( ea_cc_redirect_uri() ); ?></code></td>
                </tr>
            </tbody>
        </table>

        <?php if ( $auth_url ) : ?>
            <p style="margin-top: 20px;">
                <a class="button button-primary" href="<?php echo esc_url( $auth_url ); ?>">
                    <?php echo $connected ? esc_html__( 'Reconnect Constant Contact', 'ea-react-theme' ) : esc_html__( 'Connect Constant Contact', 'ea-react-theme' ); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
    <?php
}

function ea_cc_oauth_callback() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to connect Constant Contact.', 'ea-react-theme' ) );
    }

    $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
    $code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

    if ( '' === $state || false === get_transient( 'ea_cc_oauth_state_' . $state ) ) {
        wp_die( esc_html__( 'Constant Contact authorization state expired or did not match.', 'ea-react-theme' ) );
    }
    delete_transient( 'ea_cc_oauth_state_' . $state );

    if ( '' === $code ) {
        wp_die( esc_html__( 'Constant Contact did not return an authorization code.', 'ea-react-theme' ) );
    }

    $result = ea_cc_exchange_code_for_tokens( $code );
    if ( is_wp_error( $result ) ) {
        wp_die( esc_html( $result->get_error_message() ) );
    }

    wp_safe_redirect( admin_url( 'edit.php?post_type=ea_newsletter&page=ea-constant-contact&connected=1' ) );
    exit;
}
add_action( 'admin_post_ea_cc_oauth_callback', 'ea_cc_oauth_callback' );

function ea_cc_exchange_code_for_tokens( $code ) {
    $config = ea_cc_config();

    $response = wp_remote_post(
        'https://authz.constantcontact.com/oauth2/default/v1/token',
        array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $config['client_id'] . ':' . $config['client_secret'] ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => array(
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => ea_cc_redirect_uri(),
            ),
            'timeout' => 20,
        )
    );

    return ea_cc_store_token_response( $response );
}

function ea_cc_store_token_response( $response ) {
    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
        ea_cc_log( 'Token request failed', array( 'status' => $code, 'body' => $body ) );
        return new WP_Error( 'ea_cc_token_failed', __( 'Constant Contact token request failed.', 'ea-react-theme' ) );
    }

    $tokens = get_option( ea_cc_token_option(), array() );
    $tokens['access_token'] = sanitize_text_field( $body['access_token'] );
    if ( ! empty( $body['refresh_token'] ) ) {
        $tokens['refresh_token'] = sanitize_text_field( $body['refresh_token'] );
    }
    $tokens['expires_at'] = time() + max( 60, (int) ( $body['expires_in'] ?? 7200 ) - 120 );
    update_option( ea_cc_token_option(), $tokens, false );

    return $tokens;
}

function ea_cc_access_token() {
    $tokens = get_option( ea_cc_token_option(), array() );
    if ( empty( $tokens['access_token'] ) || empty( $tokens['refresh_token'] ) ) {
        return new WP_Error( 'ea_cc_not_connected', __( 'Constant Contact is not connected.', 'ea-react-theme' ) );
    }

    if ( ! empty( $tokens['expires_at'] ) && time() < (int) $tokens['expires_at'] ) {
        return $tokens['access_token'];
    }

    $config   = ea_cc_config();
    $response = wp_remote_post(
        'https://authz.constantcontact.com/oauth2/default/v1/token',
        array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $config['client_id'] . ':' . $config['client_secret'] ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $tokens['refresh_token'],
            ),
            'timeout' => 20,
        )
    );

    $tokens = ea_cc_store_token_response( $response );
    if ( is_wp_error( $tokens ) ) {
        return $tokens;
    }

    return $tokens['access_token'];
}

// Simple month-range rule of thumb: Jan-Mar Spring, Apr-Jun Summer, Jul-Sep Fall, Oct-Dec Winter.
function ea_cc_season_from_date( $date_string ) {
    $timestamp = '' !== $date_string ? strtotime( $date_string ) : false;
    if ( false === $timestamp ) {
        $timestamp = current_time( 'timestamp' );
    }

    $month = (int) gmdate( 'n', $timestamp );
    $year  = gmdate( 'Y', $timestamp );

    if ( $month >= 1 && $month <= 3 ) {
        $season = 'Spring';
    } elseif ( $month >= 4 && $month <= 6 ) {
        $season = 'Summer';
    } elseif ( $month >= 7 && $month <= 9 ) {
        $season = 'Fall';
    } else {
        $season = 'Winter';
    }

    return array( $season, $year );
}

function ea_cc_find_tag_by_name( &$tags_cache, $token, $tag_name ) {
    if ( null === $tags_cache ) {
        $response = wp_remote_get(
            'https://api.cc.email/v3/contact_tags?limit=500',
            array(
                'headers' => array( 'Authorization' => 'Bearer ' . $token ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body       = json_decode( wp_remote_retrieve_body( $response ), true );
        $tags_cache = $body['tags'] ?? array();
    }

    foreach ( $tags_cache as $tag ) {
        if ( isset( $tag['name'] ) && 0 === strcasecmp( $tag['name'], $tag_name ) ) {
            return $tag['tag_id'];
        }
    }

    return false;
}

function ea_cc_find_or_create_tag( &$tags_cache, $token, $tag_name ) {
    $tag_name = sanitize_text_field( $tag_name );

    $existing = ea_cc_find_tag_by_name( $tags_cache, $token, $tag_name );
    if ( $existing ) {
        return $existing;
    }

    $response = wp_remote_post(
        'https://api.cc.email/v3/contact_tags',
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array( 'name' => $tag_name ) ),
            'timeout' => 15,
        )
    );

    if ( is_wp_error( $response ) ) {
        ea_cc_log( 'Tag create failed', array( 'error' => $response->get_error_message() ) );
        return false;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( 201 === $code && ! empty( $body['tag_id'] ) ) {
        $tags_cache[] = $body;
        return $body['tag_id'];
    }

    if ( 409 === $code ) {
        $tags_cache = null;
        return ea_cc_find_tag_by_name( $tags_cache, $token, $tag_name );
    }

    ea_cc_log( 'Unexpected tag create response', array( 'status' => $code, 'body' => $body ) );
    return false;
}

function ea_cc_contact_id_for_email( $token, $email, $attempts = 3 ) {
    for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
        $lookup = wp_remote_get(
            'https://api.cc.email/v3/contacts?email=' . rawurlencode( $email ),
            array(
                'headers' => array( 'Authorization' => 'Bearer ' . $token ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $lookup ) ) {
            ea_cc_log( 'Contact lookup failed', array( 'error' => $lookup->get_error_message(), 'attempt' => $attempt ) );
            return '';
        }

        $lookup_body = json_decode( wp_remote_retrieve_body( $lookup ), true );
        $contact     = $lookup_body['contacts'][0] ?? null;
        if ( ! empty( $contact['contact_id'] ) ) {
            return $contact['contact_id'];
        }

        if ( $attempt < $attempts ) {
            sleep( 1 );
        }
    }

    ea_cc_log( 'Contact lookup did not find contact after signup', array( 'email' => $email ) );
    return '';
}

function ea_cc_apply_tags( $token, $contact_id, $tag_ids, $email = '' ) {
    $tag_ids = array_values( array_filter( (array) $tag_ids ) );
    if ( empty( $tag_ids ) ) {
        return false;
    }

    if ( '' === $contact_id && '' !== $email ) {
        $contact_id = ea_cc_contact_id_for_email( $token, $email );
    }

    if ( '' === $contact_id ) {
        ea_cc_log( 'Tag apply skipped because no Constant Contact contact_id was available.', array( 'email' => $email ) );
        return false;
    }

    $response = wp_remote_post(
        'https://api.cc.email/v3/activities/contacts_taggings_add',
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode(
                array(
                    'source'  => array(
                        'contact_ids' => array( $contact_id ),
                    ),
                    'tag_ids' => $tag_ids,
                )
            ),
            'timeout' => 15,
        )
    );

    if ( is_wp_error( $response ) ) {
        ea_cc_log( 'Tag apply activity failed', array( 'error' => $response->get_error_message() ) );
        return false;
    }

    $status = wp_remote_retrieve_response_code( $response );
    if ( $status < 200 || $status >= 300 ) {
        ea_cc_log( 'Tag apply activity returned unexpected response', array( 'status' => $status, 'body' => wp_remote_retrieve_body( $response ) ) );
        return false;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    return $body['activity_id'] ?? true;
}

function ea_cc_sync_newsletter_contact( $email, $location, $entry_id = 0, $session_start = '', $program_summary = '', $province = '' ) {
    if ( ! ea_cc_is_configured() ) {
        ea_cc_log( 'Skipped sync because configuration is incomplete.' );
        return false;
    }

    $token = ea_cc_access_token();
    if ( is_wp_error( $token ) ) {
        ea_cc_log( 'Skipped sync because Constant Contact is not connected.', array( 'error' => $token->get_error_message() ) );
        return false;
    }

    $config = ea_cc_config();
    $city   = ea_newsletter_city_from_location( $location );
    list( $season, $year ) = ea_cc_season_from_date( $session_start );

    $tag_names = array_unique( array_filter( array(
        ea_default_sport_value(),
        $city,
        $province,
        'Youth',
        $season,
        $year,
    ) ) );
    $tag_ids    = array();
    $tags_cache = null;
    foreach ( $tag_names as $tag_name ) {
        $tag_id = ea_cc_find_or_create_tag( $tags_cache, $token, $tag_name );
        if ( $tag_id ) {
            $tag_ids[] = $tag_id;
        }
    }

    $payload = array(
        'email_address'    => $email,
        'list_memberships' => array( $config['list_id'] ),
    );

    $response = wp_remote_post(
        'https://api.cc.email/v3/contacts/sign_up_form',
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        )
    );

    if ( is_wp_error( $response ) ) {
        ea_cc_log( 'Newsletter sync request failed', array( 'error' => $response->get_error_message() ) );
        if ( $entry_id ) {
            update_post_meta( $entry_id, '_ea_cc_sync_error', $response->get_error_message() );
        }
        return false;
    }

    $status = wp_remote_retrieve_response_code( $response );
    $body   = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( $status < 200 || $status >= 300 ) {
        ea_cc_log( 'Newsletter sync failed', array( 'status' => $status, 'body' => $body ) );
        if ( $entry_id ) {
            update_post_meta( $entry_id, '_ea_cc_sync_error', wp_json_encode( $body ) );
        }
        return false;
    }

    $contact_id = ! empty( $body['contact_id'] ) ? sanitize_text_field( $body['contact_id'] ) : '';
    $tag_result = ea_cc_apply_tags( $token, $contact_id, $tag_ids, $email );

    if ( $entry_id ) {
        update_post_meta( $entry_id, '_ea_cc_synced_at', current_time( 'mysql' ) );
        update_post_meta( $entry_id, '_ea_cc_city', $city );
        if ( $tag_result ) {
            update_post_meta( $entry_id, '_ea_cc_tags_applied_at', current_time( 'mysql' ) );
            if ( is_string( $tag_result ) ) {
                update_post_meta( $entry_id, '_ea_cc_tagging_activity_id', $tag_result );
            }
            delete_post_meta( $entry_id, '_ea_cc_tag_sync_error' );
        } else {
            update_post_meta( $entry_id, '_ea_cc_tag_sync_error', 'Contact synced, but tag activity did not complete.' );
        }
        delete_post_meta( $entry_id, '_ea_cc_sync_error' );
    }

    return true;
}

// ─── Newsletter signups (custom REST endpoint) ────────────────────────────────
// The React newsletter form POSTs here. We validate the email, store it as an
// ea_newsletter entry, then notify the admin. Mirrors the Free Trial flow above.
function ea_register_newsletter_route() {
    register_rest_route( 'ea/v1', '/newsletter', array(
        'methods'             => 'POST',
        'permission_callback' => '__return_true', // public form; anyone can submit
        'callback'            => 'ea_handle_newsletter',
        'args'                => array(
            'email'    => array( 'required' => true, 'type' => 'string' ),
            // Which location card the signup came from (optional; blank = general signup).
            'location'       => array( 'required' => false, 'type' => 'string' ),
            // First session date of the source program; used for season/year tagging.
            'sessionStart'   => array( 'required' => false, 'type' => 'string' ),
            // Human-readable source program details for optional CC mapping.
            'programSummary' => array( 'required' => false, 'type' => 'string' ),
            // Province/state from the clicked city card; used for Constant Contact tags.
            'province'       => array( 'required' => false, 'type' => 'string' ),
            // Honeypot: real users leave this empty; bots tend to fill every field.
            'website'        => array( 'required' => false, 'type' => 'string' ),
        ),
    ) );
}
add_action( 'rest_api_init', 'ea_register_newsletter_route' );

function ea_handle_newsletter( WP_REST_Request $request ) {
    // Spam honeypot — silently accept so bots don't retry, but send nothing.
    if ( ! empty( $request['website'] ) ) {
        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    $email           = sanitize_email( wp_unslash( $request['email'] ) );
    $location        = isset( $request['location'] ) ? sanitize_text_field( wp_unslash( $request['location'] ) ) : '';
    $province        = isset( $request['province'] ) ? sanitize_text_field( wp_unslash( $request['province'] ) ) : '';
    $session_start   = isset( $request['sessionStart'] ) ? sanitize_text_field( wp_unslash( $request['sessionStart'] ) ) : '';
    $program_summary = isset( $request['programSummary'] ) ? sanitize_text_field( wp_unslash( $request['programSummary'] ) ) : '';

    if ( '' === $email || ! is_email( $email ) ) {
        return new WP_Error(
            'ea_invalid',
            'Please provide a valid email address.',
            array( 'status' => 422 )
        );
    }

    // One record per email. Reuse the existing entry if this address already signed
    // up so we can accumulate the locations they've subscribed to.
    $existing = get_posts( array(
        'post_type'   => 'ea_newsletter',
        'post_status' => 'publish',
        'title'       => $email,
        'numberposts' => 1,
        'fields'      => 'ids',
    ) );

    if ( ! empty( $existing ) ) {
        $entry_id = (int) $existing[0];
    } else {
        // Record of truth first (viewable in wp-admin → Newsletter).
        $entry_id = wp_insert_post( array(
            'post_type'   => 'ea_newsletter',
            'post_status' => 'publish',
            'post_title'  => $email,
        ), true );

        if ( is_wp_error( $entry_id ) || ! $entry_id ) {
            return new WP_Error(
                'ea_store_failed',
                'Sorry, something went wrong saving your subscription. Please try again.',
                array( 'status' => 500 )
            );
        }
        update_post_meta( $entry_id, '_ea_email', $email );
    }

    // Track which locations this email is signed up for (present = subscribed / true).
    if ( '' !== $location ) {
        $locations = get_post_meta( $entry_id, '_ea_locations', true );
        if ( ! is_array( $locations ) ) {
            $locations = array();
        }
        if ( ! in_array( $location, $locations, true ) ) {
            $locations[] = $location;
            update_post_meta( $entry_id, '_ea_locations', $locations );
        }
    }

    // Sync newsletter signups to Constant Contact after local storage succeeds.
    // Best-effort: a Constant Contact outage must not break the front-end form.
    ea_cc_sync_newsletter_contact( $email, $location, (int) $entry_id, $session_start, $program_summary, $province );

    // Notify the admin (best-effort — the entry is already saved). Locally this is
    // caught by Local's Mailpit (Site → Tools → Open Mailpit).
    $to      = 'mitchell@elevationathletics.ca';
    $subject = 'New newsletter signup';
    $body    = "A newsletter signup was submitted:\n\nEmail: {$email}\n"
             . 'Location: ' . ( '' !== $location ? $location : '(general)' ) . "\n";
    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $email,
    );
    wp_mail( $to, $subject, $body, $headers );

    return new WP_REST_Response( array( 'ok' => true, 'id' => (int) $entry_id ), 200 );
}

// ─── "Newsletter" admin screen (stores + lists signups) ───────────────────────
function ea_register_newsletter_cpt() {
    register_post_type( 'ea_newsletter', array(
        'labels' => array(
            'name'          => __( 'Newsletter', 'ea-react-theme' ),
            'singular_name' => __( 'Subscriber', 'ea-react-theme' ),
            'menu_name'     => __( 'Newsletter', 'ea-react-theme' ),
            'all_items'     => __( 'All Subscribers', 'ea-react-theme' ),
            'search_items'  => __( 'Search Subscribers', 'ea-react-theme' ),
        ),
        'public'              => false,   // not a front-end URL
        'show_ui'             => true,    // but visible in wp-admin
        'show_in_menu'        => true,
        'menu_icon'           => 'dashicons-email',
        'menu_position'       => 27,
        'supports'            => array( 'title' ),
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        // No manual creation — signups only arrive via the form endpoint.
        'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
        'exclude_from_search' => true,
    ) );
}
add_action( 'init', 'ea_register_newsletter_cpt' );

// Columns for the Newsletter list table: Email | City | Sport | Subscribed.
function ea_newsletter_columns( $columns ) {
    return array(
        'cb'       => isset( $columns['cb'] ) ? $columns['cb'] : '',
        'title'    => __( 'Email', 'ea-react-theme' ),
        'ea_city'  => __( 'City', 'ea-react-theme' ),
        'ea_sport' => __( 'Sport', 'ea-react-theme' ),
        'date'     => __( 'Subscribed', 'ea-react-theme' ),
    );
}
add_filter( 'manage_ea_newsletter_posts_columns', 'ea_newsletter_columns' );

function ea_newsletter_column_content( $column, $post_id ) {
    if ( 'ea_city' === $column ) {
        $locations = get_post_meta( $post_id, '_ea_locations', true );
        $city      = ea_newsletter_city_value( $locations );
        echo '' !== $city
            ? esc_html( $city )
            : '<span aria-hidden="true">—</span>';
    } elseif ( 'ea_sport' === $column ) {
        echo esc_html( ea_default_sport_value() );
    }
}
add_action( 'manage_ea_newsletter_posts_custom_column', 'ea_newsletter_column_content', 10, 2 );
