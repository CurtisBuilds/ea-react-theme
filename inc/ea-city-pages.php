<?php
/**
 * EA automatic city pages — creates a town page the same day a program upload
 * adds a city that has no page on this site yet.
 *
 * HOW IT WORKS
 *   Every hour (WP-Cron) the site reads the program feed + cities feed and
 *   finds active programs for this site's sport that no published town page
 *   lists. For each such city it copies the standard town page (layout,
 *   template, Elementor data, search fields), swaps in the new city's name,
 *   publishes it at the address the feed uses (e.g. /collingwood/), and emails
 *   info@ what it made. The town template then pulls that city's programs by
 *   itself, so the Register buttons come straight from the feed.
 *
 * SAFETY
 *   - Never edits, moves or deletes an existing page.
 *   - Skips (and reports) an address that already exists in any state
 *     (draft, trash, private…), is redirected by the Redirection plugin, or is
 *     on the "Never create" list — those were deliberate decisions.
 *   - Any feed problem → does nothing that run.
 *   - At most 5 new pages per run; the rest wait for the next run and are reported.
 *   - Off switch, "Run now" and a log on Settings → EA Search.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EA_CITYPAGES_MAX_PER_RUN = 5;

function ea_citypages_enabled() {
	return '1' === (string) get_option( 'ea_citypages_enabled', '1' );
}

function ea_citypages_host() {
	return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
}

/** Page whose layout new town pages copy (setting, else this site's standard town page). */
function ea_citypages_source_id() {
	$id = (int) get_option( 'ea_citypages_source', 0 );
	if ( ! $id ) {
		$host = ea_citypages_host();
		if ( false !== strpos( $host, 'pickleball' ) ) {
			$id = 17386; // /alliston/
		} elseif ( false !== strpos( $host, 'badminton' ) ) {
			$id = 2101;  // /nanaimo/
		} else {
			$id = 15701; // /essa/
		}
	}
	$p = get_post( $id );
	return ( $p && 'page' === $p->post_type && 'publish' === $p->post_status ) ? $id : 0;
}

function ea_citypages_sport_label() {
	$host = ea_citypages_host();
	if ( false !== strpos( $host, 'pickleball' ) ) {
		return 'Pickleball';
	}
	if ( false !== strpos( $host, 'badminton' ) ) {
		return 'Badminton';
	}
	return 'Basketball';
}

function ea_citypages_never_slugs() {
	$raw = (string) get_option( 'ea_citypages_never', '' );
	return array_values( array_filter( array_map( 'ea_clean_slug', preg_split( '/[\s,]+/', $raw ) ), 'strlen' ) );
}

/** Slug a program's town page should live at on this site, or '' if its page lives on another site. */
function ea_citypages_target_slug( $p ) {
	$url = trim( (string) ( $p['URL'] ?? ( $p['url'] ?? '' ) ) );
	if ( '' !== $url ) {
		$full = preg_match( '#^https?://#i', $url ) ? $url : 'https://' . ltrim( $url, '/' );
		$host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $full, PHP_URL_HOST ) ) );
		if ( $host && false !== strpos( $host, '.' ) && $host !== ea_citypages_host() ) {
			return '';
		}
		$segs = ea_clean_url_segments( $full );
		if ( $segs ) {
			return end( $segs );
		}
	}
	$ident = ea_clean_slug( $p['pageIdentifyer'] ?? ( $p['pageIdentifier'] ?? '' ) );
	return $ident ? $ident : ea_clean_slug( $p['City'] ?? '' );
}

/** Published town pages (both town templates). */
function ea_citypages_town_pages() {
	return get_posts( array(
		'post_type'        => 'page',
		'post_status'      => 'publish',
		'posts_per_page'   => -1,
		'no_found_rows'    => true,
		'suppress_filters' => true,
		'meta_query'       => array( array( 'key' => '_wp_page_template', 'value' => array( 'template-city-programs.php', 'template-city-programs-free-trial.php' ), 'compare' => 'IN' ) ),
	) );
}

/** Anything already at this address (any post type, any state incl. trash). */
function ea_citypages_slug_taken( $slug ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
		"SELECT ID, post_status FROM {$wpdb->posts} WHERE post_name IN (%s, %s) AND post_type IN ('page','post') LIMIT 1",
		$slug, $slug . '__trashed'
	) );
	return $row ? $row->post_status : '';
}

function ea_citypages_slug_redirected( $slug ) {
	global $wpdb;
	$table = $wpdb->prefix . 'redirection_items';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return false;
	}
	return (bool) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
		"SELECT id FROM {$table} WHERE status = 'enabled' AND url IN (%s, %s) LIMIT 1",
		'/' . $slug . '/', '/' . $slug
	) );
}

/**
 * Work out what to do. Returns null if a feed is down, else
 * array( 'create' => [slug => [city, programs[]]], 'skip' => [slug => reason] ).
 */
function ea_citypages_plan() {
	$programs = ea_clean_programs();
	$cities   = ea_clean_cities();
	if ( null === $programs || null === $cities ) {
		return null;
	}
	// Programs already listed by some published town page.
	$covered = array();
	foreach ( ea_citypages_town_pages() as $page ) {
		$list = ea_clean_town_programs( $page->post_name );
		if ( null === $list ) {
			return null;
		}
		foreach ( $list as $p ) {
			$covered[ (string) ( $p['ProgramID'] ?? md5( wp_json_encode( $p ) ) ) ] = true;
		}
	}
	$aliases = ea_clean_sport_aliases();
	$today   = wp_date( 'Y-m-d' );
	$want    = array();
	foreach ( $programs as $p ) {
		if ( ! is_array( $p ) || ! empty( $p['is_cancelled'] ) || '' === trim( (string) ( $p['City'] ?? '' ) ) ) {
			continue;
		}
		$sport = strtolower( trim( (string) ( $p['sport'] ?? ( $p['Sport'] ?? '' ) ) ) );
		$cat   = strtoupper( trim( (string) ( $p['Category'] ?? '' ) ) );
		if ( ! in_array( $sport, $aliases, true ) || ! in_array( $cat, array( 'EA', 'TS' ), true ) || ! ea_clean_program_active( $p, $today ) ) {
			continue;
		}
		if ( isset( $covered[ (string) ( $p['ProgramID'] ?? md5( wp_json_encode( $p ) ) ) ] ) ) {
			continue;
		}
		$slug = ea_citypages_target_slug( $p );
		if ( '' === $slug ) {
			continue;
		}
		$rec  = ea_clean_city_record( $cities, $slug );
		$city = trim( (string) ( $rec['City'] ?? '' ) );
		if ( '' === $city || ea_clean_slug( $city ) !== ea_clean_slug( $p['City'] ) ) {
			$city = trim( (string) $p['City'] );
		}
		$want[ $slug ]['city']       = $city;
		$want[ $slug ]['programs'][] = (string) ( $p['ProgramID'] ?? '' );
	}
	$never = ea_citypages_never_slugs();
	$plan  = array( 'create' => array(), 'skip' => array() );
	foreach ( $want as $slug => $info ) {
		$label = $info['city'] . ' (' . implode( ', ', array_filter( $info['programs'] ) ) . ')';
		if ( in_array( $slug, $never, true ) ) {
			$plan['skip'][ $slug ] = $label . ': on the "Never create" list';
		} elseif ( $status = ea_citypages_slug_taken( $slug ) ) {
			$plan['skip'][ $slug ] = $label . ': /' . $slug . '/ already exists as ' . $status . ' — not recreated';
		} elseif ( ea_citypages_slug_redirected( $slug ) ) {
			$plan['skip'][ $slug ] = $label . ': /' . $slug . '/ is redirected — not recreated';
		} elseif ( count( $plan['create'] ) >= EA_CITYPAGES_MAX_PER_RUN ) {
			$plan['skip'][ $slug ] = $label . ': waiting for the next run (limit ' . EA_CITYPAGES_MAX_PER_RUN . ' per run)';
		} else {
			$plan['create'][ $slug ] = $info;
		}
	}
	return $plan;
}

/** Swap the source city's name for the new one (whole words + its address). */
function ea_citypages_swap( $text, $from, $to, $from_slug, $to_slug ) {
	if ( ! is_string( $text ) || '' === $text ) {
		return $text;
	}
	if ( '' !== $from ) {
		$text = preg_replace( '/(?<![A-Za-z])' . preg_quote( $from, '/' ) . '(?![A-Za-z])/', $to, $text );
		$text = preg_replace( '/(?<![A-Za-z])' . preg_quote( strtoupper( $from ), '/' ) . '(?![A-Za-z])/', strtoupper( $to ), $text );
	}
	if ( '' !== $from_slug ) {
		$text = str_replace( array( '/' . $from_slug . '/', '\\/' . $from_slug . '\\/' ), array( '/' . $to_slug . '/', '\\/' . $to_slug . '\\/' ), $text );
	}
	return $text;
}

/** Copy the source town page into a new published page. Returns new ID or WP_Error. */
function ea_citypages_create( $slug, $city ) {
	$src_id = ea_citypages_source_id();
	if ( ! $src_id ) {
		return new WP_Error( 'ea_citypages_source', 'Layout page not found (Settings → EA Search → Copy layout from page ID).' );
	}
	$src       = get_post( $src_id );
	$cities    = ea_clean_cities();
	$src_rec   = $cities ? ea_clean_city_record( $cities, $src->post_name ) : null;
	$from      = trim( (string) ( $src_rec['City'] ?? '' ) );
	$from_slug = $src->post_name;
	if ( '' === $from || false === stripos( $src->post_title, $from ) ) {
		$from = ucwords( str_replace( '-', ' ', $from_slug ) );
	}
	$title = false !== stripos( $src->post_title, $from )
		? ea_citypages_swap( $src->post_title, $from, $city, $from_slug, $slug )
		: $city . ' ' . ea_citypages_sport_label();

	if ( wp_unique_post_slug( $slug, 0, 'publish', 'page', (int) $src->post_parent ) !== $slug ) { // never take a "-2" address
		return new WP_Error( 'ea_citypages_slug', '/' . $slug . '/ is not free.' );
	}
	$new_id = wp_insert_post( wp_slash( array(
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'post_title'     => $title,
		'post_name'      => $slug,
		'post_content'   => ea_citypages_swap( $src->post_content, $from, $city, $from_slug, $slug ),
		'post_excerpt'   => ea_citypages_swap( $src->post_excerpt, $from, $city, $from_slug, $slug ),
		'post_author'    => (int) $src->post_author,
		'post_parent'    => (int) $src->post_parent,
		'comment_status' => 'closed',
		'ping_status'    => $src->ping_status,
		'page_template'  => (string) get_post_meta( $src_id, '_wp_page_template', true ),
	) ), true );
	if ( is_wp_error( $new_id ) ) {
		return $new_id;
	}
	$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_elementor_css', '_elementor_element_cache', '_elementor_page_assets', '_wp_page_template', '_wp_trash_meta_status', '_wp_trash_meta_time' );
	foreach ( get_post_meta( $src_id ) as $key => $values ) {
		if ( in_array( $key, $skip, true ) ) {
			continue;
		}
		foreach ( $values as $v ) {
			$v = maybe_unserialize( $v );
			if ( is_string( $v ) ) {
				$v = ea_citypages_swap( $v, $from, $city, $from_slug, $slug );
			}
			add_post_meta( $new_id, $key, wp_slash( $v ) );
		}
	}
	update_post_meta( $new_id, '_ea_auto_city_page', wp_date( 'c' ) );
	return $new_id;
}

function ea_citypages_log( $line ) {
	$log   = (array) get_option( 'ea_citypages_log', array() );
	array_unshift( $log, wp_date( 'Y-m-d H:i' ) . ' — ' . $line );
	update_option( 'ea_citypages_log', array_slice( $log, 0, 30 ), false );
}

/** One run. $manual = from the "Run now" button. Returns a short summary. */
function ea_citypages_run( $manual = false ) {
	if ( ! $manual && ! ea_citypages_enabled() ) {
		return 'Off.';
	}
	if ( get_transient( 'ea_citypages_lock' ) ) {
		return 'Already running.';
	}
	set_transient( 'ea_citypages_lock', 1, 5 * MINUTE_IN_SECONDS );
	delete_transient( 'ea_clean_cities' ); // fresh town list for this check

	$plan = ea_citypages_plan();
	if ( null === $plan ) {
		delete_transient( 'ea_citypages_lock' );
		ea_citypages_log( 'Feed not available — nothing done.' );
		return 'Feed not available — nothing done.';
	}
	$made = array();
	$fail = array();
	foreach ( $plan['create'] as $slug => $info ) {
		$id = ea_citypages_create( $slug, $info['city'] );
		if ( is_wp_error( $id ) ) {
			$fail[] = $info['city'] . ': ' . $id->get_error_message();
		} else {
			$made[] = $info['city'] . ' — ' . get_permalink( $id ) . ' (programs ' . implode( ', ', array_filter( $info['programs'] ) ) . ')';
		}
	}
	if ( $made ) {
		delete_transient( 'ea_clean_hidden_ids' );
		delete_transient( 'ea_clean_redirected_ids' );
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
	}

	// Email info@ when pages were made, or when the list of skipped cities changes.
	$skip_sig = md5( wp_json_encode( array_keys( $plan['skip'] ) ) );
	$skip_new = $plan['skip'] && get_option( 'ea_citypages_skip_sig' ) !== $skip_sig;
	update_option( 'ea_citypages_skip_sig', $plan['skip'] ? $skip_sig : '', false );
	if ( $made || $fail || $skip_new ) {
		$body = '';
		if ( $made ) {
			$body .= "New city pages created and published:\n- " . implode( "\n- ", $made ) . "\n\n";
		}
		if ( $fail ) {
			$body .= "Could not create:\n- " . implode( "\n- ", $fail ) . "\n\n";
		}
		if ( $plan['skip'] ) {
			$body .= "Cities with programs but no page, left alone:\n- " . implode( "\n- ", $plan['skip'] ) . "\n\n";
		}
		$body .= "Settings, log and off switch: " . admin_url( 'options-general.php?page=ea-search' ) . "\n";
		wp_mail( 'info@elevationathletics.ca', sprintf( '[%s] %s', ea_citypages_host(), $made ? count( $made ) . ' new city page(s)' : 'City pages need a look' ), $body );
	}

	$summary = sprintf( '%d created, %d skipped, %d failed.', count( $made ), count( $plan['skip'] ), count( $fail ) );
	if ( $made || $fail || $manual ) {
		ea_citypages_log( $summary . ( $made ? ' ' . implode( '; ', $made ) : '' ) . ( $fail ? ' Failed: ' . implode( '; ', $fail ) : '' ) );
	}
	update_option( 'ea_citypages_last_run', wp_date( 'Y-m-d H:i' ) . ' — ' . $summary, false );
	delete_transient( 'ea_citypages_lock' );
	return $summary;
}

// ─── Schedule: hourly ────────────────────────────────────────────────────────

add_action( 'ea_citypages_cron', 'ea_citypages_run' );

add_action( 'init', function () {
	if ( ea_citypages_enabled() && ! wp_next_scheduled( 'ea_citypages_cron' ) ) {
		wp_schedule_event( time() + 120, 'hourly', 'ea_citypages_cron' );
	} elseif ( ! ea_citypages_enabled() && wp_next_scheduled( 'ea_citypages_cron' ) ) {
		wp_clear_scheduled_hook( 'ea_citypages_cron' );
	}
} );

add_action( 'switch_theme', function () {
	wp_clear_scheduled_hook( 'ea_citypages_cron' );
} );

// ─── Settings → EA Search ────────────────────────────────────────────────────

add_action( 'admin_post_ea_citypages_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'ea_citypages_save' );
	update_option( 'ea_citypages_enabled', empty( $_POST['ea_citypages_enabled'] ) ? '0' : '1' );
	update_option( 'ea_citypages_source', absint( $_POST['ea_citypages_source'] ?? 0 ) );
	update_option( 'ea_citypages_never', sanitize_textarea_field( wp_unslash( $_POST['ea_citypages_never'] ?? '' ) ) );
	$msg = '';
	if ( ! empty( $_POST['ea_citypages_run'] ) ) {
		$msg = ea_citypages_run( true );
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'ea-search', 'saved' => 1, 'citypages' => rawurlencode( $msg ) ), admin_url( 'options-general.php' ) ) );
	exit;
} );

function ea_citypages_settings_section() {
	$on   = ea_citypages_enabled();
	$src  = ea_citypages_source_id();
	$log  = (array) get_option( 'ea_citypages_log', array() );
	$next = wp_next_scheduled( 'ea_citypages_cron' );
	?>
	<hr />
	<h2>Create city pages automatically</h2>
	<p>Every hour the site checks the program feed. When a city has active programs but no town page here, it copies the standard town page, publishes it at the feed's address (e.g. /collingwood/) and emails info@. Existing, drafted, trashed or redirected addresses are never touched.</p>
	<?php if ( isset( $_GET['citypages'] ) && '' !== $_GET['citypages'] ) : // phpcs:ignore ?>
		<div class="notice notice-info inline"><p>Run now: <?php echo esc_html( wp_unslash( $_GET['citypages'] ) ); // phpcs:ignore ?></p></div>
	<?php endif; ?>
	<p>Last check: <?php echo esc_html( (string) get_option( 'ea_citypages_last_run', 'not yet' ) ); ?><?php echo $on && $next ? ' · next ' . esc_html( wp_date( 'H:i', $next ) ) : ''; ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ea_citypages_save" />
		<?php wp_nonce_field( 'ea_citypages_save' ); ?>
		<p><label><input type="checkbox" name="ea_citypages_enabled" value="1" <?php checked( $on ); ?> /> Create city pages automatically</label></p>
		<p><label>Copy layout from page ID: <input type="number" name="ea_citypages_source" value="<?php echo esc_attr( (string) get_option( 'ea_citypages_source', '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $src ); ?>" style="width:8em" /></label>
			<?php if ( $src ) : ?> (now: <a href="<?php echo esc_url( get_permalink( $src ) ); ?>"><?php echo esc_html( get_the_title( $src ) ); ?></a>)<?php else : ?> <strong>— layout page not found</strong><?php endif; ?></p>
		<p><label for="ea_citypages_never"><strong>Never create</strong> (page slugs, one per line or comma-separated):</label><br />
		<textarea id="ea_citypages_never" name="ea_citypages_never" rows="3" cols="60"><?php echo esc_textarea( (string) get_option( 'ea_citypages_never', '' ) ); ?></textarea></p>
		<?php submit_button( 'Save', 'secondary', 'ea_citypages_save_btn', false ); ?>
		<?php submit_button( 'Save and run now', 'primary', 'ea_citypages_run', false ); ?>
	</form>
	<?php if ( $log ) : ?>
		<h3>Log</h3>
		<ul style="list-style:disc;padding-left:20px"><?php foreach ( array_slice( $log, 0, 10 ) as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ul>
	<?php endif; ?>
	<?php
}
