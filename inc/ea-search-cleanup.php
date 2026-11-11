<?php
/**
 * EA search clean-up — keeps pages Google calls "empty" or "redirect" out of search.
 *
 *   1. Town pages (template-city-programs.php) with no active programs right now:
 *      <meta robots noindex,follow> and left out of the sitemap. As soon as the
 *      program feed lists a program for that town again, the page is back in
 *      search and the sitemap by itself.
 *   2. "Coming soon" placeholder pages (template-coming-soon.php): noindex and
 *      out of the sitemap.
 *   3. Pages whose address is redirected by the Redirection plugin: out of the
 *      sitemap (Google reports them as "Page with redirect").
 *
 * Visitors see exactly the same pages; only search engines are told to skip them.
 *
 * SAFETY
 *   - A town page is only treated as empty when BOTH feeds (programs + cities)
 *     loaded. Any feed problem → nothing is hidden.
 *   - Matching mirrors CityProgramsPage.jsx (programMatchesPage + findCityRecord)
 *     and is a little broader (programs not yet open for registration still
 *     count), so a page that shows programs is never hidden.
 *   - Off switch: Settings → EA Search → "Hide empty pages from search".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'EA_CITIES_FEED_URL' ) ) {
	define( 'EA_CITIES_FEED_URL', 'https://curtisbuilds.github.io/ea-programs-json/data/cities.json' );
}

function ea_clean_enabled() {
	return '1' === (string) get_option( 'ea_clean_enabled', '1' );
}

function ea_clean_slug( $v ) {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( trim( (string) $v ) ) ), '-' );
}

/** Sport keys this site's town pages show (CityProgramsPage selectedSports + LeagueHub SPORTS aliases). */
function ea_clean_sport_aliases() {
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( false !== strpos( $host, 'pickleball' ) ) {
		return array( 'pb', 'pickleball', 'pickle' );
	}
	if ( false !== strpos( $host, 'badminton' ) ) {
		return array( 'bad', 'badm', 'badmin', 'badminton' );
	}
	return array( 'bask', 'basketball', 'bball' );
}

/** Program feed (shared cache with the search tags). null = not available. */
function ea_clean_programs() {
	if ( ! function_exists( 'ea_seo_programs' ) ) {
		return null;
	}
	$list = ea_seo_programs();
	return ( is_array( $list ) && $list ) ? $list : null;
}

/** Cities feed (town records with their page address). null = not available. */
function ea_clean_cities() {
	$cached = get_transient( 'ea_clean_cities' );
	if ( is_array( $cached ) ) {
		return $cached ? $cached : null;
	}
	$resp = wp_remote_get( EA_CITIES_FEED_URL, array( 'timeout' => 4 ) );
	$list = array();
	if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$list = is_array( $data ) ? array_values( array_filter( $data, 'is_array' ) ) : array();
	}
	set_transient( 'ea_clean_cities', $list, $list ? 30 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
	return $list ? $list : null;
}

function ea_clean_url_segments( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return array();
	}
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	return array_values( array_filter( array_map( 'ea_clean_slug', explode( '/', $path ) ), 'strlen' ) );
}

function ea_clean_record_segments( $c ) {
	return ea_clean_url_segments( $c['PageURL'] ?? ( $c['pageUrl'] ?? ( $c['URL'] ?? '' ) ) );
}

/** findCityRecord(cities, slug) from src/data/cities.js. */
function ea_clean_city_record( $cities, $slug ) {
	foreach ( $cities as $c ) {
		if ( in_array( $slug, ea_clean_record_segments( $c ), true ) ) {
			return $c;
		}
	}
	foreach ( $cities as $c ) {
		if ( ea_clean_slug( $c['City'] ?? ( $c['city'] ?? '' ) ) === $slug ) {
			return $c;
		}
	}
	foreach ( $cities as $c ) {
		foreach ( ea_clean_record_segments( $c ) as $seg ) {
			if ( $seg === $slug || 0 === strpos( $seg, $slug . '-' ) ) {
				return $c;
			}
		}
	}
	return null;
}

/** Program still has a session ahead (or no end date yet). */
function ea_clean_program_active( $p, $today ) {
	$sessions = array_filter( array_map( 'trim', explode( ',', (string) ( $p['SessionDates'] ?? '' ) ) ), function ( $d ) {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
	} );
	if ( $sessions ) {
		foreach ( $sessions as $d ) {
			if ( $d >= $today ) {
				return true;
			}
		}
		return false;
	}
	$end = (string) ( $p['End Date'] ?? ( $p['EndDate'] ?? '' ) );
	return ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $end ) || substr( $end, 0, 10 ) >= $today;
}

/**
 * Active programs the town page with this slug lists (same matching as
 * CityProgramsPage.jsx), or null when a feed is unavailable.
 */
function ea_clean_town_programs( $slug ) {
	static $memo = array();
	$slug = ea_clean_slug( $slug );
	if ( '' === $slug ) {
		return null;
	}
	if ( array_key_exists( $slug, $memo ) ) {
		return $memo[ $slug ];
	}
	$programs = ea_clean_programs();
	$cities   = ea_clean_cities();
	if ( null === $programs || null === $cities ) {
		return $memo[ $slug ] = null;
	}
	$record   = ea_clean_city_record( $cities, $slug );
	$rec_segs = $record ? ea_clean_record_segments( $record ) : array();
	$rec_slug = $record ? ea_clean_slug( $record['City'] ?? ( $record['city'] ?? '' ) ) : '';
	$aliases  = ea_clean_sport_aliases();
	$today    = wp_date( 'Y-m-d' );
	$out      = array();
	foreach ( $programs as $p ) {
		if ( ! is_array( $p ) || ! empty( $p['is_cancelled'] ) || '' === trim( (string) ( $p['City'] ?? '' ) ) ) {
			continue;
		}
		$sport = strtolower( trim( (string) ( $p['sport'] ?? ( $p['Sport'] ?? ( $p['SPORT'] ?? '' ) ) ) ) );
		$cat   = strtoupper( trim( (string) ( $p['Category'] ?? '' ) ) );
		if ( ! in_array( $sport, $aliases, true ) || ! in_array( $cat, array( 'EA', 'TS' ), true ) || ! ea_clean_program_active( $p, $today ) ) {
			continue;
		}
		$ident = ea_clean_slug( $p['pageIdentifyer'] ?? ( $p['pageIdentifier'] ?? ( $p['PageIdentifier'] ?? ( $p['Page Identifier'] ?? '' ) ) ) );
		$psegs = ea_clean_url_segments( $p['URL'] ?? ( $p['url'] ?? '' ) );
		$pcity = ea_clean_slug( $p['City'] ?? ( $p['city'] ?? '' ) );
		if ( $ident === $slug || ( $ident && in_array( $ident, $rec_segs, true ) ) || in_array( $slug, $psegs, true )
			|| array_intersect( $psegs, $rec_segs ) || ( $rec_slug && $pcity === $rec_slug ) || $pcity === $slug ) {
			$out[] = $p;
		}
	}
	return $memo[ $slug ] = $out;
}

/** true / false, or null when a feed is unavailable (then nothing is hidden). */
function ea_clean_town_has_programs( $slug ) {
	$list = ea_clean_town_programs( $slug );
	return null === $list ? null : (bool) $list;
}

/** Slugs listed under "Always keep in search" (pages that bring search visits even while empty). */
function ea_clean_keep_slugs() {
	$raw = (string) get_option( 'ea_clean_keep', '' );
	return array_values( array_filter( array_map( 'ea_clean_slug', preg_split( '/[\s,]+/', $raw ) ), 'strlen' ) );
}

/** Search description for a town page, built from the programs it lists ('' if not a town page / feed down). */
function ea_clean_town_description( $sport ) {
	if ( ! is_page_template( 'template-city-programs.php' ) ) {
		return '';
	}
	$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
	$list = ea_clean_town_programs( $slug );
	if ( null === $list ) {
		return '';
	}
	$counts = array();
	$mins   = array();
	$maxs   = array();
	foreach ( $list as $p ) {
		$c = trim( (string) ( $p['City'] ?? '' ) );
		if ( '' !== $c ) {
			$counts[ $c ] = ( $counts[ $c ] ?? 0 ) + 1;
		}
		if ( isset( $p['MinAge'] ) && is_numeric( $p['MinAge'] ) ) { $mins[] = (int) $p['MinAge']; }
		if ( isset( $p['MaxAge'] ) && is_numeric( $p['MaxAge'] ) ) { $maxs[] = (int) $p['MaxAge']; }
	}
	arsort( $counts );
	$city = $counts ? (string) array_key_first( $counts ) : trim( (string) get_the_title( get_queried_object_id() ) );
	$city = trim( preg_replace( '/\s+/', ' ', preg_replace( '/\b(ea|elevation|athletics|pickleball|basketball|badminton|programs?|leagues?|lessons?|training)\b/i', ' ', $city ) ) );
	if ( '' === $city ) {
		return '';
	}
	if ( ! $list ) {
		return sprintf( '%s in %s with Elevation Athletics. No sessions open right now; see nearby programs or join the newsletter for new dates.', $sport, $city );
	}
	$ages = $mins ? ' for ages ' . min( $mins ) . ( ( ! $maxs || max( $maxs ) >= 99 ) ? '+' : '–' . max( $maxs ) ) : '';
	return sprintf( '%s in %s: %d %s%s with Elevation Athletics. See days, times, locations and prices, and register online.',
		$sport, $city, count( $list ), 1 === count( $list ) ? 'program' : 'programs', $ages );
}

/** IDs of published pages to keep out of search: empty town pages + "coming soon" pages. Cached 30 min. */
function ea_clean_hidden_page_ids() {
	$cached = get_transient( 'ea_clean_hidden_ids' );
	if ( is_array( $cached ) && ( $cached['keep'] ?? null ) === ea_clean_keep_slugs() && isset( $cached['ids'] ) ) {
		return $cached['ids'];
	}
	$ids   = array();
	$pages = get_posts( array(
		'post_type'        => 'page',
		'post_status'      => 'publish',
		'posts_per_page'   => -1,
		'no_found_rows'    => true,
		'suppress_filters' => true,
		'meta_query'       => array( array( 'key' => '_wp_page_template', 'value' => array( 'template-city-programs.php', 'template-coming-soon.php' ), 'compare' => 'IN' ) ),
	) );
	$unknown = false;
	foreach ( $pages as $page ) {
		$tpl = (string) get_post_meta( $page->ID, '_wp_page_template', true );
		if ( 'template-coming-soon.php' === $tpl ) {
			$ids[] = (int) $page->ID;
			continue;
		}
		if ( in_array( ea_clean_slug( $page->post_name ), ea_clean_keep_slugs(), true ) ) {
			continue; // "Always keep in search"
		}
		$has = ea_clean_town_has_programs( $page->post_name );
		if ( null === $has ) {
			$unknown = true;
			break;
		}
		if ( false === $has ) {
			$ids[] = (int) $page->ID;
		}
	}
	if ( $unknown ) { // a feed is down: hide only the coming-soon pages, retry soon
		$ids = array_map( 'intval', wp_list_pluck( array_filter( $pages, function ( $pg ) {
			return 'template-coming-soon.php' === (string) get_post_meta( $pg->ID, '_wp_page_template', true );
		} ), 'ID' ) );
		set_transient( 'ea_clean_hidden_ids', array( 'keep' => ea_clean_keep_slugs(), 'ids' => $ids ), 5 * MINUTE_IN_SECONDS );
		return $ids;
	}
	set_transient( 'ea_clean_hidden_ids', array( 'keep' => ea_clean_keep_slugs(), 'ids' => $ids ), 30 * MINUTE_IN_SECONDS );
	return $ids;
}

/** Page IDs whose address the Redirection plugin redirects (exact, enabled rules). Cached 30 min. */
function ea_clean_redirected_page_ids() {
	$cached = get_transient( 'ea_clean_redirected_ids' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	global $wpdb;
	$ids   = array();
	$table = $wpdb->prefix . 'redirection_items';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$sources = $wpdb->get_col( "SELECT url FROM {$table} WHERE status = 'enabled' AND regex = 0" ); // phpcs:ignore WordPress.DB
		$paths   = array();
		foreach ( (array) $sources as $u ) {
			$p = '/' . trim( strtolower( (string) wp_parse_url( (string) $u, PHP_URL_PATH ) ), '/' ) . '/';
			$paths[ $p ] = true;
		}
		if ( $paths ) {
			foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) as $id ) {
				$p = '/' . trim( strtolower( (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH ) ), '/' ) . '/';
				if ( isset( $paths[ $p ] ) && '//' !== $p ) {
					$ids[] = (int) $id;
				}
			}
		}
	}
	set_transient( 'ea_clean_redirected_ids', $ids, 30 * MINUTE_IN_SECONDS );
	return $ids;
}

add_filter( 'wp_sitemaps_posts_query_args', function ( $args, $post_type ) {
	if ( 'page' !== $post_type || ! ea_clean_enabled() ) {
		return $args;
	}
	$skip = array_merge( ea_clean_hidden_page_ids(), ea_clean_redirected_page_ids() );
	if ( $skip ) {
		$args['post__not_in'] = array_values( array_unique( array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $skip ) ) );
	}
	return $args;
}, 20, 2 );

add_filter( 'wp_robots', function ( $robots ) {
	if ( ! ea_clean_enabled() || ! is_page() ) {
		return $robots;
	}
	$id = (int) get_queried_object_id();
	if ( $id && in_array( $id, ea_clean_hidden_page_ids(), true ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'] );
	}
	return $robots;
}, 30 );

// ─── Off switch: a row on Settings → EA Search ───────────────────────────────

add_action( 'admin_post_ea_clean_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'ea_clean_save' );
	update_option( 'ea_clean_enabled', empty( $_POST['ea_clean_enabled'] ) ? '0' : '1' );
	update_option( 'ea_clean_keep', sanitize_textarea_field( wp_unslash( $_POST['ea_clean_keep'] ?? '' ) ) );
	delete_transient( 'ea_clean_hidden_ids' );
	delete_transient( 'ea_clean_redirected_ids' );
	wp_safe_redirect( admin_url( 'options-general.php?page=ea-search&saved=1' ) );
	exit;
} );

function ea_clean_settings_section() {
	$on     = ea_clean_enabled();
	$hidden = $on ? ea_clean_hidden_page_ids() : array();
	$redir  = $on ? ea_clean_redirected_page_ids() : array();
	?>
	<hr />
	<h2>Hide empty pages from search</h2>
	<p>Town pages with no active programs and "coming soon" pages get a noindex tag and are left out of the sitemap; redirected pages are left out of the sitemap. Town pages come back by themselves when a program is listed. Visitors see the same pages.</p>
	<?php if ( $on ) : ?>
		<p>Right now: <?php echo (int) count( $hidden ); ?> page(s) hidden, <?php echo (int) count( $redir ); ?> redirected page(s) left out of the sitemap.</p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ea_clean_save" />
		<?php wp_nonce_field( 'ea_clean_save' ); ?>
		<label><input type="checkbox" name="ea_clean_enabled" value="1" <?php checked( $on ); ?> /> Hide empty pages from search</label>
		<p><label for="ea_clean_keep"><strong>Always keep in search</strong> (page slugs, one per line or comma-separated — e.g. town pages that still get search visits while empty):</label><br />
		<textarea id="ea_clean_keep" name="ea_clean_keep" rows="4" cols="60"><?php echo esc_textarea( (string) get_option( 'ea_clean_keep', '' ) ); ?></textarea></p>
		<?php submit_button( 'Save', 'secondary', 'submit', false ); ?>
	</form>
	<?php
	if ( function_exists( 'ea_citypages_settings_section' ) ) {
		ea_citypages_settings_section();
	}
}
