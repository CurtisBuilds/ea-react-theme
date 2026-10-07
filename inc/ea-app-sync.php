<?php
/**
 * EA app sync — sends website newsletter signups and free-trial bookings to the
 * EA Operations app.
 *
 * Replaces the old Constant Contact push (removed in this release; EA no longer
 * uses Constant Contact).
 * Every signup is still saved in WordPress first (wp-admin → Newsletter); this
 * file then posts it, server to server, to the Supabase Edge Function
 * `website-signup`, signed with this site's secret.
 *
 * Spec: "Website Signups → EA App: Endpoint Spec" (shared doc).
 *
 * Setup (once per site): Settings → EA App Sync → paste this site's secret
 * (Supabase → Vault → website_signup_secret_<site>). Or define
 * EA_APP_SIGNUP_SECRET in wp-config.php, which takes precedence.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EA_APP_SIGNUP_DEFAULT_URL' ) ) {
    define( 'EA_APP_SIGNUP_DEFAULT_URL', 'https://qlaoilkioesmwqqhdxlr.supabase.co/functions/v1/website-signup' );
}
// Retries for a signup that failed for a temporary reason (timeout, 5xx).
if ( ! defined( 'EA_APP_SIGNUP_MAX_ATTEMPTS' ) ) {
    define( 'EA_APP_SIGNUP_MAX_ATTEMPTS', 24 );
}
// How many signups one click of "Send unsynced signups" processes.
if ( ! defined( 'EA_APP_SIGNUP_BATCH' ) ) {
    define( 'EA_APP_SIGNUP_BATCH', 25 );
}

// ─── Config ──────────────────────────────────────────────────────────────────

function ea_app_signup_url() {
    if ( defined( 'EA_APP_SIGNUP_URL' ) && '' !== trim( (string) EA_APP_SIGNUP_URL ) ) {
        return esc_url_raw( EA_APP_SIGNUP_URL );
    }
    $saved = (string) get_option( 'ea_app_signup_url', '' );
    return '' !== $saved ? esc_url_raw( $saved ) : EA_APP_SIGNUP_DEFAULT_URL;
}

function ea_app_signup_secret() {
    if ( defined( 'EA_APP_SIGNUP_SECRET' ) && '' !== trim( (string) EA_APP_SIGNUP_SECRET ) ) {
        return trim( (string) EA_APP_SIGNUP_SECRET );
    }
    return trim( (string) get_option( 'ea_app_signup_secret', '' ) );
}

function ea_app_signup_secret_source() {
    if ( defined( 'EA_APP_SIGNUP_SECRET' ) && '' !== trim( (string) EA_APP_SIGNUP_SECRET ) ) {
        return 'wp-config.php';
    }
    return '' !== trim( (string) get_option( 'ea_app_signup_secret', '' ) ) ? 'settings' : '';
}

// The site name the endpoint knows: eapickleball.com, eabadminton.com, elevationathletics.ca.
function ea_app_signup_site() {
    $host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    return preg_replace( '/^www\./', '', $host );
}

// Sport codes the EA app understands (same codes as the programs feed).
function ea_app_signup_sport_code( $value ) {
    $v = strtolower( preg_replace( '/[^a-z_]/i', '', (string) $value ) );
    $aliases = array(
        'pb'     => array( 'pb', 'pickleball', 'pickle' ),
        'bad'    => array( 'bad', 'badm', 'badmin', 'badminton' ),
        'bask'   => array( 'bask', 'basketball', 'bball' ),
        'vball'  => array( 'vball', 'volleyball', 'volley' ),
        's_camp' => array( 's_camp', 'camp', 'camps', 'scamp', 'scamps', 'sportscamp', 'sportscamps' ),
    );
    foreach ( $aliases as $code => $names ) {
        if ( in_array( $v, $names, true ) ) {
            return $code;
        }
    }
    return '';
}

// Display name for a sport code or name ('' if unknown).
function ea_app_sport_label( $value ) {
    $labels = array( 'pb' => 'Pickleball', 'bad' => 'Badminton', 'bask' => 'Basketball', 'vball' => 'Volleyball', 's_camp' => 'Sports Camp' );
    return $labels[ ea_app_signup_sport_code( $value ) ] ?? '';
}

// The sport a signup belongs to. elevationathletics.ca hosts several sports, so the
// form's own sport wins; then a "General <Sport>" location; then the page it was
// made on (/volleyball/, /pickleball/ …); then the site's own sport.
function ea_app_signup_sport( $override = '', $location = '', $page_url = '' ) {
    $code = ea_app_signup_sport_code( $override );
    if ( '' !== $code ) {
        return $code;
    }
    if ( preg_match( '/^General\s+(.+)$/i', trim( (string) $location ), $m ) ) {
        $code = ea_app_signup_sport_code( $m[1] );
        if ( '' !== $code ) {
            return $code;
        }
    }
    $path = strtolower( (string) wp_parse_url( (string) $page_url, PHP_URL_PATH ) );
    foreach ( array( 'volleyball' => 'vball', 'pickleball' => 'pb', 'badminton' => 'bad', 'basketball' => 'bask' ) as $word => $c ) {
        if ( '' !== $path && false !== strpos( $path, $word ) ) {
            return $c;
        }
    }
    return ea_app_signup_site_sport();
}

function ea_app_signup_site_sport() {
    $by_site = array(
        'eapickleball.com'      => 'pb',
        'eabadminton.com'       => 'bad',
        'elevationathletics.ca' => 'bask',
    );
    $site = ea_app_signup_site();
    if ( isset( $by_site[ $site ] ) ) {
        return $by_site[ $site ];
    }
    $code = ea_app_signup_sport_code( function_exists( 'ea_default_sport_value' ) ? ea_default_sport_value() : '' );
    return '' !== $code ? $code : 'pb';
}

// The words the visitor saw beside the Subscribe button — kept as consent evidence.
function ea_app_signup_consent_text( $location ) {
    $texts     = function_exists( 'ea_react_texts' ) ? (array) ea_react_texts() : array();
    $heading   = ! empty( $texts['newsletterHeading'] ) ? $texts['newsletterHeading'] : 'Join Our Newsletter!';
    $desc      = ! empty( $texts['newsletterDesc'] ) ? $texts['newsletterDesc'] : 'Stay updated on upcoming programs in your area.';
    $button    = ! empty( $texts['newsletterSubscribe'] ) ? $texts['newsletterSubscribe'] : 'Subscribe';
    $line      = '' !== $location ? 'Subscribing to ' . $location . '.' : $desc;
    return wp_strip_all_tags( $heading . ' ' . $line . ' [' . $button . ']' );
}

function ea_app_signup_iso( $timestamp ) {
    return gmdate( 'Y-m-d\TH:i:s\Z', (int) $timestamp );
}

// ─── Per-entry sync state ────────────────────────────────────────────────────
// _ea_app_sync = array( '<location or empty>' => array(
//     status: synced | retry | auth | not_configured | waiting | failed,
//     result, error, attempts, at, submitted_at ) )

function ea_app_signup_state( $entry_id ) {
    $state = get_post_meta( $entry_id, '_ea_app_sync', true );
    return is_array( $state ) ? $state : array();
}

function ea_app_signup_save_state( $entry_id, $location, $row ) {
    $state              = ea_app_signup_state( $entry_id );
    $state[ $location ] = $row;
    update_post_meta( $entry_id, '_ea_app_sync', $state );
}

function ea_app_signup_is_done( $row ) {
    return is_array( $row ) && in_array( $row['status'] ?? '', array( 'synced', 'failed' ), true );
}

// Every location an entry is subscribed to; a signup with no location = ''.
function ea_app_signup_entry_locations( $entry_id ) {
    $locations = get_post_meta( $entry_id, '_ea_locations', true );
    $locations = is_array( $locations ) ? array_values( array_filter( array_map( 'strval', $locations ), 'strlen' ) ) : array();
    return $locations ? $locations : array( '' );
}

// ─── Transport: one signed POST to the EA app ────────────────────────────────

/**
 * Sign and send one JSON body. Returns the status fields for a state row:
 * synced | waiting | failed | auth | retry, plus result / error.
 */
function ea_app_post( $body, $secret ) {
    $json     = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    $response = wp_remote_post( ea_app_signup_url(), array(
        'timeout'     => 4,
        'redirection' => 0,
        'headers'     => array(
            'Content-Type'   => 'application/json',
            'X-EA-Site'      => $body['site'],
            // Signed over the exact bytes sent.
            'X-EA-Signature' => hash_hmac( 'sha256', $json, $secret ),
        ),
        'body'        => $json,
        'data_format' => 'body',
    ) );

    $out = array( 'status' => 'retry', 'result' => '', 'error' => '' );
    if ( is_wp_error( $response ) ) {
        $out['error'] = $response->get_error_message();
        return $out;
    }
    $code    = (int) wp_remote_retrieve_response_code( $response );
    $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
    $decoded = is_array( $decoded ) ? $decoded : array();

    if ( 200 === $code && ! empty( $decoded['ok'] ) ) {
        $out['status'] = 'synced';
        $out['result'] = (string) ( $decoded['result'] ?? '' );
    } elseif ( 400 === $code && 'unsupported_type' === ( $decoded['error'] ?? '' ) ) {
        // The app doesn't take this kind of signup yet: keep it for the
        // "Send unsynced" button instead of giving up on it.
        $out['status'] = 'waiting';
        $out['error']  = 'unsupported_type';
    } elseif ( 400 === $code ) {
        // Bad data (e.g. invalid_email): retrying will not help.
        $out['status'] = 'failed';
        $out['error']  = (string) ( $decoded['error'] ?? 'bad_request' );
    } elseif ( 401 === $code ) {
        // Wrong/missing secret on one side. Retried once the secret is fixed.
        $out['status'] = 'auth';
        $out['error']  = (string) ( $decoded['error'] ?? 'unauthorized' );
    } else {
        $out['error'] = 'HTTP ' . $code;
    }
    return $out;
}

/**
 * Shared send flow: build the row, send (unless no secret), store, schedule retry.
 * $build( $row ) returns the JSON body for this signup.
 */
function ea_app_send_row( $entry_id, $key, $previous, $row, $build ) {
    $row['attempts'] = (int) ( $previous['attempts'] ?? 0 ) + 1;
    $row['at']       = time();

    $secret = ea_app_signup_secret();
    if ( '' === $secret ) {
        $row['status']   = 'not_configured';
        $row['result']   = '';
        $row['error']    = 'No site secret set (Settings → EA App Sync).';
        $row['attempts'] = (int) ( $previous['attempts'] ?? 0 );
    } else {
        $row = array_merge( $row, ea_app_post( $build( $row ), $secret ) );
    }

    ea_app_signup_save_state( $entry_id, $key, $row );

    if ( 'retry' === $row['status'] && ! wp_next_scheduled( 'ea_app_signup_retry' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'ea_app_signup_retry' );
    }
    return $row;
}

// ─── Newsletter: one send per (entry, city) ──────────────────────────────────

/**
 * Post one (entry, location) newsletter signup to the EA app. Never throws; the
 * WordPress entry is already saved, so a failure only marks it for retry.
 *
 * @param int    $entry_id ea_newsletter post id.
 * @param string $location City the visitor subscribed to ('' = general signup).
 * @param array  $extra    province, session_start, program_summary, sport, page_url, submitted_at (ISO).
 */
function ea_app_signup_send( $entry_id, $location, $extra = array() ) {
    $entry_id = (int) $entry_id;
    $location = (string) $location;
    $previous = ea_app_signup_state( $entry_id )[ $location ] ?? array();
    if ( ea_app_signup_is_done( $previous ) ) {
        return $previous;
    }

    $email = (string) get_post_meta( $entry_id, '_ea_email', true );
    if ( '' === $email ) {
        $email = (string) get_the_title( $entry_id );
    }
    $page_url = (string) ( $extra['page_url'] ?? ( $previous['page_url'] ?? '' ) );
    $row      = array(
        'sport'        => ea_app_signup_sport( $extra['sport'] ?? ( $previous['sport'] ?? '' ), $location, $page_url ),
        'page_url'     => $page_url,
        'submitted_at' => ! empty( $extra['submitted_at'] ) ? (string) $extra['submitted_at']
                        : ( ! empty( $previous['submitted_at'] ) ? (string) $previous['submitted_at'] : ea_app_signup_iso( time() ) ),
    );

    return ea_app_send_row( $entry_id, $location, $previous, $row, function ( $row ) use ( $entry_id, $email, $location, $extra ) {
        return array(
            'type'            => 'newsletter',
            'site'            => ea_app_signup_site(),
            'wp_entry_id'     => $entry_id,
            'sent_at'         => ea_app_signup_iso( time() ),
            'submitted_at'    => $row['submitted_at'],
            'email'           => $email,
            'location'        => $location,
            'province'        => (string) ( $extra['province'] ?? '' ),
            'sport'           => $row['sport'],
            'session_start'   => (string) ( $extra['session_start'] ?? '' ),
            'program_summary' => (string) ( $extra['program_summary'] ?? '' ),
            'page_url'        => $row['page_url'],
            'consent_text'    => ea_app_signup_consent_text( $location ),
        );
    } );
}

// ─── Free trials: one send per booking ───────────────────────────────────────

/**
 * Post one free-trial booking (ea_free_trial post) to the EA app. Everything
 * the visitor typed is read back from the saved entry, so retries and the
 * backfill send exactly what was stored.
 *
 * @param array $extra sport, page_url, province, submitted_at (ISO).
 */
function ea_app_trial_send( $entry_id, $extra = array() ) {
    $entry_id = (int) $entry_id;
    $previous = ea_app_signup_state( $entry_id )[''] ?? array();
    if ( ea_app_signup_is_done( $previous ) ) {
        return $previous;
    }

    $meta     = function ( $key ) use ( $entry_id ) {
        return (string) get_post_meta( $entry_id, $key, true );
    };
    $city     = $meta( '_ea_city' );
    $page_url = (string) ( $extra['page_url'] ?? ( $previous['page_url'] ?? '' ) );
    $row      = array(
        'sport'        => ea_app_signup_sport( $extra['sport'] ?? ( $previous['sport'] ?? $meta( '_ea_sport' ) ), '', $page_url ),
        'page_url'     => $page_url,
        'province'     => (string) ( $extra['province'] ?? ( $previous['province'] ?? '' ) ),
        'submitted_at' => ! empty( $extra['submitted_at'] ) ? (string) $extra['submitted_at']
                        : ( ! empty( $previous['submitted_at'] ) ? (string) $previous['submitted_at'] : ea_app_signup_iso( time() ) ),
    );

    return ea_app_send_row( $entry_id, '', $previous, $row, function ( $row ) use ( $entry_id, $meta, $city ) {
        return array(
            'type'         => 'free_trial',
            'site'         => ea_app_signup_site(),
            'wp_entry_id'  => $entry_id,
            'sent_at'      => ea_app_signup_iso( time() ),
            'submitted_at' => $row['submitted_at'],
            'email'        => $meta( '_ea_email' ),
            // Name typed on the form: the athlete (often a child), not necessarily
            // the person who owns the email address.
            'attendee_name' => (string) get_the_title( $entry_id ),
            'phone'        => $meta( '_ea_phone' ),
            'location'     => $city,
            'province'     => $row['province'],
            'sport'        => $row['sport'],
            'age_range'    => $meta( '_ea_age_range' ),
            'skill_level'  => $meta( '_ea_skill_level' ),
            'session'      => $meta( '_ea_session' ),
            'form'         => $meta( '_ea_source' ),
            'page_url'     => $row['page_url'],
        );
    } );
}

// ─── Queue: unsynced items, oldest first ─────────────────────────────────────
// Items are array( type, entry_id, key ); key = city for newsletter, '' for trials.

function ea_app_signup_types() {
    return array(
        'newsletter' => 'ea_newsletter',
        'free_trial' => 'ea_free_trial',
    );
}

function ea_app_item_keys( $type, $entry_id ) {
    return 'newsletter' === $type ? ea_app_signup_entry_locations( $entry_id ) : array( '' );
}

function ea_app_signup_pending( $limit, $statuses = array( 'retry', 'auth', 'not_configured', 'waiting', 'new' ), $types = null ) {
    $queue = array();
    foreach ( ea_app_signup_types() as $type => $post_type ) {
        if ( $types && ! in_array( $type, (array) $types, true ) ) {
            continue;
        }
        $ids = get_posts( array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ) );
        foreach ( $ids as $entry_id ) {
            $state = ea_app_signup_state( $entry_id );
            foreach ( ea_app_item_keys( $type, $entry_id ) as $key ) {
                $row    = $state[ $key ] ?? null;
                $status = $row ? ( $row['status'] ?? 'new' ) : 'new';
                if ( ! in_array( $status, $statuses, true ) ) {
                    continue;
                }
                if ( 'retry' === $status && (int) ( $row['attempts'] ?? 0 ) >= EA_APP_SIGNUP_MAX_ATTEMPTS ) {
                    continue;
                }
                $queue[] = array( $type, (int) $entry_id, $key );
                if ( count( $queue ) >= $limit ) {
                    return $queue;
                }
            }
        }
    }
    return $queue;
}

// Send one queued item. Backfilled items keep the date they were made.
function ea_app_send_item( $item, $backfill = false ) {
    list( $type, $entry_id, $key ) = $item;
    $extra = $backfill ? array( 'submitted_at' => ea_app_signup_iso( (int) get_post_time( 'U', true, $entry_id ) ) ) : array();
    return 'free_trial' === $type ? ea_app_trial_send( $entry_id, $extra ) : ea_app_signup_send( $entry_id, $key, $extra );
}

function ea_app_signup_counts( $type = 'newsletter' ) {
    $types  = ea_app_signup_types();
    $counts = array( 'synced' => 0, 'pending' => 0, 'waiting' => 0, 'failed' => 0 );
    $ids    = get_posts( array(
        'post_type'      => $types[ $type ],
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );
    foreach ( $ids as $entry_id ) {
        $state = ea_app_signup_state( $entry_id );
        foreach ( ea_app_item_keys( $type, $entry_id ) as $key ) {
            $status = $state[ $key ]['status'] ?? 'new';
            if ( 'synced' === $status ) {
                $counts['synced']++;
            } elseif ( 'failed' === $status ) {
                $counts['failed']++;
            } elseif ( 'waiting' === $status ) {
                $counts['waiting']++;
            } else {
                $counts['pending']++;
            }
        }
    }
    $counts['entries'] = count( $ids );
    return $counts;
}

// Hourly retry of signups that failed for a temporary reason.
add_action( 'ea_app_signup_retry', function () {
    foreach ( ea_app_signup_pending( 50, array( 'retry' ) ) as $item ) {
        ea_app_send_item( $item );
    }
    if ( ! ea_app_signup_pending( 1, array( 'retry' ) ) ) {
        wp_clear_scheduled_hook( 'ea_app_signup_retry' );
    }
} );

add_action( 'switch_theme', function () {
    wp_clear_scheduled_hook( 'ea_app_signup_retry' );
} );

// ─── Form emails after the response ──────────────────────────────────────────
// Sending through the site's mail server can take several seconds (≈7 s per
// email measured on eabadminton.com's SMTP login). The visitor shouldn't wait
// for that: queue the email and send it after / outside the visitor's request.

function ea_mail_after_response( $to, $subject, $message, $headers = '', $attachments = array() ) {
    global $ea_deferred_mail;
    if ( ! is_array( $ea_deferred_mail ) ) {
        $ea_deferred_mail = array();
        add_action( 'shutdown', 'ea_send_deferred_mail', 0 );
    }
    $ea_deferred_mail[] = array( $to, $subject, $message, $headers, $attachments );
    return true;
}

function ea_send_deferred_mail() {
    global $ea_deferred_mail;
    if ( empty( $ea_deferred_mail ) ) {
        return;
    }
    $queue            = $ea_deferred_mail;
    $ea_deferred_mail = array();

    // PHP-FPM / LiteSpeed: finish the response, then send in this request.
    if ( function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ) ) {
        ignore_user_abort( true );
        function_exists( 'fastcgi_finish_request' ) ? fastcgi_finish_request() : litespeed_finish_request();
        ea_mail_send_all( $queue );
        return;
    }

    // SiteGround runs PHP as cgi-fcgi, which can't end the response early.
    // Hand the emails to a background request instead (like WP-Cron's spawn),
    // with a scheduled run 2 minutes later as a safety net.
    $id = strtolower( wp_generate_password( 20, false ) );
    set_transient( 'ea_mailq_' . $id, $queue, DAY_IN_SECONDS );
    wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'ea_mail_queue_run', array( $id ) );
    wp_remote_post( admin_url( 'admin-post.php' ), array(
        'blocking'  => false,
        'timeout'   => 1,
        'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
        'body'      => array(
            'action' => 'ea_mail_queue',
            'id'     => $id,
            'sig'    => hash_hmac( 'sha256', $id, wp_salt( 'nonce' ) ),
        ),
    ) );
}

function ea_mail_send_all( $queue ) {
    foreach ( (array) $queue as $mail ) {
        wp_mail( $mail[0], $mail[1], $mail[2], $mail[3], $mail[4] );
    }
}

// Send one stored batch. Taken out of storage first, so the background request
// and the safety-net run never both send it.
function ea_mail_queue_run( $id ) {
    $id    = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $id ) );
    $queue = '' !== $id ? get_transient( 'ea_mailq_' . $id ) : false;
    if ( ! is_array( $queue ) ) {
        return;
    }
    delete_transient( 'ea_mailq_' . $id );
    wp_clear_scheduled_hook( 'ea_mail_queue_run', array( $id ) );
    ea_mail_send_all( $queue );
}
add_action( 'ea_mail_queue_run', 'ea_mail_queue_run' );

function ea_mail_queue_endpoint() {
    ignore_user_abort( true );
    $id  = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
    $sig = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
    if ( '' !== $id && hash_equals( hash_hmac( 'sha256', $id, wp_salt( 'nonce' ) ), $sig ) ) {
        ea_mail_queue_run( $id );
    }
    exit;
}
add_action( 'admin_post_nopriv_ea_mail_queue', 'ea_mail_queue_endpoint' );
add_action( 'admin_post_ea_mail_queue', 'ea_mail_queue_endpoint' );

// One-time cleanup: the Constant Contact integration was removed. Drop its stored
// OAuth tokens so no live credentials linger in the database.
add_action( 'admin_init', function () {
    if ( false !== get_option( 'ea_cc_tokens', false ) ) {
        delete_option( 'ea_cc_tokens' );
    }
} );

// ─── Admin: Settings → EA App Sync ───────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_options_page( 'EA App Sync', 'EA App Sync', 'manage_options', 'ea-app-sync', 'ea_app_signup_settings_page' );
} );

add_action( 'admin_post_ea_app_sync_save', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Not allowed.' );
    }
    check_admin_referer( 'ea_app_sync_save' );

    $secret = isset( $_POST['ea_app_signup_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ea_app_signup_secret'] ) ) ) : '';
    if ( '' !== $secret ) {
        // Not autoloaded: only read when a signup is sent.
        update_option( 'ea_app_signup_secret', $secret, false );
    }
    if ( ! empty( $_POST['ea_app_signup_clear'] ) ) {
        delete_option( 'ea_app_signup_secret' );
    }
    $url = isset( $_POST['ea_app_signup_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['ea_app_signup_url'] ) ) ) : '';
    if ( '' === $url || EA_APP_SIGNUP_DEFAULT_URL === $url ) {
        delete_option( 'ea_app_signup_url' );
    } else {
        update_option( 'ea_app_signup_url', $url, false );
    }

    wp_safe_redirect( add_query_arg( array( 'page' => 'ea-app-sync', 'saved' => 1 ), admin_url( 'options-general.php' ) ) );
    exit;
} );

add_action( 'admin_post_ea_app_sync_send', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Not allowed.' );
    }
    check_admin_referer( 'ea_app_sync_send' );

    $tally = array();
    foreach ( ea_app_signup_pending( EA_APP_SIGNUP_BATCH ) as $item ) {
        $row   = ea_app_send_item( $item, true );
        $label = ( 'free_trial' === $item[0] ? 'free trial ' : '' )
               . ( 'synced' === $row['status'] ? ( $row['result'] ? $row['result'] : 'synced' ) : $row['status'] );
        $tally[ $label ] = ( $tally[ $label ] ?? 0 ) + 1;
    }

    set_transient( 'ea_app_sync_last_run_' . get_current_user_id(), $tally, 10 * MINUTE_IN_SECONDS );
    wp_safe_redirect( add_query_arg( array( 'page' => 'ea-app-sync', 'sent' => 1 ), admin_url( 'options-general.php' ) ) );
    exit;
} );

function ea_app_signup_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $counts = ea_app_signup_counts();
    $source = ea_app_signup_secret_source();
    $tally  = get_transient( 'ea_app_sync_last_run_' . get_current_user_id() );
    ?>
    <div class="wrap">
        <h1>EA App Sync</h1>
        <p>Newsletter signups and free-trial bookings on this site are saved in WordPress, then sent to the EA Operations app.
           Site name sent: <code><?php echo esc_html( ea_app_signup_site() ); ?></code> · default sport: <code><?php echo esc_html( ea_app_signup_site_sport() ); ?></code></p>

        <?php if ( isset( $_GET['saved'] ) ) : ?>
            <div class="notice notice-success"><p>Settings saved.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['sent'] ) && is_array( $tally ) ) : ?>
            <div class="notice notice-info"><p>Last run:
                <?php
                $parts = array();
                foreach ( $tally as $k => $n ) {
                    $parts[] = esc_html( $k ) . ' ' . (int) $n;
                }
                echo $parts ? implode( ' · ', $parts ) : 'nothing to send';
                ?>
            </p></div>
        <?php endif; ?>

        <h2>Status</h2>
        <table class="widefat striped" style="max-width:620px">
            <thead><tr><th></th><th>Newsletter</th><th>Free trials</th></tr></thead>
            <tbody>
                <?php $trials = ea_app_signup_counts( 'free_trial' ); ?>
                <tr><td>Entries in WordPress</td><td><?php echo (int) $counts['entries']; ?></td><td><?php echo (int) $trials['entries']; ?></td></tr>
                <tr><td>Sent to the app (newsletter: one per city)</td><td><?php echo (int) $counts['synced']; ?></td><td><?php echo (int) $trials['synced']; ?></td></tr>
                <tr><td>Not sent yet</td><td><?php echo (int) $counts['pending']; ?></td><td><?php echo (int) $trials['pending']; ?></td></tr>
                <tr><td>Waiting for the app to accept this type</td><td><?php echo (int) $counts['waiting']; ?></td><td><?php echo (int) $trials['waiting']; ?></td></tr>
                <tr><td>Rejected by the app (bad data)</td><td><?php echo (int) $counts['failed']; ?></td><td><?php echo (int) $trials['failed']; ?></td></tr>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
            <?php wp_nonce_field( 'ea_app_sync_send' ); ?>
            <input type="hidden" name="action" value="ea_app_sync_send">
            <?php submit_button( 'Send unsynced signups (' . (int) EA_APP_SIGNUP_BATCH . ' at a time)', 'primary', 'submit', false, '' === $source ? array( 'disabled' => 'disabled' ) : array() ); ?>
            <p class="description">Sends newsletter signups, then free trials, not yet in the app — oldest first, with their original date. Safe to click again: repeats are ignored by the app.</p>
        </form>

        <h2>Connection</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'ea_app_sync_save' ); ?>
            <input type="hidden" name="action" value="ea_app_sync_save">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ea_app_signup_secret">Site secret</label></th>
                    <td>
                        <?php if ( 'wp-config.php' === $source ) : ?>
                            <p>Set in <code>wp-config.php</code> (EA_APP_SIGNUP_SECRET).</p>
                        <?php else : ?>
                            <input type="password" id="ea_app_signup_secret" name="ea_app_signup_secret" class="regular-text" autocomplete="new-password" placeholder="<?php echo 'settings' === $source ? 'Saved — paste a new one to replace' : 'Paste from Supabase → Vault'; ?>">
                            <p class="description">Supabase → Vault → <code>website_signup_secret_<?php echo esc_html( ea_app_signup_site() ); ?></code>. Never shown again after saving.</p>
                            <?php if ( 'settings' === $source ) : ?>
                                <label><input type="checkbox" name="ea_app_signup_clear" value="1"> Remove the saved secret</label>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ea_app_signup_url">Endpoint URL</label></th>
                    <td><input type="url" id="ea_app_signup_url" name="ea_app_signup_url" class="large-text code" value="<?php echo esc_attr( ea_app_signup_url() ); ?>"></td>
                </tr>
            </table>
            <?php submit_button( 'Save' ); ?>
        </form>
    </div>
    <?php
}

// Nudge on the Newsletter screen until the secret is set.
add_action( 'admin_notices', function () {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || 'edit-ea_newsletter' !== $screen->id || '' !== ea_app_signup_secret_source() ) {
        return;
    }
    printf(
        '<div class="notice notice-warning"><p>Newsletter signups are not reaching the EA app yet: add this site\'s secret in <a href="%s">Settings → EA App Sync</a>.</p></div>',
        esc_url( admin_url( 'options-general.php?page=ea-app-sync' ) )
    );
} );

// "EA app" column on the Newsletter list.
add_filter( 'manage_ea_newsletter_posts_columns', function ( $columns ) {
    $columns['ea_app_sync'] = 'EA app';
    return $columns;
}, 20 );

add_action( 'manage_ea_newsletter_posts_custom_column', function ( $column, $post_id ) {
    if ( 'ea_app_sync' !== $column ) {
        return;
    }
    $state = ea_app_signup_state( $post_id );
    $parts = array();
    foreach ( ea_app_signup_entry_locations( $post_id ) as $location ) {
        $row    = $state[ $location ] ?? null;
        $status = $row ? $row['status'] : 'not sent';
        $label  = 'synced' === $status ? 'sent' : ( 'failed' === $status ? 'rejected: ' . $row['error'] : str_replace( '_', ' ', $status ) );
        $parts[] = ( '' !== $location ? esc_html( $location ) . ': ' : '' ) . esc_html( $label );
    }
    echo implode( '<br>', $parts );
}, 20, 2 );

// "EA app" column on the Free Trials list.
add_filter( 'manage_ea_free_trial_posts_columns', function ( $columns ) {
    $columns['ea_app_sync'] = 'EA app';
    return $columns;
}, 20 );

add_action( 'manage_ea_free_trial_posts_custom_column', function ( $column, $post_id ) {
    if ( 'ea_app_sync' !== $column ) {
        return;
    }
    $row    = ea_app_signup_state( $post_id )[''] ?? null;
    $status = $row ? $row['status'] : 'not sent';
    echo esc_html( 'synced' === $status ? 'sent' : ( 'failed' === $status ? 'rejected: ' . $row['error'] : str_replace( '_', ' ', $status ) ) );
}, 20, 2 );
