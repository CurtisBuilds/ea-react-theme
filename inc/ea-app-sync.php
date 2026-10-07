<?php
/**
 * EA app sync — sends website newsletter signups to the EA Operations app.
 *
 * Replaces the Constant Contact push (EA no longer uses Constant Contact).
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

function ea_app_signup_sport() {
    $by_site = array(
        'eapickleball.com'      => 'pb',
        'eabadminton.com'       => 'bad',
        'elevationathletics.ca' => 'bask',
    );
    $site = ea_app_signup_site();
    if ( isset( $by_site[ $site ] ) ) {
        return $by_site[ $site ];
    }
    $sport = strtolower( function_exists( 'ea_default_sport_value' ) ? ea_default_sport_value() : '' );
    if ( false !== strpos( $sport, 'badminton' ) ) return 'bad';
    if ( false !== strpos( $sport, 'basketball' ) ) return 'bask';
    return 'pb';
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
//     status: synced | retry | auth | not_configured | failed,
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

// ─── Send one signup ─────────────────────────────────────────────────────────

/**
 * Post one (entry, location) signup to the EA app. Never throws; the WordPress
 * entry is already saved, so a failure here only marks the entry for retry.
 *
 * @param int    $entry_id ea_newsletter post id.
 * @param string $location City the visitor subscribed to ('' = general signup).
 * @param array  $extra    province, session_start, program_summary, page_url, submitted_at (ISO).
 * @return array The stored state row.
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

    $submitted_at = ! empty( $extra['submitted_at'] )
        ? (string) $extra['submitted_at']
        : ( ! empty( $previous['submitted_at'] ) ? (string) $previous['submitted_at'] : ea_app_signup_iso( time() ) );

    $row = array(
        'status'       => 'retry',
        'result'       => '',
        'error'        => '',
        'attempts'     => (int) ( $previous['attempts'] ?? 0 ) + 1,
        'at'           => time(),
        'submitted_at' => $submitted_at,
    );

    $secret = ea_app_signup_secret();
    if ( '' === $secret ) {
        $row['status']   = 'not_configured';
        $row['error']    = 'No site secret set (Settings → EA App Sync).';
        $row['attempts'] = (int) ( $previous['attempts'] ?? 0 );
        ea_app_signup_save_state( $entry_id, $location, $row );
        return $row;
    }

    $body = array(
        'type'            => 'newsletter',
        'site'            => ea_app_signup_site(),
        'wp_entry_id'     => $entry_id,
        'sent_at'         => ea_app_signup_iso( time() ),
        'submitted_at'    => $submitted_at,
        'email'           => $email,
        'location'        => $location,
        'province'        => (string) ( $extra['province'] ?? '' ),
        'sport'           => ea_app_signup_sport(),
        'session_start'   => (string) ( $extra['session_start'] ?? '' ),
        'program_summary' => (string) ( $extra['program_summary'] ?? '' ),
        'page_url'        => (string) ( $extra['page_url'] ?? '' ),
        'consent_text'    => ea_app_signup_consent_text( $location ),
    );
    $json = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

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

    if ( is_wp_error( $response ) ) {
        $row['error'] = $response->get_error_message();
    } else {
        $code    = (int) wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $decoded = is_array( $decoded ) ? $decoded : array();

        if ( 200 === $code && ! empty( $decoded['ok'] ) ) {
            $row['status'] = 'synced';
            $row['result'] = (string) ( $decoded['result'] ?? '' );
        } elseif ( 400 === $code ) {
            // Bad data (e.g. invalid_email): retrying will not help.
            $row['status'] = 'failed';
            $row['error']  = (string) ( $decoded['error'] ?? 'bad_request' );
        } elseif ( 401 === $code ) {
            // Wrong/missing secret on one side. Retried once the secret is fixed.
            $row['status'] = 'auth';
            $row['error']  = (string) ( $decoded['error'] ?? 'unauthorized' );
        } else {
            $row['error'] = 'HTTP ' . $code;
        }
    }

    ea_app_signup_save_state( $entry_id, $location, $row );

    if ( 'retry' === $row['status'] && ! wp_next_scheduled( 'ea_app_signup_retry' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'ea_app_signup_retry' );
    }

    return $row;
}

// ─── Queue: unsynced (entry, location) pairs, oldest first ───────────────────

function ea_app_signup_pending( $limit, $statuses = array( 'retry', 'auth', 'not_configured', 'new' ) ) {
    $ids = get_posts( array(
        'post_type'      => 'ea_newsletter',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ) );

    $queue = array();
    foreach ( $ids as $entry_id ) {
        $state = ea_app_signup_state( $entry_id );
        foreach ( ea_app_signup_entry_locations( $entry_id ) as $location ) {
            $row    = $state[ $location ] ?? null;
            $status = $row ? ( $row['status'] ?? 'new' ) : 'new';
            if ( ! in_array( $status, $statuses, true ) ) {
                continue;
            }
            if ( 'retry' === $status && (int) ( $row['attempts'] ?? 0 ) >= EA_APP_SIGNUP_MAX_ATTEMPTS ) {
                continue;
            }
            $queue[] = array( (int) $entry_id, $location );
            if ( count( $queue ) >= $limit ) {
                return $queue;
            }
        }
    }
    return $queue;
}

function ea_app_signup_send_queued( $entry_id, $location ) {
    // Backfilled signups keep the date they were made.
    $extra = array(
        'submitted_at' => ea_app_signup_iso( (int) get_post_time( 'U', true, $entry_id ) ),
    );
    return ea_app_signup_send( $entry_id, $location, $extra );
}

function ea_app_signup_counts() {
    $counts = array( 'synced' => 0, 'pending' => 0, 'failed' => 0 );
    $ids    = get_posts( array(
        'post_type'      => 'ea_newsletter',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );
    foreach ( $ids as $entry_id ) {
        $state = ea_app_signup_state( $entry_id );
        foreach ( ea_app_signup_entry_locations( $entry_id ) as $location ) {
            $status = $state[ $location ]['status'] ?? 'new';
            if ( 'synced' === $status ) {
                $counts['synced']++;
            } elseif ( 'failed' === $status ) {
                $counts['failed']++;
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
    $queue = ea_app_signup_pending( 50, array( 'retry' ) );
    foreach ( $queue as $item ) {
        ea_app_signup_send( $item[0], $item[1] );
    }
    if ( ! ea_app_signup_pending( 1, array( 'retry' ) ) ) {
        wp_clear_scheduled_hook( 'ea_app_signup_retry' );
    }
} );

add_action( 'switch_theme', function () {
    wp_clear_scheduled_hook( 'ea_app_signup_retry' );
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
        $row = ea_app_signup_send_queued( $item[0], $item[1] );
        $key = 'synced' === $row['status'] ? ( $row['result'] ? $row['result'] : 'synced' ) : $row['status'];
        $tally[ $key ] = ( $tally[ $key ] ?? 0 ) + 1;
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
        <p>Newsletter signups on this site are saved in WordPress, then sent to the EA Operations app.
           Site name sent: <code><?php echo esc_html( ea_app_signup_site() ); ?></code> · sport: <code><?php echo esc_html( ea_app_signup_sport() ); ?></code></p>

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
        <table class="widefat striped" style="max-width:520px">
            <tbody>
                <tr><td>Newsletter entries</td><td><?php echo (int) $counts['entries']; ?></td></tr>
                <tr><td>Signups sent to the app (one per city)</td><td><?php echo (int) $counts['synced']; ?></td></tr>
                <tr><td>Not sent yet</td><td><?php echo (int) $counts['pending']; ?></td></tr>
                <tr><td>Rejected by the app (bad data)</td><td><?php echo (int) $counts['failed']; ?></td></tr>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
            <?php wp_nonce_field( 'ea_app_sync_send' ); ?>
            <input type="hidden" name="action" value="ea_app_sync_send">
            <?php submit_button( 'Send unsynced signups (' . (int) EA_APP_SIGNUP_BATCH . ' at a time)', 'primary', 'submit', false, '' === $source ? array( 'disabled' => 'disabled' ) : array() ); ?>
            <p class="description">Sends signups not yet in the app, oldest first, with their original signup date. Safe to click again: repeats are ignored by the app.</p>
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
