<?php
/**
 * Free trials: never book a session that has already started.
 *
 * The trial forms only list sessions that haven't started yet (src/lib/trialTime.js),
 * but a page left open, a wrong device clock or a crafted request could still send
 * one. ea_handle_free_trial() calls ea_trial_session_started() FIRST, before the
 * duplicate guard, so a rejected booking creates no ea_free_trial post, no
 * website_signups row, no email and no Pixel event.
 *
 * Rule (America/Toronto time): the session id is "PROGRAM@YYYY-MM-DD".
 *   - a day before today              → started
 *   - a day after today               → not started
 *   - today                           → started once the program's start time (feed "Time") has passed
 *   - today, start time unknown       → allowed (the date-only rule the forms used before)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Minutes after midnight of "6:00 - 8:00 PM" / "11:30 - 1:30 PM" → start time, or null. */
function ea_trial_start_minutes( $time_text ) {
	$text = (string) $time_text;
	if ( ! preg_match( '/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $text, $start, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}
	preg_match_all( '/(am|pm)/i', $text, $mers );
	$last_mer = $mers[1] ? strtolower( end( $mers[1] ) ) : '';
	$to_min   = function ( $h, $m, $mer ) {
		return ( ( (int) $h % 12 ) + ( 'pm' === strtolower( (string) $mer ) ? 12 : 0 ) ) * 60 + (int) $m;
	};
	$own_mer = isset( $start[3] ) && '' !== $start[3][0] ? $start[3][0] : '';
	$mins    = $to_min( $start[1][0], isset( $start[2] ) ? $start[2][0] : 0, '' !== $own_mer ? $own_mer : $last_mer );
	$rest    = substr( $text, $start[0][1] + strlen( $start[0][0] ) );
	if ( '' === $own_mer && preg_match( '/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $rest, $end ) ) {
		$end_mins = $to_min( $end[1], isset( $end[2] ) ? $end[2] : 0, ! empty( $end[3] ) ? $end[3] : $last_mer );
		if ( $mins > $end_mins ) {
			$mins -= 12 * 60; // "11:30 - 1:30 PM" starts at 11:30 AM
		}
	}
	return ( $mins >= 0 && $mins < 24 * 60 ) ? $mins : null;
}

/** Program rows from the shared feed (cached; same cache as the SEO module when it's loaded). */
function ea_trial_feed_rows() {
	if ( function_exists( 'ea_seo_programs' ) ) {
		return (array) ea_seo_programs();
	}
	$cached = get_transient( 'ea_trial_feed' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$url  = defined( 'EA_SEO_FEED_URL' ) ? EA_SEO_FEED_URL : 'https://curtisbuilds.github.io/ea-programs-json/data/programs.json';
	$resp = wp_remote_get( $url, array( 'timeout' => 4 ) );
	$list = array();
	if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$list = is_array( $data ) ? $data : array();
	}
	set_transient( 'ea_trial_feed', $list, $list ? 30 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
	return $list;
}

/** True when the session "PROGRAM@YYYY-MM-DD" has already started (Toronto time). */
function ea_trial_session_started( $session_id ) {
	if ( ! preg_match( '/^(.+)@(\d{4}-\d{2}-\d{2})$/', trim( (string) $session_id ), $m ) ) {
		return false; // no dated id (old Customizer session list): nothing to check
	}
	$tz    = new DateTimeZone( 'America/Toronto' );
	$now   = new DateTime( 'now', $tz );
	$today = $now->format( 'Y-m-d' );
	if ( $m[2] < $today ) {
		return true;
	}
	if ( $m[2] > $today ) {
		return false;
	}
	$program = strtolower( trim( $m[1] ) );
	foreach ( ea_trial_feed_rows() as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$ids = array( strtolower( trim( (string) ( $row['ProgramID'] ?? '' ) ) ), strtolower( trim( (string) ( $row['ListingCode'] ?? '' ) ) ) );
		if ( ! in_array( $program, $ids, true ) ) {
			continue;
		}
		$mins = ea_trial_start_minutes( $row['Time'] ?? '' );
		if ( null === $mins ) {
			return false;
		}
		$now_mins = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
		return $now_mins >= $mins;
	}
	return false;
}
