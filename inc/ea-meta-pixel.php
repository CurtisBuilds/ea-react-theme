<?php
/**
 * EA Meta Pixel — browser events for Facebook/Instagram ads.
 *
 * Same file on all three sites (eapickleball.com, eabadminton.com, elevationathletics.ca).
 *
 * Events (all sent with trackSingle so only OUR Pixel receives them):
 *   PageView            every front-end page
 *   ViewContent         town pages (feed "URL" = this page) and registration pages (?program=ID)
 *   RegisterClick       custom; fired by ea-attribution.php at the same moment as GA4 register_click
 *   CompleteRegistration once, on WPForms AJAX success for a form that carries a program ID
 *   JoinWaitlist        custom; same as above when the submission was a waitlist sign-up (no payment)
 *   Schedule            free-trial booking, fired by eaTrackTrial() (inc/ea-attribution.php) with an eventID
 *
 * No personal data is ever sent: only program ID, program/town name, sport, price, currency.
 * GA4, register_click, attribution and the WPForms hidden fields are not touched.
 *
 * Off switch: Settings → EA Meta Pixel (untick), or define( 'EA_PIXEL_DISABLED', true ).
 * Nothing is output until a Pixel ID is saved there.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function ea_pixel_id() {
    return preg_replace( '/\D/', '', (string) get_option( 'ea_pixel_id', '' ) );
}

function ea_pixel_enabled() {
    if ( defined( 'EA_PIXEL_DISABLED' ) && EA_PIXEL_DISABLED ) {
        return false;
    }
    return '1' === (string) get_option( 'ea_pixel_enabled', '1' ) && '' !== ea_pixel_id();
}

function ea_pixel_skip_request() {
    return is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_customize_preview()
        || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
}

function ea_pixel_sport_label( $code ) {
    if ( function_exists( 'ea_app_sport_label' ) ) {
        $label = ea_app_sport_label( (string) $code );
        if ( '' !== (string) $label ) {
            return $label;
        }
    }
    return (string) $code;
}

/** ViewContent facts for this page, or null. */
function ea_pixel_view_content() {
    if ( ! function_exists( 'ea_seo_programs' ) ) {
        return null;
    }
    // Registration page for one program: /register…/?program=EA-PROGRAM-123
    $pid = isset( $_GET['program'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['program'] ) ) ) : '';
    if ( '' !== $pid && preg_match( '/^EA-[A-Z0-9-]{3,40}$/', $pid ) ) {
        foreach ( ea_seo_programs() as $p ) {
            if ( ! is_array( $p ) ) {
                continue;
            }
            $ids = array( strtoupper( (string) ( $p['ProgramID'] ?? '' ) ), strtoupper( (string) ( $p['ListingCode'] ?? '' ) ) );
            if ( in_array( $pid, $ids, true ) ) {
                return array(
                    'content_name'     => (string) ( $p['City'] ?? '' ),
                    'content_category' => ea_pixel_sport_label( $p['sport'] ?? '' ),
                    'content_ids'      => array( $pid ),
                    'content_type'     => 'product',
                );
            }
        }
        return null;
    }
    // Town page: the feed lists this page as the programs' home.
    if ( ! function_exists( 'ea_seo_page_programs' ) ) {
        return null;
    }
    $programs = ea_seo_page_programs();
    if ( ! $programs ) {
        return null;
    }
    $first = $programs[0];
    return array(
        'content_name'     => (string) ( $first['City'] ?? '' ),
        'content_category' => ea_pixel_sport_label( $first['sport'] ?? '' ),
    );
}

add_action( 'wp_head', function () {
    $verify = trim( (string) get_option( 'ea_pixel_domain_verify', '' ) );
    if ( '' !== $verify && ! is_admin() ) {
        echo '<meta name="facebook-domain-verification" content="' . esc_attr( $verify ) . '" />' . "\n";
    }
    if ( ! ea_pixel_enabled() || ea_pixel_skip_request() ) {
        return;
    }
    try {
        $view = ea_pixel_view_content();
    } catch ( \Throwable $e ) {
        $view = null;
    }
    $cfg = array(
        'id'   => ea_pixel_id(),
        'view' => $view,
        'feed' => defined( 'EA_SEO_FEED_URL' ) ? EA_SEO_FEED_URL : '',
    );
    ?>
<!-- EA Meta Pixel -->
<script id="ea-pixel">
(function (w, d) {
  if (w.eaPixel) return;
  var CFG = <?php echo wp_json_encode( $cfg ); ?>;
  !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(w,d,'script','https://connect.facebook.net/en_US/fbevents.js');
  var ID = CFG.id;
  w.fbq('init', ID);
  function send(kind, name, params, opts) {
    try { if (opts && opts.eventID) w.fbq(kind, ID, name, params || {}, { eventID: String(opts.eventID) }); else w.fbq(kind, ID, name, params || {}); } catch (e) {}
  }
  w.eaPixel = {
    id: ID,
    track: function (name, params, opts) { send('trackSingle', name, params, opts); },
    custom: function (name, params, opts) { send('trackSingleCustom', name, params, opts); }
  };
  w.eaPixel.track('PageView');
  if (CFG.view) w.eaPixel.track('ViewContent', CFG.view);

  // ── CompleteRegistration / JoinWaitlist: WPForms AJAX success on a program form ──
  var PID = /^EA-(PROGRAM-\d+|[A-Z]{2}-[A-Z0-9]+(-[A-Z0-9]+)*)$/i;
  var TEST = /^EA-PROGRAM-457$/i;
  function num(s) { var m = String(s || '').replace(/,/g, '').match(/-?\d+(\.\d+)?/); return m ? parseFloat(m[0]) : NaN; }
  function snapshot(form) {
    var inputs = form.querySelectorAll('input');
    var pid = '', waitlist = false, total = NaN;
    for (var i = 0; i < inputs.length; i++) {
      var v = String(inputs[i].value || '').trim();
      if (!pid && PID.test(v)) pid = v.toUpperCase();
      if (inputs[i].type === 'hidden' && /^waitlist$/i.test(v)) waitlist = true;
      if (/wpforms-payment-(price|total)/.test(inputs[i].className) && v) total = num(v);
    }
    if (!pid) return null;
    var header = d.querySelector('.ea-program-header');
    var name = '', fee = NaN, rate = NaN;
    if (header) {
      var spans = header.querySelectorAll('span, div, p, h1, h2, h3');
      for (var j = 0; j < spans.length; j++) {
        var t = (spans[j].textContent || '').trim();
        if (/^program fee$/i.test(t) && spans[j].nextElementSibling) fee = num(spans[j].nextElementSibling.textContent);
        var r = t.match(/^[A-Z ]*\((\d+(\.\d+)?)%\)$/i); if (r && isNaN(rate)) rate = parseFloat(r[1]);
        if (!name && /^you are registering for$/i.test(t) && spans[j].nextElementSibling) name = (spans[j].nextElementSibling.textContent || '').trim();
      }
    }
    var value = !isNaN(fee) ? fee : (!isNaN(total) ? (!isNaN(rate) ? total / (1 + rate / 100) : total) : 0);
    return { pid: pid, name: name, waitlist: waitlist, value: Math.round(value * 100) / 100 };
  }
  function lookupName(pid, cb) {
    if (!CFG.feed || !w.fetch) return cb('');
    var done = false; function fin(n) { if (!done) { done = true; cb(n || ''); } }
    setTimeout(function () { fin(''); }, 1500);
    fetch(CFG.feed, { cache: 'force-cache' }).then(function (r) { return r.json(); }).then(function (list) {
      for (var i = 0; i < (list || []).length; i++) {
        var p = list[i] || {};
        if (String(p.ProgramID || '').toUpperCase() === pid || String(p.ListingCode || '').toUpperCase() === pid) return fin(p.Title);
      }
      fin('');
    }).catch(function () { fin(''); });
  }
  var snaps = {}, fired = {};
  function formKey(form) { return form.getAttribute('data-formid') || form.id || 'form'; }
  d.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.classList && f.classList.contains('wpforms-form')) { try { snaps[formKey(f)] = snapshot(f); } catch (x) {} }
  }, true);
  function onSuccess(form) {
    var key = formKey(form);
    if (fired[key]) return;
    var s = null; try { s = snapshot(form); } catch (x) {}
    s = s || snaps[key];
    if (!s) return;
    fired[key] = true;
    lookupName(s.pid, function (title) {
      var params = { content_ids: [s.pid], content_name: title || s.name || s.pid, content_type: 'product' };
      if (TEST.test(s.pid)) params.test_event = true;
      if (s.waitlist) { w.eaPixel.custom('JoinWaitlist', params); return; }
      params.value = s.value; params.currency = 'CAD';
      w.eaPixel.track('CompleteRegistration', params);
    });
  }
  function bindJq() {
    var $ = w.jQuery;
    if (!$ || bindJq.done) return;
    bindJq.done = true;
    $(d).on('wpformsAjaxSubmitSuccess', 'form.wpforms-form', function (e, json) {
      if (json && json.success === false) return;
      onSuccess(this);
    });
  }
  bindJq();
  d.addEventListener('DOMContentLoaded', bindJq);
  w.addEventListener('load', bindJq);
})(window, document);
</script>
<!-- /EA Meta Pixel -->
    <?php
}, 3 );

// ─── Settings → EA Meta Pixel ────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_options_page( 'EA Meta Pixel', 'EA Meta Pixel', 'manage_options', 'ea-meta-pixel', 'ea_pixel_settings_page' );
} );

add_action( 'admin_post_ea_pixel_save', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Not allowed' );
    }
    check_admin_referer( 'ea_pixel_save' );
    update_option( 'ea_pixel_enabled', empty( $_POST['ea_pixel_enabled'] ) ? '0' : '1' );
    update_option( 'ea_pixel_id', preg_replace( '/\D/', '', (string) wp_unslash( $_POST['ea_pixel_id'] ?? '' ) ) );
    update_option( 'ea_pixel_domain_verify', preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['ea_pixel_domain_verify'] ?? '' ) ) );
    wp_safe_redirect( admin_url( 'options-general.php?page=ea-meta-pixel&saved=1' ) );
    exit;
} );

function ea_pixel_settings_page() {
    ?>
    <div class="wrap">
      <h1>EA Meta Pixel</h1>
      <?php if ( ! empty( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Saved. Purge the SiteGround cache so visitors get the change.</p></div><?php endif; ?>
      <p>Sends PageView, ViewContent, RegisterClick, CompleteRegistration and JoinWaitlist to Meta. No names, emails or phone numbers are ever sent.</p>
      <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="ea_pixel_save" />
        <?php wp_nonce_field( 'ea_pixel_save' ); ?>
        <table class="form-table">
          <tr><th>Enabled</th><td><label><input type="checkbox" name="ea_pixel_enabled" value="1" <?php checked( '1', (string) get_option( 'ea_pixel_enabled', '1' ) ); ?> /> Load the Meta Pixel on this site</label></td></tr>
          <tr><th>Pixel ID</th><td><input type="text" class="regular-text" name="ea_pixel_id" value="<?php echo esc_attr( ea_pixel_id() ); ?>" placeholder="15–16 digits" /></td></tr>
          <tr><th>Domain verification code</th><td><input type="text" class="regular-text" name="ea_pixel_domain_verify" value="<?php echo esc_attr( (string) get_option( 'ea_pixel_domain_verify', '' ) ); ?>" /><p class="description">Only the code from Meta's meta-tag (the part inside content="…").</p></td></tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
}
