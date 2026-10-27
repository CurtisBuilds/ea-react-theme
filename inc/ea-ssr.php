<?php
/**
 * EA server-rendered page text (pilot: city program pages).
 *
 * WHY: the theme draws every page in the browser with React, so the HTML that
 * search engines and AI crawlers first receive is an empty shell. This prints the
 * same facts React will show (city heading, a one-line summary, and the active
 * program list) straight into the page HTML.
 *
 * HOW IT STAYS SAFE
 *   - Visitors still get the React page: React replaces this markup when it
 *     mounts (src/main.jsx keeps the program list on screen until the live feed
 *     arrives, so there is no "Loading programs…" flash).
 *   - Same source as React: the public program feed, read through the cached
 *     ea_seo_programs() (inc/ea-seo.php). Same filters as CityProgramsPage.jsx.
 *     Visitors and crawlers see the same text; nothing hidden.
 *   - Only on pages using the "EA City Programs" template. Registration,
 *     membership and payment pages are never touched.
 *   - Fails quietly: any error or an empty feed prints nothing extra, and the
 *     page behaves exactly as before.
 *   - Off switch: Settings → EA Search → untick "Server-rendered page text"
 *     (instant, no reinstall), or define EA_SSR_DISABLED in wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ea_ssr_enabled() {
	if ( defined( 'EA_SSR_DISABLED' ) && EA_SSR_DISABLED ) {
		return false;
	}
	if ( ! function_exists( 'ea_seo_programs' ) ) {
		return false;
	}
	return '1' === (string) get_option( 'ea_ssr_enabled', '1' );
}

function ea_ssr_slugify( $value ) {
	$v = strtolower( trim( (string) $value ) );
	$v = preg_replace( '/[^a-z0-9]+/', '-', $v );
	return trim( (string) $v, '-' );
}

function ea_ssr_url_segments( $url ) {
	$raw = trim( (string) $url );
	if ( '' === $raw ) {
		return array();
	}
	if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) && 0 !== strpos( $raw, '/' ) ) {
		$raw = 'https://' . $raw;
	}
	$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
	return array_values( array_filter( array_map( 'ea_ssr_slugify', explode( '/', $path ) ) ) );
}

/** Sport keys this site shows (mirrors selectedSports() in CityProgramsPage.jsx). */
function ea_ssr_sport_aliases() {
	return array( 'bad', 'badm', 'badmin', 'badminton' );
}

function ea_ssr_sport_name() {
	return 'Badminton';
}

function ea_ssr_has_price( $p ) {
	$raw = $p['updated_price'] ?? ( $p['StaticPriceText'] ?? ( $p['TotalPrice'] ?? null ) );
	if ( null === $raw ) {
		return false;
	}
	$t = trim( (string) $raw );
	if ( '' === $t || preg_match( '/^tbd$/i', $t ) ) {
		return false;
	}
	$n = preg_replace( '/[$,\s]/', '', $t );
	return '' !== $n && is_numeric( $n );
}

function ea_ssr_is_active( $p, $today ) {
	$sessions = array_filter( array_map( 'trim', explode( ',', (string) ( $p['SessionDates'] ?? '' ) ) ) );
	$sessions = array_filter( $sessions, function ( $d ) {
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
	$end = (string) ( $p['End Date'] ?? '' );
	return ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) || $end >= $today;
}

/** Programs React shows on this city page, in start-date order. */
function ea_ssr_city_programs( $slug ) {
	$slug  = ea_ssr_slugify( $slug );
	$today = wp_date( 'Y-m-d' );
	$out   = array();
	if ( '' === $slug ) {
		return $out;
	}
	foreach ( (array) ea_seo_programs() as $p ) {
		if ( ! is_array( $p ) || ! empty( $p['is_cancelled'] ) || empty( $p['City'] ) ) {
			continue;
		}
		$sport = strtolower( trim( (string) ( $p['sport'] ?? ( $p['Sport'] ?? ( $p['SPORT'] ?? '' ) ) ) ) );
		$cat   = strtoupper( trim( (string) ( $p['Category'] ?? '' ) ) );
		if ( ! in_array( $sport, ea_ssr_sport_aliases(), true ) || ! in_array( $cat, array( 'EA', 'TS' ), true ) ) {
			continue;
		}
		$link = trim( (string) ( $p['RegisterLink'] ?? ( $p['URL'] ?? '' ) ) );
		if ( '' === $link || preg_match( '/^tbd$/i', $link ) || ! ea_ssr_has_price( $p ) ) {
			continue; // "coming soon" rows are not listed on city pages
		}
		if ( ! ea_ssr_is_active( $p, $today ) ) {
			continue;
		}
		$ident   = ea_ssr_slugify( $p['pageIdentifyer'] ?? ( $p['pageIdentifier'] ?? ( $p['PageIdentifier'] ?? ( $p['Page Identifier'] ?? '' ) ) ) );
		$matches = ( $ident === $slug )
			|| in_array( $slug, ea_ssr_url_segments( $p['URL'] ?? ( $p['url'] ?? '' ) ), true )
			|| ea_ssr_slugify( $p['City'] ) === $slug;
		if ( $matches ) {
			$out[] = $p;
		}
	}
	usort( $out, function ( $a, $b ) {
		return strcmp( (string) ( $a['Start Date'] ?? '9999' ), (string) ( $b['Start Date'] ?? '9999' ) );
	} );
	return $out;
}

function ea_ssr_clean_city( $value ) {
	$v = preg_replace( '/\b(ea|elevation|athletics|badminton|pickleball|basketball|league|leagues|lesson|lessons|program|programs|training)\b/i', ' ', (string) $value );
	return trim( preg_replace( '/\s+/', ' ', (string) $v ) );
}

function ea_ssr_city_name( $programs, $slug ) {
	$counts = array();
	foreach ( $programs as $p ) {
		$c = trim( (string) $p['City'] );
		if ( '' !== $c ) {
			$counts[ $c ] = ( $counts[ $c ] ?? 0 ) + 1;
		}
	}
	arsort( $counts );
	$name = $counts ? (string) array_key_first( $counts ) : ucwords( str_replace( '-', ' ', (string) $slug ) );
	return ea_ssr_clean_city( $name );
}

function ea_ssr_price( $p ) {
	$raw = $p['updated_price'] ?? ( $p['StaticPriceText'] ?? ( $p['TotalPrice'] ?? '' ) );
	$n   = preg_replace( '/[$,\s]/', '', (string) $raw );
	if ( ! is_numeric( $n ) ) {
		return '';
	}
	$n = (float) $n;
	return '$' . ( floor( $n ) == $n ? number_format( $n, 0 ) : number_format( $n, 2 ) );
}

function ea_ssr_date( $ymd ) {
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ymd ) ) {
		return '';
	}
	$ts = strtotime( $ymd . ' 12:00:00' );
	return $ts ? gmdate( 'M j', $ts ) : '';
}

function ea_ssr_ages( $p ) {
	$min = trim( (string) ( $p['MinAge'] ?? '' ) );
	$max = trim( (string) ( $p['MaxAge'] ?? '' ) );
	if ( '' === $min || ! is_numeric( $min ) ) {
		return '';
	}
	if ( '' === $max || ! is_numeric( $max ) || (int) $max >= 99 ) {
		return 'Ages ' . (int) $min . '+';
	}
	return 'Ages ' . (int) $min . '–' . (int) $max;
}

/** HTML for a city page; '' if switched off or anything goes wrong. */
function ea_ssr_city_markup( $slug ) {
	if ( ! ea_ssr_enabled() ) {
		return '';
	}
	try {
		$programs = ea_ssr_city_programs( $slug );
		$city     = ea_ssr_city_name( $programs, $slug );
		$sport    = ea_ssr_sport_name();
		if ( '' === $city ) {
			return '';
		}

		$venues = array();
		$mins   = array();
		$maxes  = array();
		foreach ( $programs as $p ) {
			$v = trim( (string) ( $p['LocationName'] ?? '' ) );
			if ( '' !== $v ) {
				$venues[ $v ] = true;
			}
			if ( isset( $p['MinAge'] ) && is_numeric( $p['MinAge'] ) ) {
				$mins[] = (int) $p['MinAge'];
			}
			if ( isset( $p['MaxAge'] ) && is_numeric( $p['MaxAge'] ) ) {
				$maxes[] = (int) $p['MaxAge'];
			}
		}

		ob_start();
		echo '<div class="ea-ssr" data-ea-ssr>';
		echo '<h1>' . esc_html( 'EA ' . $sport . ' ' . $city ) . '</h1>';

		if ( $programs ) {
			$parts = array( sprintf( 'Elevation Athletics runs %d %s %s in %s', count( $programs ), strtolower( $sport ), 1 === count( $programs ) ? 'program' : 'programs', $city ) );
			if ( $mins ) {
				$hi      = $maxes ? max( $maxes ) : 0;
				$parts[] = 'for ages ' . min( $mins ) . ( $hi >= 99 || ! $hi ? '+' : '–' . $hi );
			}
			$intro = implode( ' ', $parts );
			if ( $venues ) {
				$intro .= ' at ' . implode( ', ', array_slice( array_keys( $venues ), 0, 3 ) );
			}
			echo '<p>' . esc_html( $intro . '. See days, times, locations and prices below, and register online.' ) . '</p>';

			echo '<div data-ea-ssr-list>';
			foreach ( array_slice( $programs, 0, 40 ) as $p ) {
				$start = ea_ssr_date( $p['Start Date'] ?? '' );
				$end   = ea_ssr_date( $p['End Date'] ?? '' );
				$facts = array_filter( array(
					trim( (string) ( $p['Day'] ?? '' ) ),
					trim( (string) ( $p['Time'] ?? '' ) ),
					$start && $end ? $start . ' – ' . $end : $start,
					ea_ssr_ages( $p ),
					trim( implode( ', ', array_filter( array( trim( (string) ( $p['LocationName'] ?? '' ) ), trim( (string) $p['City'] ) ) ) ) ),
					ea_ssr_price( $p ),
					! empty( $p['is_full'] ) && 'false' !== strtolower( (string) $p['is_full'] ) ? 'Full' : '',
				) );
				$link = (string) ( $p['RegisterLink'] ?? ( $p['URL'] ?? '' ) );
				echo '<article class="ea-ssr-program">';
				echo '<h2>' . esc_html( (string) ( $p['Title'] ?? '' ) ) . '</h2>';
				echo '<p>' . esc_html( implode( ' · ', $facts ) ) . '</p>';
				if ( '' !== $link ) {
					echo '<p><a href="' . esc_url( $link ) . '">Register</a></p>';
				}
				echo '</article>';
			}
			echo '</div>';
		} else {
			echo '<p>' . esc_html( sprintf( 'No active %s programs in %s right now. Check nearby cities, or come back soon for new programs.', strtolower( $sport ), $city ) ) . '</p>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	} catch ( \Throwable $e ) {
		if ( ob_get_level() ) {
			ob_end_clean();
		}
		return '';
	}
}

/** Minimal styling so the text reads cleanly before React mounts (no layout tricks). */
add_action( 'wp_head', function () {
	if ( ! ea_ssr_enabled() || ! is_page_template( 'template-city-programs.php' ) ) {
		return;
	}
	echo '<style id="ea-ssr-css">.ea-ssr{max-width:1184px;margin:0 auto;padding:48px 32px 96px;font-family:var(--font-body,system-ui,sans-serif);color:var(--ea-ink,#1E526E)}.ea-ssr h1{margin:0 0 12px;font-size:40px;line-height:1.1}.ea-ssr>p{font-size:18px;line-height:1.4;margin:0 0 24px}.ea-ssr-program{background:#fff;border:1px solid #d9e7ee;border-radius:14px;padding:16px 20px;margin:0 0 12px}.ea-ssr-program h2{margin:0 0 6px;font-size:20px}.ea-ssr-program p{margin:0 0 6px}@media(max-width:767px){.ea-ssr{padding:30px 16px 72px}.ea-ssr h1{font-size:30px}}</style>' . "\n";
}, 20 );

// ─── Off switch: add a row to Settings → EA Search ───────────────────────────

add_action( 'admin_post_ea_ssr_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'ea_ssr_save' );
	update_option( 'ea_ssr_enabled', empty( $_POST['ea_ssr_enabled'] ) ? '0' : '1' );
	wp_safe_redirect( admin_url( 'options-general.php?page=ea-search&saved=1' ) );
	exit;
} );

function ea_ssr_settings_section() {
	$on = '1' === (string) get_option( 'ea_ssr_enabled', '1' );
	?>
	<hr />
	<h2>Server-rendered page text (pilot)</h2>
	<p>Prints each city page's heading, summary and program list into the page HTML so search engines and AI tools can read it. Visitors see the same page as before.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ea_ssr_save" />
		<?php wp_nonce_field( 'ea_ssr_save' ); ?>
		<label><input type="checkbox" name="ea_ssr_enabled" value="1" <?php checked( $on ); ?> /> On (untick to turn off instantly, then purge the SiteGround cache)</label>
		<?php submit_button( 'Save' ); ?>
	</form>
	<?php
}
