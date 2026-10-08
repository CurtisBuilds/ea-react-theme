<?php
/**
 * EA attribution — where a visitor came from, carried through to every signup
 * and registration the EA app receives, plus the GA4 `register_click` event.
 *
 * Browser side (inline script in <head>, every front-end page):
 *   - Cookie `ea_attr` (first-party, 90 days, renewed on every visit):
 *       first: first touch, set once and never overwritten
 *       last:  overwritten whenever the visitor arrives with UTMs / gclid /
 *              fbclid or from an external referrer
 *       rt:    recipient token from campaign emails (?rt=)
 *     Each snapshot: utm_source, utm_medium, utm_campaign, utm_content,
 *     utm_term, gclid, fbclid, referrer_host, landing_host, landing_page, ts.
 *   - The three EA sites are one journey: a link from one EA domain to another
 *     carries the cookie in `ea_x` (removed from the address bar on arrival),
 *     and EA domains never count as an external referrer.
 *   - GA4 event `register_click` on every Register / Join Waitlist link:
 *     program_id, city, sport, cta (register|waitlist), page_path. No personal data.
 *
 * Server side:
 *   - Free trial + newsletter: the cookie is read when the visitor submits and
 *     saved on the entry (_ea_attribution); the website-signup payload carries it
 *     as `attribution` (null when there was no cookie).
 *   - WPForms registrations: saved on the entry at submit, then added to the
 *     wpforms-webhook body as `attribution` when the Webhooks addon sends it
 *     (it sends from a background task, where the visitor's cookie is gone).
 *
 * Never blocks a submission: any problem here just means attribution = null.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EA_ATTR_FEED_URL' ) ) {
    define( 'EA_ATTR_FEED_URL', 'https://curtisbuilds.github.io/ea-programs-json/data/programs.json' );
}

// ─── Reading the cookie (server side) ────────────────────────────────────────

function ea_attr_clip( $value, $max = 200 ) {
    if ( ! is_scalar( $value ) ) {
        return '';
    }
    $value = sanitize_text_field( (string) $value );
    return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
}

function ea_attr_clean_snapshot( $snap ) {
    if ( ! is_array( $snap ) ) {
        return null;
    }
    $keys = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid', 'referrer_host', 'landing_host', 'landing_page', 'ts' );
    $out  = array();
    foreach ( $keys as $key ) {
        $out[ $key ] = isset( $snap[ $key ] ) ? ea_attr_clip( $snap[ $key ] ) : '';
    }
    return $out;
}

/** The GA client ID ("123.456") from the _ga cookie, or ''. */
function ea_attr_ga_client_id() {
    $raw = isset( $_COOKIE['_ga'] ) ? (string) wp_unslash( $_COOKIE['_ga'] ) : '';
    return preg_match( '/^GA\d\.\d+\.(\d+\.\d+)$/', $raw, $m ) ? $m[1] : '';
}

/** Attribution for the current request, or null when the visitor has no cookie. */
function ea_attr_from_request() {
    if ( empty( $_COOKIE['ea_attr'] ) ) {
        return null;
    }
    $raw     = strtr( (string) wp_unslash( $_COOKIE['ea_attr'] ), '-_', '+/' );
    $decoded = base64_decode( $raw . str_repeat( '=', ( 4 - strlen( $raw ) % 4 ) % 4 ), true );
    $data    = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
    if ( ! is_array( $data ) ) {
        return null;
    }
    $first = ea_attr_clean_snapshot( $data['first'] ?? null );
    $last  = ea_attr_clean_snapshot( $data['last'] ?? null );
    if ( null === $first && null === $last ) {
        return null;
    }
    $client = ea_attr_ga_client_id();
    $rt     = ea_attr_clip( $data['rt'] ?? '' );
    return array(
        'first'           => $first,
        'last'            => $last,
        'ga_client_id'    => '' !== $client ? $client : null,
        'recipient_token' => '' !== $rt ? $rt : null,
    );
}

// ─── Free trial + newsletter entries ─────────────────────────────────────────

/**
 * Save the visitor's attribution on a signup entry. Only from the visitor's own
 * submission (the form handlers pass page_url); never from cron retries or the
 * admin "Send unsynced" button, which would record the admin's cookie.
 */
function ea_attr_capture_for_post( $post_id, $extra ) {
    if ( ! is_array( $extra ) || ! array_key_exists( 'page_url', $extra ) || wp_doing_cron() ) {
        return;
    }
    $attr = ea_attr_from_request();
    if ( null !== $attr ) {
        update_post_meta( (int) $post_id, '_ea_attribution', wp_slash( wp_json_encode( $attr ) ) );
    }
}

function ea_attr_for_post( $post_id ) {
    $saved = get_post_meta( (int) $post_id, '_ea_attribution', true );
    $data  = is_string( $saved ) && '' !== $saved ? json_decode( $saved, true ) : null;
    return is_array( $data ) ? $data : null;
}

// ─── WPForms registrations → wpforms-webhook ─────────────────────────────────

add_action( 'wpforms_process_complete', function ( $fields, $entry, $form_data, $entry_id ) {
    $entry_id = (int) $entry_id;
    if ( $entry_id <= 0 ) {
        return;
    }
    $attr = ea_attr_from_request();
    if ( null === $attr ) {
        return;
    }
    // Runs before the Webhooks addon queues its send (priority 10).
    set_transient( 'ea_attr_wpf_' . $entry_id, wp_json_encode( $attr ), 30 * DAY_IN_SECONDS );
}, 5, 4 );

add_filter( 'wpforms_webhooks_process_delivery_request_options', function ( $options, $webhook_data = array(), $fields = array(), $form_data = array(), $entry_id = 0 ) {
    $url = is_array( $webhook_data ) ? (string) ( $webhook_data['url'] ?? '' ) : '';
    if ( false === strpos( $url, '/functions/v1/wpforms-webhook' ) || ! isset( $options['body'] ) || ! is_string( $options['body'] ) ) {
        return $options;
    }
    $body = json_decode( $options['body'], true );
    if ( ! is_array( $body ) ) {
        return $options;
    }
    $saved               = (int) $entry_id > 0 ? get_transient( 'ea_attr_wpf_' . (int) $entry_id ) : false;
    $attr                = is_string( $saved ) ? json_decode( $saved, true ) : null;
    $body['attribution'] = is_array( $attr ) ? $attr : null;
    $encoded             = wp_json_encode( $body );
    if ( is_string( $encoded ) ) {
        $options['body'] = $encoded;
    }
    return $options;
}, 10, 5 );

// ─── Browser script ──────────────────────────────────────────────────────────

function ea_attr_page_sport() {
    if ( ! function_exists( 'ea_app_signup_sport' ) ) {
        return '';
    }
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
    return ea_app_signup_sport( '', '', home_url( $uri ) );
}

add_action( 'wp_head', function () {
    if ( is_admin() || is_customize_preview() ) {
        return;
    }
    $config = array(
        'sport' => ea_attr_page_sport(),
        'feed'  => EA_ATTR_FEED_URL,
    );
    ?>
<script id="ea-attr">
(function (w, d) {
  if (w.eaAttr) return;
  var CFG = <?php echo wp_json_encode( $config ); ?>;
  var EA = /(^|\.)(eapickleball\.com|eabadminton\.com|elevationathletics\.ca)$/i;
  var NOT_SOURCE = /(^|\.)(stripe\.com|paypal\.com|wpforms\.com)$/i;
  var UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
  var DAYS = 90;

  function enc(s) { return btoa(unescape(encodeURIComponent(s))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function dec(s) { s = String(s).replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; return decodeURIComponent(escape(atob(s))); }
  function clip(v) { return v == null ? '' : String(v).slice(0, 200); }
  function read() {
    var m = d.cookie.match(/(?:^|;\s*)ea_attr=([^;]+)/);
    if (!m) return null;
    try { var o = JSON.parse(dec(m[1])); return o && typeof o === 'object' ? o : null; } catch (e) { return null; }
  }
  function write(o) {
    try {
      d.cookie = 'ea_attr=' + enc(JSON.stringify(o)) + '; expires=' + new Date(Date.now() + DAYS * 864e5).toUTCString() +
        '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    } catch (e) {}
  }

  var url, q;
  try { url = new URL(location.href); q = url.searchParams; } catch (e) { return; }

  var ref = '';
  try {
    if (d.referrer) {
      var h = new URL(d.referrer).hostname.replace(/^www\./, '').toLowerCase();
      if (h && !EA.test(h) && !NOT_SOURCE.test(h) && h !== location.hostname.replace(/^www\./, '')) ref = h;
    }
  } catch (e) {}

  var touch = !!(ref || q.get('gclid') || q.get('fbclid') || UTM.some(function (k) { return q.get(k); }));
  function snapshot() {
    var s = {};
    UTM.forEach(function (k) { s[k] = clip(q.get(k)); });
    s.gclid = clip(q.get('gclid'));
    s.fbclid = clip(q.get('fbclid'));
    s.referrer_host = ref;
    s.landing_host = location.hostname.replace(/^www\./, '');
    s.landing_page = clip(location.pathname);
    s.ts = new Date().toISOString();
    return s;
  }

  var cur = read() || {};

  // Arriving from another EA site: merge what that site knew.
  var carried = q.get('ea_x');
  if (carried) {
    try {
      var inc = JSON.parse(dec(carried));
      if (inc && inc.first && (!cur.first || String(inc.first.ts || '') < String(cur.first.ts || ''))) cur.first = inc.first;
      if (inc && inc.last && (!cur.last || String(inc.last.ts || '') > String(cur.last.ts || ''))) cur.last = inc.last;
      if (inc && inc.rt && (!cur.rt || String(inc.rt_ts || '') > String(cur.rt_ts || ''))) { cur.rt = clip(inc.rt); cur.rt_ts = clip(inc.rt_ts); }
    } catch (e) {}
    try { q.delete('ea_x'); history.replaceState(history.state, '', url.pathname + (q.toString() ? '?' + q.toString() : '') + url.hash); } catch (e) {}
  }

  if (!cur.first) cur.first = snapshot();
  if (touch || !cur.last) cur.last = touch ? snapshot() : cur.first;
  if (q.get('rt')) { cur.rt = clip(q.get('rt')); cur.rt_ts = new Date().toISOString(); }
  cur.v = 1;
  write(cur);
  w.eaAttr = { read: read };

  // ── Links to another EA site carry the cookie ──
  function decorate(a) {
    try {
      var u = new URL(a.href, location.href);
      var host = u.hostname.replace(/^www\./, '').toLowerCase();
      if (!/^https?:$/.test(u.protocol) || !EA.test(host) || host === location.hostname.replace(/^www\./, '').toLowerCase()) return;
      var c = read();
      if (!c) return;
      u.searchParams.set('ea_x', enc(JSON.stringify({ first: c.first, last: c.last, rt: c.rt || '', rt_ts: c.rt_ts || '' })));
      a.href = u.toString();
    } catch (e) {}
  }

  // ── register_click ──
  var feed = null, feedLoading = null;
  function loadFeed() {
    if (feed || feedLoading || !w.fetch) return feedLoading;
    feedLoading = fetch(CFG.feed, { cache: 'force-cache' }).then(function (r) { return r.json(); }).then(function (list) {
      var map = {};
      (Array.isArray(list) ? list : []).forEach(function (p) { if (p && p.ProgramID) map[String(p.ProgramID).toUpperCase()] = { city: p.City || '', sport: p.sport || '' }; });
      feed = map;
    }).catch(function () { feed = {}; });
    return feedLoading;
  }
  function registerInfo(a) {
    var href = a.getAttribute('href') || '';
    if (!href || /^(mailto|tel|javascript):/i.test(href)) return null;
    var u;
    try { u = new URL(a.href, location.href); } catch (e) { return null; }
    var pid = (u.search.match(/EA-PROGRAM-\d+/i) || [''])[0].toUpperCase();
    var isReg = /\/register(-[a-z-]+)?\/?$/i.test(u.pathname) || !!pid || /^\s*register\b/i.test(a.textContent || '');
    return isReg ? { program_id: pid, cta: /waitlist/i.test(a.textContent || '') ? 'waitlist' : 'register' } : null;
  }
  function sendRegister(info) {
    var p = feed && info.program_id ? feed[info.program_id] : null;
    var params = {
      program_id: info.program_id || '',
      city: p ? p.city : '',
      sport: (p && p.sport) || CFG.sport || '',
      cta: info.cta,
      page_path: location.pathname,
      transport_type: 'beacon'
    };
    if (typeof w.gtag === 'function') w.gtag('event', 'register_click', params);
    else (w.dataLayer = w.dataLayer || []).push(['event', 'register_click', params]);
  }
  function linkFrom(e) { var t = e.target; return t && t.closest ? t.closest('a[href]') : null; }

  d.addEventListener('pointerover', function (e) { var a = linkFrom(e); if (a && registerInfo(a)) loadFeed(); }, { passive: true });
  d.addEventListener('focusin', function (e) { var a = linkFrom(e); if (a && registerInfo(a)) loadFeed(); });
  d.addEventListener('touchstart', function (e) { var a = linkFrom(e); if (a && registerInfo(a)) loadFeed(); }, { passive: true });

  function onActivate(e) {
    var a = linkFrom(e);
    if (!a) return;
    decorate(a);
    if (e.type !== 'click' || a.__eaReg) return;
    var info = registerInfo(a);
    if (!info || /\/register(-[a-z-]+)?\/?$/i.test(location.pathname)) return;
    a.__eaReg = true; setTimeout(function () { a.__eaReg = false; }, 1000);
    var newTab = a.target === '_blank' || e.metaKey || e.ctrlKey || e.shiftKey;
    if (feed || !info.program_id || !newTab) { sendRegister(info); return; }
    // Page stays open (new tab): wait briefly for the city/sport lookup.
    var sent = false;
    function go() { if (!sent) { sent = true; sendRegister(info); } }
    setTimeout(go, 1200);
    (loadFeed() || Promise.resolve()).then(go, go);
  }
  d.addEventListener('mousedown', onActivate, true);
  d.addEventListener('auxclick', onActivate, true);
  d.addEventListener('click', onActivate, true);
  d.addEventListener('contextmenu', function (e) { var a = linkFrom(e); if (a) decorate(a); }, true);
})(window, document);
</script>
    <?php
}, 2 );
