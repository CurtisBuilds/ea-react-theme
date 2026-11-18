<?php
/**
 * EA public REST forms — never reject a visitor over the "Cookie check".
 *
 * WHY: the React pages send the page's `wp_rest` nonce (X-WP-Nonce) with every
 * form POST. WordPress then checks that nonce even for logged-out visitors, and
 * when it doesn't verify (page served from cache after the nonce expired, or a
 * plugin changing the logged-out nonce session) the request dies with
 * 403 "Cookie check failed" before our handler runs. That silently lost free-
 * trial bookings and newsletter sign-ups (seen 2026-11-17/18).
 *
 * These routes are public (permission_callback __return_true) and never use the
 * current user, so the nonce protects nothing here. For these routes only, an
 * invalid nonce is treated as an anonymous request instead of an error.
 * Everything else in the REST API keeps WordPress's normal check.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ea_rest_public_routes() {
	return array( '/ea/v1/free-trial', '/ea/v1/newsletter', '/ea/v1/venue-coords' );
}

add_filter( 'rest_authentication_errors', function ( $result ) {
	if ( ! is_wp_error( $result ) || 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) {
		return $result;
	}
	$route = '';
	if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
		$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
	} elseif ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	$route = '/' . trim( strtolower( $route ), '/' );
	if ( ! in_array( $route, ea_rest_public_routes(), true ) ) {
		return $result;
	}
	wp_set_current_user( 0 );
	return true;
}, 101 );
