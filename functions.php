<?php
/**
 * Elevation Athletics React Theme — functions.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Newsletter → EA Operations app sync (settings: Settings → EA App Sync).
require_once get_template_directory() . '/inc/ea-app-sync.php';
require_once get_template_directory() . '/inc/ea-attribution.php';

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
    // React and ReactDOM load from unpkg.com, and the whole app - including the
    // programs fetch - is blocked until they arrive. Opening the connection
    // early overlaps the DNS + TLS handshake with the rest of the head instead
    // of paying for it serially once the parser reaches the script tags.
    echo '<link rel="preconnect" href="https://unpkg.com" crossorigin>' . "\n";

    $fonts = array(
        // Display face: every headline.
        'BBHBogle-Regular.woff2',
        // Body face: every paragraph, label and card on the page. It was NOT
        // preloaded before, so it was only discovered after fonts.css parsed -
        // the largest font on the page behind the longest discovery delay.
        'InclusiveSans.woff2',
    );
    foreach ( $fonts as $font ) {
        printf(
            '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
            esc_url( get_template_directory_uri() . '/ea_ds/assets/fonts/' . $font )
        );
    }
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
            // Admin-editable marketing copy (Appearance → Customize → EA Text).
            'texts'    => ea_react_texts(),
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

// Allow waiver pages to use the shorthand [form id="123"] for WPForms.
function ea_wpforms_form_shortcode_alias( $atts ) {
    if ( ! shortcode_exists( 'wpforms' ) ) {
        return '';
    }

    $atts = shortcode_atts(
        array(
            'id'          => '',
            'title'       => 'false',
            'description' => 'false',
        ),
        $atts,
        'form'
    );

    if ( empty( $atts['id'] ) ) {
        return '';
    }

    return do_shortcode(
        sprintf(
            '[wpforms id="%s" title="%s" description="%s"]',
            esc_attr( $atts['id'] ),
            esc_attr( $atts['title'] ),
            esc_attr( $atts['description'] )
        )
    );
}

function ea_register_wpforms_form_shortcode_alias() {
    if ( ! shortcode_exists( 'form' ) ) {
        add_shortcode( 'form', 'ea_wpforms_form_shortcode_alias' );
    }
}
add_action( 'init', 'ea_register_wpforms_form_shortcode_alias', 20 );

// ─── Pickleball waiver forms ─────────────────────────────────────────────────
// First-party waiver forms. Submissions are saved in wp-admin → Waivers.
function ea_waiver_legal_sections() {
    return array(
        1 => array(
            'title' => 'Release of Liability, Waiver of Claims and Indemnity Agreement - Paragraph 1',
            'body'  => 'This is a binding legal agreement. Clarify any questions or concerns before signing.',
        ),
        2 => array(
            'title' => 'Activities - Paragraph 2',
            'body'  => 'As a participant in the sport of pickleball and the activities, programs, classes, services provided and events sponsored or organized by Elevation Athletics Association including but not limited to games, tournaments, practices, training, personal training, dry land training, use of strength training and fitness conditioning equipment, machines and facilities, nutritional and dietary programs, orientational or instructional sessions or lessons, aerobic and anaerobic conditioning programs (collectively the “Activities”), the undersigned acknowledges and agrees to the terms outlined in this agreement.',
        ),
        3 => array(
            'title' => 'Disclaimer - Paragraph 3',
            'body'  => 'Elevation Athletics Association and their respective Directors, Officers, committee members, members, employees, coaches, volunteers, officials, participants, agents, sponsors, owners/operators of the facilities in which the Activities take place, and representatives (collectively the “Organization”) are not responsible for any injury, personal injury, damage, property damage, expense, loss of income or loss of any kind suffered by a Participant during, or as a result of, the Activities, caused in any manner whatsoever including, but not limited to, the negligence of the Organization.',
        ),
        4 => array(
            'title' => 'Description and Acknowledgement of Risks - Paragraph 4',
            'body'  => 'I understand and acknowledge that the Activities have foreseeable and unforeseeable inherent risks, hazards and dangers that no amount of care, caution or expertise can eliminate, including the potential for serious bodily injury, permanent disability, paralysis and loss of life. Risks include, but are not limited to, transmission of communicable disease including COVID-19, collision with persons or objects, being struck by a paddle or ball, stroke, heart attack or other life-threatening conditions caused by physical exertion, strains, sprains, fractures, brain injury, spinal cord injury, loss of balance or control, slips, trips and falls, negligent first aid, failure to act safely or within one’s own ability, negligence of other persons, and negligence on the part of the Organization. The Organization may be unaware of fitness or abilities, may misjudge conditions, may give incomplete warnings or instructions, and equipment may malfunction. COVID-19 has been declared a worldwide pandemic and is contagious; the Organization cannot guarantee that participation will not increase the risk of exposure or infection.',
        ),
        5 => array(
            'title' => 'Description and Acknowledgement of Risks - Paragraph 5',
            'body'  => 'I am participating voluntarily in the Activities. In consideration of participation, I acknowledge that I am aware of the risks, dangers and hazards associated with the Activities, including health risks, premises risks, equipment risks, contact with paddles, nets, court dividers, balls, equipment, vehicles or other persons, negligent advice, failure to act safely or within ability, inherent pickleball risks including running, sliding, slipping, serving, returning, stepping onto or over court surfaces or dividers, cyber/privacy risks, conduct of other persons, travel to and from the Activities, and negligence of myself, other persons, and the Organization.',
        ),
        6 => array(
            'title' => 'Terms - Paragraph 6',
            'body'  => 'In consideration of the Organization allowing participation in the Activities, I agree that when I practice or train in my own space I am responsible for my surroundings and equipment; that my mental and physical condition is appropriate and I assume related risks; to comply with rules and regulations for participation and facility/equipment use; to remove myself and notify a representative if I observe a significant hazard or risk; not to participate if impaired; to assess whether any Activities are too difficult; to be responsible for my choice and fit of safety or protective equipment; and that COVID-19 exposure may result in personal injury, illness, permanent disability, or death.',
        ),
        7 => array(
            'title' => 'Release of Liability and Disclaimer - Paragraph 7',
            'body'  => 'In consideration of the Organization allowing participation, I agree that the sole responsibility for safety remains with me; to assume all risks arising out of, associated with or related to participation; that I am not relying on oral or written statements made by the Organization or its agents; to waive any and all claims I may have now or in the future against the Organization; to freely accept and fully assume all risks and possibility of personal injury, death, property damage, expense and related loss; to forever release and indemnify the Organization from any and all liability for claims, demands, actions, damages, losses, judgments and costs that might arise out of or relate to participation, even if caused by negligence, gross negligence, negligent rescue, omissions, carelessness, breach of contract or statutory duty of care; to release and indemnify the Organization from any action related to exposure to or infection by COVID-19; that the Organization is not responsible for damage to vehicle, property or equipment; and that this release, waiver and indemnity is intended to be as broad and inclusive as permitted by law.',
        ),
        8 => array(
            'title' => 'Jurisdiction - Paragraph 8',
            'body'  => 'I agree that in the event that I file a lawsuit against the Organization, I will do so solely in the Province of Ontario and further agree that the substantive law of the Province of Ontario will apply without regard to conflict of law rules.',
        ),
        9 => array(
            'title' => 'Photo Release - Paragraph 9',
            'body'  => 'Do you give permission for Elevation Athletics Association to take photographs of the participant during program sessions for use in future promotional materials?',
        ),
        10 => array(
            'title' => 'Participant Safety Agreement - Paragraph 10',
            'body'  => 'In addition to the foregoing, the undersigned acknowledges and agrees to abide by Elevation Athletics Association’s 5 Golden Rules: participants shall not run backwards on the court; play must stop immediately if a ball rolls onto the court; the coach/coordinator shall inspect the court and surrounding area for hazards before activities begin; the court area must remain free of items including water bottles, personal belongings, benches, tables and similar objects; and participants must wear appropriate gym clothing and indoor athletic shoes while on the court. EA reserves the right to deny participation to anyone not adhering to this standard.',
        ),
        11 => array(
            'title' => 'Acknowledgement - Paragraph 11',
            'body'  => 'I acknowledge that I have read and understand this agreement, that I have executed this agreement voluntarily, and that this agreement is to be binding upon myself, my heirs, spouse, children, parents, guardians, next of kin, executors, administrators and legal or personal representatives. I further acknowledge that by submitting this agreement electronically, I have waived my right to maintain a lawsuit against the Organization on the basis of any claims from which I have released herein. By typing my name and submitting this form, I have agreed to all the Terms and Conditions of this Elevation Athletics Association Release of Liability, Waiver of Claims and Indemnity Agreement on the date and time recorded at the time of submission.',
        ),
    );
}

function ea_waiver_field( $name, $label, $type = 'text', $required = true, $placeholder = '' ) {
    $required_attr = $required ? ' required' : '';
    $placeholder_attr = '' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '';
    echo '<label class="ea-waiver-form__field">';
    echo '<span>' . esc_html( $label ) . ( $required ? ' <em>*</em>' : '' ) . '</span>';
    echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '"' . $required_attr . $placeholder_attr . '>';
    echo '</label>';
}

function ea_render_waiver_form( $type = 'adult' ) {
    $type     = 'minor' === $type ? 'minor' : 'adult';
    $is_minor = 'minor' === $type;
    $sections = ea_waiver_legal_sections();
    $success  = isset( $_GET['ea_waiver_submitted'] ) && '1' === $_GET['ea_waiver_submitted']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $title    = $is_minor
        ? 'EA Pickleball Informed Consent and Assumption of Risk Agreement'
        : 'EA Pickleball Release of Liability, Waiver of Claims and Indemnity Agreement';
    $intro    = $is_minor
        ? 'To be completed by the parent or guardian of participants under 18 years of age. Please read carefully before submitting.'
        : 'To be executed by participants over the Age of Majority. (18 years of age and older.) WARNING! Please read carefully. By submitting this form accepting the conditions, you will waive certain legal rights, including the right to sue.';
    ?>
    <section class="ea-built-waiver">
        <header class="ea-built-waiver__header">
            <p class="ea-built-waiver__eyebrow"><?php echo esc_html( $is_minor ? 'Minor waiver' : 'Adult waiver' ); ?></p>
            <h1><?php echo esc_html( $title ); ?></h1>
            <p><?php echo esc_html( $intro ); ?></p>
        </header>

        <?php if ( $success ) : ?>
            <div class="ea-built-waiver__success" role="status">
                <strong><?php esc_html_e( 'Thank you.', 'ea-react-theme' ); ?></strong>
                <?php esc_html_e( 'Your waiver has been submitted successfully.', 'ea-react-theme' ); ?>
            </div>
        <?php endif; ?>

        <form class="ea-waiver-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="ea_submit_waiver">
            <input type="hidden" name="waiver_type" value="<?php echo esc_attr( $type ); ?>">
            <input class="ea-waiver-form__website" type="text" name="website" tabindex="-1" autocomplete="off">
            <?php wp_nonce_field( 'ea_submit_waiver', 'ea_waiver_nonce' ); ?>

            <section class="ea-waiver-form__section">
                <h2><?php esc_html_e( 'Participant Information', 'ea-react-theme' ); ?></h2>
                <div class="ea-waiver-form__grid">
                    <?php
                    ea_waiver_field( 'athlete_first_name', "Athlete's First Name" );
                    ea_waiver_field( 'athlete_last_name', "Athlete's Last Name" );
                    if ( $is_minor ) {
                        ea_waiver_field( 'guardian_first_name', 'Parent / Guardian First Name' );
                        ea_waiver_field( 'guardian_last_name', 'Parent / Guardian Last Name' );
                    }
                    ea_waiver_field( 'email', $is_minor ? 'Parent / Guardian Email' : 'Email', 'email' );
                    ea_waiver_field( 'phone', 'Phone Number', 'tel' );
                    ?>
                </div>
            </section>

            <section class="ea-waiver-form__section">
                <h2><?php esc_html_e( 'Program Information', 'ea-react-theme' ); ?></h2>
                <p><?php esc_html_e( 'Please select the location where your pickleball league is taking place, not your home address.', 'ea-react-theme' ); ?></p>
                <div class="ea-waiver-form__grid">
                    <?php
                    ea_waiver_field( 'province', 'Province' );
                    ea_waiver_field( 'city', 'City' );
                    ea_waiver_field( 'program_type', 'Program Type', 'text', true, 'Ex. Learn to Play, Beginners League' );
                    ea_waiver_field( 'program_start_date', 'Program Start Date', 'date' );
                    ea_waiver_field( 'program_start_time', 'Program Start Time', 'text', true, 'Ex. 8:00 PM' );
                    ?>
                </div>
            </section>

            <section class="ea-waiver-form__section ea-waiver-form__legal">
                <h2><?php esc_html_e( 'Agreement', 'ea-react-theme' ); ?></h2>
                <?php foreach ( $sections as $number => $section ) : ?>
                    <div class="ea-waiver-form__clause">
                        <h3><?php echo esc_html( $section['title'] ); ?></h3>
                        <p><?php echo esc_html( $section['body'] ); ?></p>
                        <?php if ( 9 === (int) $number ) : ?>
                            <fieldset class="ea-waiver-form__radios">
                                <legend><?php esc_html_e( 'Photo permission', 'ea-react-theme' ); ?> <em>*</em></legend>
                                <label><input type="radio" name="photo_release" value="yes" required> <?php esc_html_e( 'Yes', 'ea-react-theme' ); ?></label>
                                <label><input type="radio" name="photo_release" value="no" required> <?php esc_html_e( 'No', 'ea-react-theme' ); ?></label>
                            </fieldset>
                        <?php else : ?>
                            <label class="ea-waiver-form__checkbox">
                                <input type="checkbox" name="agreement_<?php echo esc_attr( $number ); ?>" value="1" required>
                                <span><?php echo esc_html( sprintf( 'I have read and agree to be bound by paragraph %d.', $number ) ); ?></span>
                            </label>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="ea-waiver-form__section">
                <h2><?php esc_html_e( 'Electronic Signature', 'ea-react-theme' ); ?></h2>
                <p><?php echo esc_html( $is_minor ? 'By typing your name below, you confirm that you are the parent or guardian and agree on behalf of the participant.' : 'By typing your name below, you agree to all Terms and Conditions of this agreement.' ); ?></p>
                <?php ea_waiver_field( 'signature', $is_minor ? 'Parent / Guardian Signature' : 'Participant Signature' ); ?>
            </section>

            <button class="ea-waiver-form__submit" type="submit"><?php esc_html_e( 'Submit Waiver', 'ea-react-theme' ); ?></button>
        </form>
    </section>
    <?php
}

function ea_register_waiver_cpt() {
    register_post_type( 'ea_waiver', array(
        'labels' => array(
            'name'          => __( 'Waivers', 'ea-react-theme' ),
            'singular_name' => __( 'Waiver', 'ea-react-theme' ),
            'menu_name'     => __( 'Waivers', 'ea-react-theme' ),
            'all_items'     => __( 'All Waivers', 'ea-react-theme' ),
            'search_items'  => __( 'Search Waivers', 'ea-react-theme' ),
        ),
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'menu_icon'           => 'dashicons-media-text',
        'menu_position'       => 27,
        'supports'            => array( 'title' ),
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
        'exclude_from_search' => true,
    ) );
}
add_action( 'init', 'ea_register_waiver_cpt' );

function ea_handle_waiver_submission() {
    if ( ! empty( $_POST['website'] ) ) {
        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
        exit;
    }

    if (
        empty( $_POST['ea_waiver_nonce'] )
        || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ea_waiver_nonce'] ) ), 'ea_submit_waiver' )
    ) {
        wp_die( esc_html__( 'Sorry, this waiver could not be verified. Please go back and try again.', 'ea-react-theme' ) );
    }

    $type = isset( $_POST['waiver_type'] ) && 'minor' === sanitize_text_field( wp_unslash( $_POST['waiver_type'] ) ) ? 'minor' : 'adult';
    $fields = array(
        'athlete_first_name',
        'athlete_last_name',
        'guardian_first_name',
        'guardian_last_name',
        'email',
        'phone',
        'province',
        'city',
        'program_type',
        'program_start_date',
        'program_start_time',
        'photo_release',
        'signature',
    );

    $data = array();
    foreach ( $fields as $field ) {
        $raw = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
        $data[ $field ] = 'email' === $field ? sanitize_email( $raw ) : sanitize_text_field( $raw );
    }

    $required = array( 'athlete_first_name', 'athlete_last_name', 'email', 'phone', 'province', 'city', 'program_type', 'program_start_date', 'program_start_time', 'photo_release', 'signature' );
    if ( 'minor' === $type ) {
        $required[] = 'guardian_first_name';
        $required[] = 'guardian_last_name';
    }

    foreach ( $required as $field ) {
        if ( '' === $data[ $field ] ) {
            wp_die( esc_html__( 'Please complete all required waiver fields.', 'ea-react-theme' ) );
        }
    }

    if ( ! is_email( $data['email'] ) ) {
        wp_die( esc_html__( 'Please provide a valid email address.', 'ea-react-theme' ) );
    }

    for ( $i = 1; $i <= 11; $i++ ) {
        if ( 9 === $i ) {
            continue;
        }
        if ( empty( $_POST[ 'agreement_' . $i ] ) ) {
            wp_die( esc_html__( 'Please confirm each waiver paragraph before submitting.', 'ea-react-theme' ) );
        }
    }

    $athlete = trim( $data['athlete_first_name'] . ' ' . $data['athlete_last_name'] );
    $entry_id = wp_insert_post( array(
        'post_type'   => 'ea_waiver',
        'post_status' => 'publish',
        'post_title'  => ( 'minor' === $type ? 'Minor' : 'Adult' ) . ' waiver - ' . $athlete,
    ), true );

    if ( is_wp_error( $entry_id ) || ! $entry_id ) {
        wp_die( esc_html__( 'Sorry, something went wrong saving your waiver. Please try again.', 'ea-react-theme' ) );
    }

    update_post_meta( $entry_id, '_ea_waiver_type', $type );
    foreach ( $data as $field => $value ) {
        update_post_meta( $entry_id, '_ea_' . $field, $value );
    }
    update_post_meta( $entry_id, '_ea_signed_at', current_time( 'mysql' ) );

    $redirect = wp_get_referer() ? wp_get_referer() : home_url( '/' );
    wp_safe_redirect( add_query_arg( 'ea_waiver_submitted', '1', $redirect ) );
    exit;
}
add_action( 'admin_post_nopriv_ea_submit_waiver', 'ea_handle_waiver_submission' );
add_action( 'admin_post_ea_submit_waiver', 'ea_handle_waiver_submission' );

function ea_waiver_columns( $columns ) {
    return array(
        'cb'         => isset( $columns['cb'] ) ? $columns['cb'] : '',
        'title'      => __( 'Waiver', 'ea-react-theme' ),
        'ea_type'    => __( 'Type', 'ea-react-theme' ),
        'ea_email'   => __( 'Email', 'ea-react-theme' ),
        'ea_city'    => __( 'City', 'ea-react-theme' ),
        'ea_program' => __( 'Program', 'ea-react-theme' ),
        'date'       => __( 'Submitted', 'ea-react-theme' ),
    );
}
add_filter( 'manage_ea_waiver_posts_columns', 'ea_waiver_columns' );

function ea_waiver_column_content( $column, $post_id ) {
    if ( 'ea_type' === $column ) {
        echo esc_html( ucfirst( get_post_meta( $post_id, '_ea_waiver_type', true ) ) );
    } elseif ( 'ea_email' === $column ) {
        $email = get_post_meta( $post_id, '_ea_email', true );
        echo $email ? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' : '—';
    } elseif ( 'ea_city' === $column ) {
        echo esc_html( get_post_meta( $post_id, '_ea_city', true ) );
    } elseif ( 'ea_program' === $column ) {
        echo esc_html( get_post_meta( $post_id, '_ea_program_type', true ) );
    }
}
add_action( 'manage_ea_waiver_posts_custom_column', 'ea_waiver_column_content', 10, 2 );

function ea_waiver_meta_boxes() {
    add_meta_box(
        'ea_waiver_details',
        __( 'Waiver Details', 'ea-react-theme' ),
        'ea_render_waiver_meta_box',
        'ea_waiver',
        'normal',
        'high'
    );
}
add_action( 'add_meta_boxes_ea_waiver', 'ea_waiver_meta_boxes' );

function ea_render_waiver_meta_box( $post ) {
    $rows = array(
        'Type'                => ucfirst( get_post_meta( $post->ID, '_ea_waiver_type', true ) ),
        'Athlete First Name'  => get_post_meta( $post->ID, '_ea_athlete_first_name', true ),
        'Athlete Last Name'   => get_post_meta( $post->ID, '_ea_athlete_last_name', true ),
        'Guardian First Name' => get_post_meta( $post->ID, '_ea_guardian_first_name', true ),
        'Guardian Last Name'  => get_post_meta( $post->ID, '_ea_guardian_last_name', true ),
        'Email'               => get_post_meta( $post->ID, '_ea_email', true ),
        'Phone'               => get_post_meta( $post->ID, '_ea_phone', true ),
        'Province'            => get_post_meta( $post->ID, '_ea_province', true ),
        'City'                => get_post_meta( $post->ID, '_ea_city', true ),
        'Program Type'        => get_post_meta( $post->ID, '_ea_program_type', true ),
        'Program Start Date'  => get_post_meta( $post->ID, '_ea_program_start_date', true ),
        'Program Start Time'  => get_post_meta( $post->ID, '_ea_program_start_time', true ),
        'Photo Release'       => get_post_meta( $post->ID, '_ea_photo_release', true ),
        'Signature'           => get_post_meta( $post->ID, '_ea_signature', true ),
        'Signed At'           => get_post_meta( $post->ID, '_ea_signed_at', true ),
    );

    echo '<table class="widefat striped"><tbody>';
    foreach ( $rows as $label => $value ) {
        if ( '' === (string) $value ) {
            $value = '—';
        }
        echo '<tr><th style="width:220px;">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
    }
    echo '</tbody></table>';
}

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
        'ea_img_featured_article_1' => array( 'key' => 'featuredArticle1', 'label' => 'Featured Articles — Article 1 image' ),
        'ea_img_featured_article_2' => array( 'key' => 'featuredArticle2', 'label' => 'Featured Articles — Article 2 image' ),
        'ea_img_featured_article_3' => array( 'key' => 'featuredArticle3', 'label' => 'Featured Articles — Article 3 image' ),
        'ea_img_featured_article_4' => array( 'key' => 'featuredArticle4', 'label' => 'Featured Articles — Article 4 image' ),
        'ea_img_league_hub_backdrop' => array( 'key' => 'leagueHubBackdrop', 'label' => 'League Hub — subtle background' ),
        'ea_img_city_programs_backdrop' => array( 'key' => 'cityProgramsBackdrop', 'label' => 'City Programs — subtle background' ),
        'ea_img_faq_question_squid' => array( 'key' => 'faqQuestionSquid', 'label' => 'FAQ — question mascot image' ),
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
            'default' => 'Play pickleball in Ontario',
        ),
        'ea_txt_hero_desc' => array(
            'key' => 'heroDesc', 'label' => 'Hero — Subtext', 'type' => 'textarea',
            'default' => 'Join Canada’s most exciting and fastest-growing pickleball community! We welcome players of all skill levels onto the court.',
        ),
        'ea_txt_hero_btn_primary' => array(
            'key' => 'heroBtnPrimary', 'label' => 'Hero — Primary button', 'type' => 'text',
            'default' => 'Find a League Near You',
        ),
        'ea_txt_hero_btn_secondary' => array(
            'key' => 'heroBtnSecondary', 'label' => 'Hero — Secondary button', 'type' => 'text',
            'default' => 'New to Pickleball? Start Here',
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
        'ea_txt_league_hub_inactive_cities_heading' => array(
            'key' => 'leagueHubInactiveCitiesHeading', 'label' => 'League Hub — Inactive cities heading', 'type' => 'text',
            'default' => 'More Cities',
        ),
        'ea_txt_league_hub_inactive_cities_desc' => array(
            'key' => 'leagueHubInactiveCitiesDesc', 'label' => 'League Hub — Inactive cities description', 'type' => 'textarea',
            'default' => 'No active programs are listed in these cities right now, but you can still join the city newsletter.',
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
            'default' => 'From first-timers to future champions, Elevation Athletics pickleball is built around fun, inclusive play for every community.',
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
            'default' => 'Help bring inclusive, low-cost pickleball to your township. We\'ll set you up with courts, coaching, and leagues.',
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
            'default' => 'Help bring inclusive, low-cost pickleball to your township. We\'ll set you up with courts, coaching, and leagues.',
        ),
        'ea_txt_leaders_cta' => array(
            'key' => 'leadersCta', 'label' => 'Community — Leaders button', 'type' => 'text',
            'default' => 'Apply Today',
        ),

        // ── Featured Articles ────────────────────────────────────────────────
        'ea_txt_featured_articles_heading' => array(
            'key' => 'featuredArticlesHeading', 'label' => 'Featured Articles — Heading', 'type' => 'text',
            'default' => 'Featured Articles',
        ),
        'ea_txt_featured_article_1_title' => array(
            'key' => 'featuredArticle1Title', 'label' => 'Featured Articles — Article 1 title', 'type' => 'text',
            'default' => 'Recreational Pickleball back in Squamish & Whistler',
        ),
        'ea_txt_featured_article_1_subtext' => array(
            'key' => 'featuredArticle1Subtext', 'label' => 'Featured Articles — Article 1 subtext', 'type' => 'text',
            'default' => 'August 7th, 2025',
        ),
        'ea_txt_featured_article_2_title' => array(
            'key' => 'featuredArticle2Title', 'label' => 'Featured Articles — Article 2 title', 'type' => 'text',
            'default' => 'Elevation Athletics brings recreational pickleball to Weyburn',
        ),
        'ea_txt_featured_article_2_subtext' => array(
            'key' => 'featuredArticle2Subtext', 'label' => 'Featured Articles — Article 2 subtext', 'type' => 'text',
            'default' => 'October 20th, 2024',
        ),
        'ea_txt_featured_article_3_title' => array(
            'key' => 'featuredArticle3Title', 'label' => 'Featured Articles — Article 3 title', 'type' => 'text',
            'default' => 'New Cambridge pickleball league hopes to be a smashing success',
        ),
        'ea_txt_featured_article_3_subtext' => array(
            'key' => 'featuredArticle3Subtext', 'label' => 'Featured Articles — Article 3 subtext', 'type' => 'text',
            'default' => 'Cambridge Times, 2024',
        ),
        'ea_txt_featured_article_4_title' => array(
            'key' => 'featuredArticle4Title', 'label' => 'Featured Articles — Article 4 title', 'type' => 'text',
            'default' => 'Sports nonprofit brings a new pickleball league to Coquitlam',
        ),
        'ea_txt_featured_article_4_subtext' => array(
            'key' => 'featuredArticle4Subtext', 'label' => 'Featured Articles — Article 4 subtext', 'type' => 'text',
            'default' => 'Tri-Cities Dispatch, 2025',
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

function ea_react_options() {
    $active_program_sports = ea_sanitize_sports( get_theme_mod( 'ea_sports', ea_default_sports_selection() ) );

    return array(
        // true = photo carousel, false = the Free Trial registration form.
        'useCarousel' => (bool) get_theme_mod( 'ea_use_carousel', true ),
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
        'leagueHubFilterProvince' => (bool) get_theme_mod( 'ea_league_hub_filter_province', true ),
        'leagueHubShowInactiveToggle' => (bool) get_theme_mod( 'ea_league_hub_show_inactive_toggle', true ),
        'leagueHubShowInactiveCities' => (bool) get_theme_mod( 'ea_league_hub_show_inactive_cities', true ),
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

// ─── Social profile links via the Customizer (Appearance → Customize → EA Social) ─
// Plain URL fields — no WP menu required. Blank fields hide that icon in the footer.
function ea_react_social_fields() {
    return array(
        'ea_social_instagram' => array(
            'key' => 'instagram', 'label' => 'Instagram URL',
            'default' => 'https://www.instagram.com/elevationathleticspickleball/',
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
        'ea_link_featured_article_1' => array( 'key' => 'featuredArticle1', 'label' => 'Featured Articles — Article 1' ),
        'ea_link_featured_article_2' => array( 'key' => 'featuredArticle2', 'label' => 'Featured Articles — Article 2' ),
        'ea_link_featured_article_3' => array( 'key' => 'featuredArticle3', 'label' => 'Featured Articles — Article 3' ),
        'ea_link_featured_article_4' => array( 'key' => 'featuredArticle4', 'label' => 'Featured Articles — Article 4' ),
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
        'featured-articles' => 'Featured Articles',
        'newsletter'      => 'Newsletter',
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
        'ea_league_hub_filter_province' => array( 'default' => true, 'label' => __( 'League Hub — show province filter', 'ea-react-theme' ) ),
        'ea_league_hub_show_inactive_toggle' => array( 'default' => true, 'label' => __( 'League Hub — show inactive cities toggle', 'ea-react-theme' ) ),
        'ea_league_hub_show_inactive_cities' => array( 'default' => true, 'label' => __( 'League Hub — show inactive city pages section', 'ea-react-theme' ) ),
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

    $wp_customize->add_setting( 'ea_newsletter_notification_emails', array(
        'default'           => '',
        'sanitize_callback' => 'sanitize_textarea_field',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( 'ea_newsletter_notification_emails', array(
        'type'        => 'textarea',
        'label'       => __( 'Newsletter — notification emails', 'ea-react-theme' ),
        'description' => __( 'Who gets the "New newsletter signup" alert. Separate multiple addresses with commas. Leave blank to use info@elevationathletics.ca.', 'ea-react-theme' ),
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

function ea_customize_texts( $wp_customize ) {
    $wp_customize->add_section( 'ea_text', array(
        'title'       => __( 'EA Text', 'ea-react-theme' ),
        'description' => __( 'Edit the marketing copy shown across the home page. Clear a field to restore its default.', 'ea-react-theme' ),
        'priority'    => 31,
    ) );

    foreach ( ea_react_text_fields() as $setting => $meta ) {
        $sanitize = ( 'textarea' === $meta['type'] ) ? 'sanitize_textarea_field' : 'sanitize_text_field';
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
            'q'    => 'What pickleball programs does Elevation Athletics offer?',
            'a'    => 'We offer pickleball lessons, leagues, camps, and seasonal programs for players of different ages and levels. Available programs vary by city and season, so check the active programs section for the most up-to-date options.',
            'open' => true,
        ),
        array(
            'q'    => 'Do players need their own pickleball paddle?',
            'a'    => 'Players are encouraged to bring their own paddle if they have one. If they are new and do not have equipment yet, contact us before the program starts and we can let you know what is available.',
            'open' => true,
        ),
        array( 'q' => 'What should players bring to each session?', 'a' => 'Players should bring court shoes, athletic clothing, a water bottle, and a pickleball paddle if they have one.' ),
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
            'session' => array( 'required' => false, 'type' => 'string' ),
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
    $session = sanitize_text_field( wp_unslash( $request['session'] ) );

    if ( '' === $name || '' === $email || ! is_email( $email ) ) {
        return new WP_Error(
            'ea_invalid',
            'Please provide a valid name and email.',
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
    update_post_meta( $entry_id, '_ea_session', $session );

    // Send the booking to the EA Operations app (Free trials tab + contact).
    // Best-effort: failures are queued and retried; see inc/ea-app-sync.php.
    $trial_sport = isset( $request['sport'] ) ? sanitize_text_field( wp_unslash( $request['sport'] ) ) : '';
    if ( '' !== $trial_sport ) {
        update_post_meta( $entry_id, '_ea_sport', $trial_sport );
    }
    ea_app_trial_send( (int) $entry_id, array(
        'sport'    => $trial_sport,
        'page_url' => (string) wp_get_referer(),
    ) );

    // 2) Email the admin as a notification (best-effort — the entry is already
    //    saved, so a mail hiccup must not fail the submission). Locally this is
    //    caught by Local's Mailpit (Site → Tools → Open Mailpit).
    $to      = get_option( 'admin_email' );
    $subject = 'New free trial registration';
    $body    = "A new free trial registration was submitted:\n\n"
             . "Athlete's Name: {$name}\n"
             . "Email: {$email}\n"
             . "Session: " . ( '' !== $session ? $session : '(not specified)' ) . "\n";
    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        // Lets the admin hit "Reply" to respond to the registrant.
        'Reply-To: ' . $name . ' <' . $email . '>',
    );
    ea_mail_after_response( $to, $subject, $body, $headers );

    return new WP_REST_Response( array( 'ok' => true, 'id' => (int) $entry_id ), 200 );
}

// ─── Venue coordinate resolver (map pins for venues not in the baked table) ───
// The generated coordinate table is a build-time snapshot. New venue links added
// after a build can be resolved server-side here, cached, then reused by the map.
if ( ! defined( 'EA_VENUE_COORD_TTL' ) ) {
    define( 'EA_VENUE_COORD_TTL', YEAR_IN_SECONDS );
}
if ( ! defined( 'EA_VENUE_COORD_FAIL_TTL' ) ) {
    define( 'EA_VENUE_COORD_FAIL_TTL', 6 * HOUR_IN_SECONDS );
}
if ( ! defined( 'EA_VENUE_COORD_MAX_LINKS' ) ) {
    define( 'EA_VENUE_COORD_MAX_LINKS', 80 );
}
if ( ! defined( 'EA_VENUE_COORD_MAX_FRESH' ) ) {
    define( 'EA_VENUE_COORD_MAX_FRESH', 12 );
}
if ( ! defined( 'EA_VENUE_COORD_BUDGET' ) ) {
    define( 'EA_VENUE_COORD_BUDGET', 8.0 );
}

function ea_venue_coord_allowed_host( $url ) {
    $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    return in_array( $host, array(
        'maps.app.goo.gl', 'goo.gl',
        'maps.google.com', 'www.google.com', 'google.com',
        'maps.google.ca', 'www.google.ca', 'google.ca',
    ), true );
}

function ea_venue_coord_extract( $text ) {
    if ( preg_match( '/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/', $text, $m ) ) {
        return array( 'lat' => (float) $m[1], 'lng' => (float) $m[2] );
    }
    if ( preg_match( '/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', $text, $m ) ) {
        return array( 'lat' => (float) $m[1], 'lng' => (float) $m[2] );
    }
    if ( preg_match( '/[?&](?:q|ll|center)=(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', $text, $m ) ) {
        return array( 'lat' => (float) $m[1], 'lng' => (float) $m[2] );
    }
    return null;
}

function ea_venue_coord_in_bbox( $c ) {
    return $c
        && $c['lat'] >= 41 && $c['lat'] <= 84
        && $c['lng'] >= -142 && $c['lng'] <= -52;
}

function ea_venue_coord_resolve( $link ) {
    $url = $link;

    for ( $hop = 0; $hop < 5; $hop++ ) {
        if ( ! ea_venue_coord_allowed_host( $url ) ) {
            return null;
        }

        $res = wp_remote_get( $url, array(
            'timeout'     => 6,
            'redirection' => 0,
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
        $url = ( 0 === strpos( $next, 'http' ) )
            ? $next
            : ( 'https://' . wp_parse_url( $url, PHP_URL_HOST ) . '/' . ltrim( $next, '/' ) );
    }

    return null;
}

function ea_register_venue_coords_route() {
    register_rest_route( 'ea/v1', '/venue-coords', array(
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'ea_handle_venue_coords',
    ) );
}
add_action( 'rest_api_init', 'ea_register_venue_coords_route' );

function ea_handle_venue_coords( WP_REST_Request $request ) {
    $links = $request->get_param( 'links' );
    if ( ! is_array( $links ) ) {
        return new WP_Error( 'ea_invalid', 'links must be an array.', array( 'status' => 422 ) );
    }

    $links    = array_slice( array_unique( array_filter( array_map( 'strval', $links ) ) ), 0, EA_VENUE_COORD_MAX_LINKS );
    $out      = array();
    $pending  = array();
    $fresh    = 0;
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
            continue;
        }

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

// Site defaults. The constants keep their old EA_CC_* names so existing
// wp-config.php files keep working (Constant Contact itself is gone).
function ea_default_sport_value() {
    return defined( 'EA_CC_DEFAULT_SPORT' ) && '' !== trim( (string) EA_CC_DEFAULT_SPORT )
        ? sanitize_text_field( EA_CC_DEFAULT_SPORT )
        : 'Pickleball';
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

function ea_free_trial_city_value() {
    return ea_default_city_value();
}

function ea_newsletter_city_from_location( $location ) {
    $location = trim( sanitize_text_field( (string) $location ) );

    if ( '' === $location || 0 === stripos( $location, 'General ' ) ) {
        return ea_default_city_value();
    }

    $city = preg_replace( '/^EA\s+(Pickleball|Badminton|Basketball)\s+/i', '', $location );
    $city = preg_replace( '/\s+Newsletter$/i', '', $city );
    $city = trim( $city );
    if ( '' === $city ) {
        return ea_default_city_value();
    }

    $normalized = strtolower( $city );
    if ( false !== strpos( $normalized, 'richmond' ) ) {
        return 'Richmond Hill';
    }
    if ( false !== strpos( $normalized, 'georgina' ) || false !== strpos( $normalized, 'keswick' ) ) {
        return 'Georgina';
    }
    if ( false !== strpos( $normalized, 'aurora' ) && false === strpos( $normalized, 'newmarket' ) ) {
        return 'Aurora';
    }

    return $city;
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
// Athlete | Email | City | Sport | Session | Submitted.
function ea_free_trial_columns( $columns ) {
    return array(
        'cb'         => isset( $columns['cb'] ) ? $columns['cb'] : '',
        'title'      => __( 'Athlete', 'ea-react-theme' ),
        'ea_email'   => __( 'Email', 'ea-react-theme' ),
        'ea_city'    => __( 'City', 'ea-react-theme' ),
        'ea_sport'   => __( 'Sport', 'ea-react-theme' ),
        'ea_session' => __( 'Session', 'ea-react-theme' ),
        'date'       => __( 'Submitted', 'ea-react-theme' ),
    );
}
add_filter( 'manage_ea_free_trial_posts_columns', 'ea_free_trial_columns' );

function ea_free_trial_column_content( $column, $post_id ) {
    if ( 'ea_email' === $column ) {
        $email = get_post_meta( $post_id, '_ea_email', true );
        echo $email ? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' : '—';
    } elseif ( 'ea_city' === $column ) {
        echo esc_html( ea_free_trial_city_value() );
    } elseif ( 'ea_sport' === $column ) {
        echo esc_html( ea_default_sport_value() );
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
        'ea_waiver' => array(
            'action' => 'ea_export_waivers',
            'label'  => __( 'Export Waivers CSV', 'ea-react-theme' ),
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
    fputcsv( $out, array( 'Submission ID', 'Athlete', 'Email', 'City', 'Sport', 'Session', 'Submitted At' ) );

    foreach ( ea_get_export_posts( 'ea_free_trial' ) as $entry ) {
        fputcsv( $out, array(
            $entry->ID,
            get_the_title( $entry ),
            get_post_meta( $entry->ID, '_ea_email', true ),
            ea_free_trial_city_value(),
            ea_default_sport_value(),
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

function ea_export_waivers_csv() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'You do not have permission to export this data.', 'ea-react-theme' ) );
    }
    check_admin_referer( 'ea_export_waivers' );

    ea_send_csv_headers( 'ea-waivers-' . gmdate( 'Y-m-d' ) . '.csv' );

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array(
        'Waiver ID',
        'Type',
        'Athlete First Name',
        'Athlete Last Name',
        'Guardian First Name',
        'Guardian Last Name',
        'Email',
        'Phone',
        'Province',
        'City',
        'Program Type',
        'Program Start Date',
        'Program Start Time',
        'Photo Release',
        'Signature',
        'Signed At',
        'Submitted At',
    ) );

    foreach ( ea_get_export_posts( 'ea_waiver' ) as $entry ) {
        fputcsv( $out, array(
            $entry->ID,
            get_post_meta( $entry->ID, '_ea_waiver_type', true ),
            get_post_meta( $entry->ID, '_ea_athlete_first_name', true ),
            get_post_meta( $entry->ID, '_ea_athlete_last_name', true ),
            get_post_meta( $entry->ID, '_ea_guardian_first_name', true ),
            get_post_meta( $entry->ID, '_ea_guardian_last_name', true ),
            get_post_meta( $entry->ID, '_ea_email', true ),
            get_post_meta( $entry->ID, '_ea_phone', true ),
            get_post_meta( $entry->ID, '_ea_province', true ),
            get_post_meta( $entry->ID, '_ea_city', true ),
            get_post_meta( $entry->ID, '_ea_program_type', true ),
            get_post_meta( $entry->ID, '_ea_program_start_date', true ),
            get_post_meta( $entry->ID, '_ea_program_start_time', true ),
            get_post_meta( $entry->ID, '_ea_photo_release', true ),
            get_post_meta( $entry->ID, '_ea_signature', true ),
            get_post_meta( $entry->ID, '_ea_signed_at', true ),
            get_date_from_gmt( $entry->post_date_gmt, 'Y-m-d H:i:s' ),
        ) );
    }

    fclose( $out );
    exit;
}
add_action( 'admin_post_ea_export_waivers', 'ea_export_waivers_csv' );

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
            // Province for city-specific signups; used for province-level tagging.
            'province'       => array( 'required' => false, 'type' => 'string' ),
            // First session date of the source program; used for season/year tagging.
            'sessionStart'   => array( 'required' => false, 'type' => 'string' ),
            // Human-readable source program details for optional CC mapping.
            'programSummary' => array( 'required' => false, 'type' => 'string' ),
            // Honeypot: real users leave this empty; bots tend to fill every field.
            'website'        => array( 'required' => false, 'type' => 'string' ),
        ),
    ) );
}
add_action( 'rest_api_init', 'ea_register_newsletter_route' );

// Recipients for the "New newsletter signup" admin alert. Set in
// Appearance → Customize → EA Options → "Newsletter — notification emails"
// (comma/space separated). Blank → info@elevationathletics.ca. This only
// changes who is notified; storage and the EA app sync are untouched.
function ea_newsletter_notification_emails() {
    $raw    = (string) get_theme_mod( 'ea_newsletter_notification_emails', '' );
    $emails = preg_split( '/[\s,;]+/', $raw );
    $emails = array_filter( array_map( 'sanitize_email', (array) $emails ), 'is_email' );
    return $emails ? array_values( array_unique( $emails ) ) : 'info@elevationathletics.ca';
}

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
    $sport           = isset( $request['sport'] ) ? sanitize_text_field( wp_unslash( $request['sport'] ) ) : '';

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

    // Send the signup to the EA Operations app (marketing contacts) after local
    // storage succeeds. Best-effort: failures are queued and retried hourly, and
    // never break the front-end form. See inc/ea-app-sync.php.
    ea_app_signup_send( (int) $entry_id, $location, array(
        'province'        => $province,
        'session_start'   => $session_start,
        'program_summary' => $program_summary,
        'sport'           => $sport,
        'page_url'        => (string) wp_get_referer(),
    ) );

    // Notify the admin (best-effort — the entry is already saved). Locally this is
    // caught by Local's Mailpit (Site → Tools → Open Mailpit).
    $to      = ea_newsletter_notification_emails();
    $subject = 'New newsletter signup';
    $body    = "A newsletter signup was submitted:\n\nEmail: {$email}\n"
             . 'Location: ' . ( '' !== $location ? $location : '(general)' ) . "\n";
    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $email,
    );
    ea_mail_after_response( $to, $subject, $body, $headers );

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
