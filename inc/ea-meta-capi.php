<?php
/**
 * inc/ea-meta-capi.php — Meta Conversions API (server-side) for CompleteRegistration.
 *
 * Sends ONE server event per completed, paid WPForms program registration, paired with the
 * browser Pixel event by a shared event_id so Meta counts each registration once.
 *
 *  - When: wpforms_process_complete (WPForms runs it only after the submission — incl. the
 *          Stripe payment — succeeded). Never on page load or a click. Waitlist sign-ups and
 *          forms without a program ID are skipped.
 *  - Dedup: the browser Pixel adds a hidden field `ea_capi_eid` to the form at submit time and
 *          fires CompleteRegistration with that same eventID. No eid → no server event (never
 *          risk double counting).
 *  - Data: SHA-256 of normalised email + phone only (never raw), plus _fbp / _fbc cookies
 *          (fbc rebuilt from the carried fbclid when the cookie is missing), IP and user agent.
 *  - Token: only from the EA_META_CAPI_TOKEN constant in wp-config.php. Never stored in the DB,
 *          printed, or sent to the browser.
 *  - Mode (Settings → EA Meta Pixel): off | test (default; only sends with a Test Events code)
 *          | live.
 */

defined( 'ABSPATH' ) || exit;

const EA_CAPI_API_VERSION = 'v23.0';

function ea_capi_token() {
    return defined( 'EA_META_CAPI_TOKEN' ) ? trim( (string) EA_META_CAPI_TOKEN ) : '';
}

function ea_capi_mode() {
    $m = (string) get_option( 'ea_capi_mode', 'test' );
    return in_array( $m, array( 'off', 'test', 'live' ), true ) ? $m : 'test';
}

function ea_capi_test_code() {
    return preg_replace( '/[^A-Za-z0-9]/', '', (string) get_option( 'ea_capi_test_code', '' ) );
}

/** Lowercased, trimmed email → sha256, or ''. */
function ea_capi_hash_email( $email ) {
    $email = strtolower( trim( (string) $email ) );
    return is_email( $email ) ? hash( 'sha256', $email ) : '';
}

/** Digits only, Canadian/US 10-digit numbers get the leading 1 → sha256, or ''. */
function ea_capi_hash_phone( $phone ) {
    $digits = preg_replace( '/\D+/', '', (string) $phone );
    if ( 10 === strlen( $digits ) ) {
        $digits = '1' . $digits;
    }
    return strlen( $digits ) >= 11 ? hash( 'sha256', $digits ) : '';
}

function ea_capi_client_ip() {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $first = trim( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
        if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
            $ip = $first;
        }
    }
    return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/** _fbc cookie, or one rebuilt from the fbclid our attribution cookie carried across EA sites. */
function ea_capi_fbc() {
    if ( ! empty( $_COOKIE['_fbc'] ) ) {
        return substr( sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ), 0, 500 );
    }
    if ( ! function_exists( 'ea_attr_from_request' ) ) {
        return '';
    }
    $attr = ea_attr_from_request();
    foreach ( array( 'last', 'first' ) as $k ) {
        $snap = is_array( $attr ) && is_array( $attr[ $k ] ?? null ) ? $attr[ $k ] : null;
        $id   = $snap ? trim( (string) ( $snap['fbclid'] ?? '' ) ) : '';
        if ( '' !== $id ) {
            $ts = ! empty( $snap['ts'] ) ? strtotime( (string) $snap['ts'] ) : false;
            $ms = $ts ? $ts * 1000 : (int) round( microtime( true ) * 1000 );
            return 'fb.1.' . $ms . '.' . substr( $id, 0, 400 );
        }
    }
    return '';
}

// ─── Build the event on a completed WPForms submission ───────────────────────

add_action( 'wpforms_process_complete', function ( $fields, $entry, $form_data, $entry_id ) {
    try {
        ea_capi_queue_registration( $fields, $entry_id );
    } catch ( \Throwable $e ) {
        // Never let tracking break a registration.
    }
}, 30, 4 );

function ea_capi_queue_registration( $fields, $entry_id ) {
    if ( 'off' === ea_capi_mode() || '' === ea_capi_token() || ! function_exists( 'ea_pixel_id' ) || '' === ea_pixel_id() ) {
        return;
    }
    $eid = isset( $_POST['ea_capi_eid'] ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', (string) wp_unslash( $_POST['ea_capi_eid'] ) ) : '';
    if ( strlen( $eid ) < 12 ) {
        return; // no shared id from the browser → don't send (avoids double counting)
    }

    $pid = '';
    $waitlist = false;
    $email = '';
    $phone = '';
    foreach ( (array) $fields as $f ) {
        $type = (string) ( $f['type'] ?? '' );
        $val  = is_array( $f['value'] ?? '' ) ? '' : trim( (string) ( $f['value'] ?? '' ) );
        if ( '' === $pid && preg_match( '/^EA-(PROGRAM-\d+|[A-Z]{2}-[A-Z0-9]+(-[A-Z0-9]+)*)$/i', $val ) ) {
            $pid = strtoupper( $val );
        }
        if ( 'hidden' === $type && preg_match( '/^waitlist$/i', $val ) ) {
            $waitlist = true;
        }
        if ( '' === $email && 'email' === $type ) {
            $email = $val;
        }
        if ( '' === $phone && 'phone' === $type ) {
            $phone = $val;
        }
    }
    if ( '' === $pid || $waitlist ) {
        return; // not a paid program registration
    }

    $value = isset( $_POST['ea_capi_val'] ) ? (float) preg_replace( '/[^0-9.]/', '', (string) wp_unslash( $_POST['ea_capi_val'] ) ) : 0.0;
    $name  = isset( $_POST['ea_capi_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ea_capi_name'] ) ) : '';

    $user = array_filter( array(
        'em'                => ( $h = ea_capi_hash_email( $email ) ) ? array( $h ) : null,
        'ph'                => ( $h = ea_capi_hash_phone( $phone ) ) ? array( $h ) : null,
        'fbp'               => ! empty( $_COOKIE['_fbp'] ) ? substr( sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ), 0, 200 ) : null,
        'fbc'               => ea_capi_fbc() ?: null,
        'client_ip_address' => ea_capi_client_ip() ?: null,
        'client_user_agent' => ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 500 ) : null,
    ) );

    $custom = array(
        'content_ids'  => array( $pid ),
        'content_type' => 'product',
        'content_name' => '' !== $name ? $name : $pid,
        'value'        => round( max( 0, $value ), 2 ),
        'currency'     => 'CAD',
    );
    if ( preg_match( '/^EA-PROGRAM-457$/i', $pid ) ) {
        $custom['test_event'] = true; // same flag the browser event carries
    }

    $source = ! empty( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : home_url( '/' );

    $event = array(
        'event_name'       => 'CompleteRegistration',
        'event_time'       => time(),
        'event_id'         => $eid,
        'action_source'    => 'website',
        'event_source_url' => $source,
        'user_data'        => $user,
        'custom_data'      => $custom,
    );

    // Send after the response (WP-Cron), so the registration form never waits on Meta.
    wp_schedule_single_event( time(), 'ea_capi_send', array( $event, (int) $entry_id ) );
    if ( function_exists( 'spawn_cron' ) ) {
        spawn_cron();
    }
}

// ─── Send ────────────────────────────────────────────────────────────────────

add_action( 'ea_capi_send', function ( $event, $entry_id = 0 ) {
    $mode  = ea_capi_mode();
    $token = ea_capi_token();
    $test  = ea_capi_test_code();
    if ( 'off' === $mode || '' === $token || ! is_array( $event ) ) {
        return;
    }
    if ( 'test' === $mode && '' === $test ) {
        ea_capi_log( $event, $entry_id, 'skipped', 'test mode without a Test Events code' );
        return;
    }
    $body = array( 'data' => array( $event ) );
    if ( 'test' === $mode ) {
        $body['test_event_code'] = $test;
    }
    $res = wp_remote_post(
        'https://graph.facebook.com/' . EA_CAPI_API_VERSION . '/' . rawurlencode( ea_pixel_id() ) . '/events?access_token=' . rawurlencode( $token ),
        array( 'timeout' => 10, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) )
    );
    if ( is_wp_error( $res ) ) {
        ea_capi_log( $event, $entry_id, 'error', $res->get_error_message() );
        return;
    }
    $code = (int) wp_remote_retrieve_response_code( $res );
    $json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
    $note = 200 === $code ? 'received: ' . (int) ( $json['events_received'] ?? 0 ) : (string) ( $json['error']['message'] ?? ( 'HTTP ' . $code ) );
    ea_capi_log( $event, $entry_id, 200 === $code ? 'sent' : 'error', $note );
}, 10, 2 );

/** Last 25 sends, for the settings page. No personal data: ids, flags and status only. */
function ea_capi_log( $event, $entry_id, $status, $note ) {
    $log   = get_option( 'ea_capi_log', array() );
    $log   = is_array( $log ) ? $log : array();
    $u     = (array) ( $event['user_data'] ?? array() );
    array_unshift( $log, array(
        'time'    => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
        'mode'    => ea_capi_mode(),
        'entry'   => (int) $entry_id,
        'eid'     => (string) ( $event['event_id'] ?? '' ),
        'program' => (string) ( $event['custom_data']['content_ids'][0] ?? '' ),
        'value'   => $event['custom_data']['value'] ?? '',
        'match'   => implode( ' ', array_keys( array_filter( $u ) ) ),
        'status'  => $status,
        'note'    => substr( (string) $note, 0, 200 ),
    ) );
    update_option( 'ea_capi_log', array_slice( $log, 0, 25 ), false );
}

// ─── Settings (shown on Settings → EA Meta Pixel) ────────────────────────────

add_action( 'admin_post_ea_capi_save', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Not allowed' );
    }
    check_admin_referer( 'ea_capi_save' );
    $mode = isset( $_POST['ea_capi_mode'] ) ? (string) wp_unslash( $_POST['ea_capi_mode'] ) : 'test';
    update_option( 'ea_capi_mode', in_array( $mode, array( 'off', 'test', 'live' ), true ) ? $mode : 'test' );
    update_option( 'ea_capi_test_code', preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['ea_capi_test_code'] ?? '' ) ) );
    wp_safe_redirect( admin_url( 'options-general.php?page=ea-meta-pixel&capi=saved' ) );
    exit;
} );

function ea_capi_settings_section() {
    $has_token = '' !== ea_capi_token();
    $mode      = ea_capi_mode();
    $log       = get_option( 'ea_capi_log', array() );
    ?>
    <hr />
    <h2>Conversions API (server events)</h2>
    <p>Sends <strong>CompleteRegistration</strong> from the server for paid WPForms program registrations, paired with the browser event so Meta counts it once. Sends only hashed email/phone — never raw.</p>
    <p><strong>Access token:</strong> <?php echo $has_token ? '<span style="color:#1a7f37">set in wp-config.php ✓</span>' : '<span style="color:#b32d2e">not set — add <code>define( \'EA_META_CAPI_TOKEN\', \'…\' );</code> to wp-config.php</span>'; ?></p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="ea_capi_save" />
        <?php wp_nonce_field( 'ea_capi_save' ); ?>
        <table class="form-table" role="presentation">
            <tr><th>Mode</th><td>
                <select name="ea_capi_mode">
                    <option value="off" <?php selected( 'off', $mode ); ?>>Off</option>
                    <option value="test" <?php selected( 'test', $mode ); ?>>Test — only with the Test Events code (not counted for ads)</option>
                    <option value="live" <?php selected( 'live', $mode ); ?>>Live</option>
                </select></td></tr>
            <tr><th>Test Events code</th><td><input type="text" class="regular-text" name="ea_capi_test_code" value="<?php echo esc_attr( ea_capi_test_code() ); ?>" placeholder="TEST12345" /><p class="description">From Events Manager → Test events → Confirm your server's events are set up correctly.</p></td></tr>
        </table>
        <?php submit_button( 'Save server-event settings' ); ?>
    </form>
    <?php if ( is_array( $log ) && $log ) : ?>
        <h3>Recent server events</h3>
        <table class="widefat striped" style="max-width:1100px"><thead><tr><th>Time</th><th>Mode</th><th>Entry</th><th>Event ID</th><th>Program</th><th>Value</th><th>Match keys</th><th>Status</th><th>Note</th></tr></thead><tbody>
        <?php foreach ( $log as $r ) : ?>
            <tr><td><?php echo esc_html( $r['time'] ); ?></td><td><?php echo esc_html( $r['mode'] ); ?></td><td><?php echo esc_html( $r['entry'] ); ?></td><td><code><?php echo esc_html( $r['eid'] ); ?></code></td><td><?php echo esc_html( $r['program'] ); ?></td><td><?php echo esc_html( (string) $r['value'] ); ?></td><td><?php echo esc_html( $r['match'] ); ?></td><td><?php echo esc_html( $r['status'] ); ?></td><td><?php echo esc_html( $r['note'] ); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif;
}
