/**
 * src/lib/shared.jsx — shared building blocks used by every page.
 *
 * Exports:
 *   useDSComponents() · useViewport()   — runtime hooks
 *   getThemeData()                      — reads window.eaReactData (themeUrl, images, menus)
 *   FB · Placeholder · MediaSlot        — UI primitives
 *   Layout                              — page shell (nav + children + footer)
 *
 * Images come from the WordPress Customizer (Appearance → Customize → EA Images),
 * injected via wp_localize_script as window.eaReactData.images. When an image
 * isn't set in the admin, components fall back to the bundled file in assets/images/.
 */
import { useState, useEffect } from 'react';

// Below this viewport width the nav collapses to the hamburger menu. It's wider
// than the page's 768px mobile breakpoint because the full nav (logo + links +
// CTA) needs more room than the page content does.
const NAV_COLLAPSE_WIDTH = 1024;

// Fixed height (px) of the nav bar, per state. The header is locked to this height
// AND the page content is offset by the same value, so the gap below the fixed nav
// is always exactly the first section's own top padding — consistent on every
// screen. `collapsed` = the hamburger (narrow) layout.
const NAV_HEIGHT = (collapsed) => (collapsed ? 72 : 92);

// ─── EA Design System hook ────────────────────────────────────────────────────
export function useDSComponents() {
  const [ds, setDs] = useState(
    () => window.ElevationAthleticsDesignSystem_58666d || null
  );
  useEffect(() => {
    if (ds) return;
    const t = setInterval(() => {
      const found = window.ElevationAthleticsDesignSystem_58666d;
      if (found) { setDs(found); clearInterval(t); }
    }, 80);
    return () => clearInterval(t);
  }, [ds]);
  return ds || {};
}

// ─── Responsive hook ──────────────────────────────────────────────────────────
export function useViewport() {
  const [w, setW] = useState(
    typeof window !== 'undefined' ? window.innerWidth : 1200
  );
  useEffect(() => {
    const handle = () => setW(window.innerWidth);
    window.addEventListener('resize', handle, { passive: true });
    return () => window.removeEventListener('resize', handle);
  }, []);
  return { isMobile: w < 768, isTablet: w < 1024, width: w };
}

// ─── WP-localized data (themeUrl, admin images, menus) ────────────────────────
export function getThemeData() {
  const d = (typeof window !== 'undefined' && window.eaReactData) || {};
  const themeUrl = d.themeUrl || '';
  return {
    themeUrl,
    siteUrl: d.siteUrl || '',
    apiUrl:  d.apiUrl  || '/wp-json/',        // REST base, e.g. http://site.local/wp-json/
    nonce:   d.nonce   || '',                 // X-WP-Nonce for authenticated REST calls
    images:  d.images  || {},                // { hero, heroMobile, spotlight1, ... } from the Customizer
    carouselLinks: d.carouselLinks || {},     // { carousel1, carousel2, carousel3 } optional slide links
    texts:   d.texts   || {},                // { heroDesc, programsDesc, ... } editable copy from the Customizer
    options: d.options || {},                // { useCarousel, ... } layout toggles from the Customizer
    defaults: d.defaults || {},              // { sport, region, city } site-level defaults from wp-config.php
    social:  d.social  || {},                // { instagram, facebook } profile links from the Customizer
    links:   d.links   || {},                // { heroPrimary: {url, section}, ... } button destinations
    // FAQ page Q&A rows from the Customizer (EA FAQ). Array of { q, a, open };
    // `a` may contain basic HTML (links etc.). undefined only in dev (no WP data),
    // where the FAQ page falls back to its built-in defaults.
    faqs:    Array.isArray(d.faqs) ? d.faqs : undefined,
    menus:   d.menus   || {},
    asset:   (file) => `${themeUrl}/assets/images/${file}`,   // bundled fallback path
    logoBase: themeUrl ? `${themeUrl}/assets/images/` : '../assets/images/',
  };
}

// ─── Minimal inline fallbacks (used when the DS bundle hasn't loaded yet) ─────
export const FB = {
  btn: (variant) => ({
    display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
    padding: '14px 28px', borderRadius: 8,
    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
    fontSize: 16, fontWeight: 600, cursor: 'pointer',
    transition: 'opacity .15s', border: 'none',
    background: variant === 'secondary' ? '#fff' : '#0092DB',
    color:      variant === 'secondary' ? '#0092DB' : '#fff',
    outline:    variant === 'secondary' ? '2px solid #0092DB' : 'none',
  }),
  h: (size) => ({
    fontFamily: 'var(--font-display, "BBH Bogle", "Arial Narrow", sans-serif)',
    textTransform: 'uppercase', fontSize: size,
    lineHeight: 1.0, letterSpacing: '0.02em',
    color: 'var(--ea-navy, #10414F)', margin: 0,
  }),
  card: {
    background: '#fff', border: '1px solid #E5E5E5',
    borderRadius: 8, padding: 24,
    boxShadow: '0 1px 4px rgba(16,65,79,.06)',
    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
  },
};

// ─── Colour placeholder (used until a real photo is set) ──────────────────────
export function Placeholder({ color, ratio = '16/9', radius = 0, style = {} }) {
  return (
    <div style={{
      background: color,
      aspectRatio: ratio,
      width: '100%',
      borderRadius: radius,
      ...style,
    }} />
  );
}

// ─── MediaSlot — renders an admin image if set, else a colour placeholder ─────
// Pass `url` from the Customizer (e.g. getThemeData().images.spotlight1). When it
// is empty, you get the original coloured block, so the layout never breaks.
export function MediaSlot({ url, color, ratio = '1/1', radius = 0, alt = '', style = {} }) {
  if (url) {
    return (
      <img
        src={url}
        alt={alt}
        style={{ width: '100%', aspectRatio: ratio, objectFit: 'cover', borderRadius: radius, display: 'block', ...style }}
      />
    );
  }
  return <Placeholder color={color} ratio={ratio} radius={radius} style={style} />;
}

// Build the nav tree from the WP "Primary Menu" (Appearance → Menus). WordPress
// menu items carry a `parent` id, so nested menu items become dropdown children.
// When no menu is assigned, fall back to a small example tree with one submenu.
function navLinks(t) {
  const items = t.menus.primary || [];
  if (items.length) {
    const byId = {};
    items.forEach((m) => { byId[m.ID] = { label: m.title, href: m.url, target: m.target, children: [] }; });
    const roots = [];
    items.forEach((m) => {
      const node = byId[m.ID];
      const parent = m.parent && byId[m.parent];
      if (parent) parent.children.push(node);
      else roots.push(node);
    });
    return roots;
  }
  return [
    { label: 'Locations', href: `${t.siteUrl}/programs/`, children: [
      { label: 'Ontario', href: `${t.siteUrl}/programs/lessons/` },
      { label: 'Alberta', href: `${t.siteUrl}/programs/leagues/` },
      { label: 'BC', href: `${t.siteUrl}/programs/leagues/` },
    ] },
     { label: 'Getting Started', href: `${t.siteUrl}/programs/`, children: [
      { label: 'FAQ', href: `${t.siteUrl}/programs/lessons/` },
      { label: 'Buy a Paddle', href: `${t.siteUrl}/programs/leagues/` },
    ] },
    { label: 'Who We Are', href: 'https://elevationathletics.ca/camps/', target: '_blank' },
  ];
}

const linkStyle = {
  fontFamily: 'var(--font-body, sans-serif)', fontWeight: 600, fontSize: 16,
  color: 'var(--ea-navy, #10414F)', textDecoration: 'none', whiteSpace: 'nowrap',
};

// Spread onto an <a> to set href + (optional) target. Links opening in a new tab
// get rel="noopener noreferrer" so the new page can't access window.opener.
function linkAttrs(item) {
  const attrs = { href: item.href || '#' };
  if (item.target) {
    attrs.target = item.target;
    if (item.target === '_blank') attrs.rel = 'noopener noreferrer';
  }
  return attrs;
}

// True when href points to a different host than the current page (i.e. an external
// site). Relative and same-host URLs resolve to false; non-http(s) schemes (mailto:,
// tel:, #anchors) are treated as internal so they don't force a new tab.
function isExternalUrl(href) {
  if (!href || typeof window === 'undefined') return false;
  try {
    const url = new URL(href, window.location.href);
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return false;
    return url.hostname !== window.location.hostname;
  } catch {
    return false;
  }
}

// Like linkAttrs, but for the nav: open in a new tab only when the link points to an
// external site. Internal (same-host) links stay in the current tab regardless of the
// per-item "Link Target" set in Appearance → Menus.
function navLinkAttrs(item) {
  const href = item.href || '#';
  return isExternalUrl(href)
    ? { href, target: '_blank', rel: 'noopener noreferrer' }
    : { href };
}

// Turn a Customizer button link ({ url, section }) into anchor props, or null when
// there's no destination. A `section` smooth-scrolls to that element id; a `url`
// navigates (external http(s) URLs open in a new tab).
export function sectionLinkAttrs(link) {
  const l = link || {};
  if (l.section) {
    return {
      href: `#${l.section}`,
      onClick: (e) => {
        const el = typeof document !== 'undefined' && document.getElementById(l.section);
        if (el) { e.preventDefault(); el.scrollIntoView({ behavior: 'smooth' }); }
      },
    };
  }
  if (l.url) {
    return /^https?:\/\//i.test(l.url)
      ? { href: l.url, target: '_blank', rel: 'noopener noreferrer' }
      : { href: l.url };
  }
  return null;
}

// A DS Button (or inline fallback) that optionally links/scrolls per its Customizer
// link. Renders as an <a> when a destination is set (Button supports `as="a"`), so
// there's never an <a> nested inside a <button>.
export function ActionButton({ DS, link, variant = 'primary', size, full = false, style, children }) {
  // Buttons flagged hidden in the Customizer (EA Button Links → "hide this button")
  // render nothing. Hero buttons never carry this flag, so they always show.
  if (link && link.hidden) return null;
  const Button = DS && DS.Button;
  const attrs = sectionLinkAttrs(link);
  if (Button) {
    return attrs
      ? <Button as="a" variant={variant} size={size} full={full} style={style} {...attrs}>{children}</Button>
      : <Button variant={variant} size={size} full={full} style={style}>{children}</Button>;
  }
  const fb = { ...FB.btn(variant), ...(full ? { width: '100%' } : {}), ...style };
  return attrs
    ? <a {...attrs} style={{ ...fb, textDecoration: 'none' }}>{children}</a>
    : <button style={fb}>{children}</button>;
}

// Down chevron; rotates when its menu is open.
function Chevron({ open }) {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"
      style={{ marginLeft: 4, transition: 'transform .4s', transform: open ? 'rotate(180deg)' : 'none' }}>
      <path d="m6 9 6 6 6-6" />
    </svg>
  );
}

// Hamburger that morphs into an X when open.
function Hamburger({ open }) {
  const bar = { height: 2, width: 22, background: 'var(--ea-navy, #10414F)', borderRadius: 2, transition: 'transform .2s, opacity .2s' };
  return (
    <span style={{ display: 'inline-flex', flexDirection: 'column', gap: 5 }}>
      <span style={{ ...bar, transform: open ? 'translateY(7px) rotate(45deg)' : 'none' }} />
      <span style={{ ...bar, opacity: open ? 0 : 1 }} />
      <span style={{ ...bar, transform: open ? 'translateY(-7px) rotate(-45deg)' : 'none' }} />
    </span>
  );
}

// ─── Navigation (shared across pages) ─────────────────────────────────────────
// Custom header so we can support dropdown submenus, a mobile hamburger menu, and
// the admin-swappable logo — none of which the DS NavBar exposes. Uses the same
// design tokens, and the DS Button for the CTA when the bundle is loaded.
function NavSection({ DS, t, isMobile }) {
  const { Button } = DS;
  const links = navLinks(t);
  const logoUrl = t.images.logo;            // custom logo from the Customizer, if set
  const logoHeight = isMobile ? 40 : 52;

  const [openIdx, setOpenIdx] = useState(null);          // desktop dropdown index
  const [mobileOpen, setMobileOpen] = useState(false);   // hamburger panel open
  const [expanded, setExpanded] = useState(null);        // mobile submenu accordion index

  // Reset menu state when crossing the mobile/desktop boundary.
  useEffect(() => {
    if (!isMobile) { setMobileOpen(false); setExpanded(null); }
    else { setOpenIdx(null); }
  }, [isMobile]);

  const ctaLabel = t.texts.navCta || 'Find a League Near You';
  const cta = <ActionButton DS={DS} link={t.links.navCta} variant="primary">{ctaLabel}</ActionButton>;

  // "Connect with us" smooth-scrolls to the footer (id="site-footer").
  const scrollToFooter = (e) => {
    const el = typeof document !== 'undefined' && document.getElementById('site-footer');
    if (el) { e.preventDefault(); el.scrollIntoView({ behavior: 'smooth' }); }
  };
  const contact = (
    <a href="#site-footer" onClick={scrollToFooter} style={{ ...linkStyle, display: 'inline-flex', alignItems: 'center' }}>{t.texts.navConnect || 'Connect with us'}</a>
  );

  return (
    <header style={{
      position: 'sticky', top: 0, left: 0, right: 0, zIndex: 50,
      display: 'flex', alignItems: 'center', gap: 40,
      height: NAV_HEIGHT(isMobile), boxSizing: 'border-box',
      padding: isMobile ? '0 18px' : '0 40px',
      background: 'var(--ea-white, #fff)', borderBottom: '1px solid var(--border-card, #E5E5E5)',
    }}>
      <a href={t.siteUrl || '/'} style={{ display: 'flex', flex: 'none' }}>
        <img
          src={logoUrl || t.asset('ea-logo.svg')}
          alt="Elevation Athletics"
          style={{ display: 'block', height: logoHeight, width: 'auto' }}
        />
      </a>

      {/* ── Desktop nav with hover/focus dropdowns ── */}
      {!isMobile && (
        <nav style={{ display: 'flex', alignItems: 'center', gap: 36 }}>
          {links.map((l, i) => {
            const hasChildren = l.children && l.children.length > 0;
            return (
              <div
                key={l.label}
                style={{ position: 'relative' }}
                onMouseEnter={() => hasChildren && setOpenIdx(i)}
                onMouseLeave={() => hasChildren && setOpenIdx(null)}
              >
                <a
                  {...navLinkAttrs(l)}
                  aria-haspopup={hasChildren || undefined}
                  aria-expanded={hasChildren ? openIdx === i : undefined}
                  style={{ ...linkStyle, display: 'inline-flex', alignItems: 'center' }}
                >
                  {l.label}{hasChildren && <Chevron open={openIdx === i} />}
                </a>
                {hasChildren && openIdx === i && (
                  // Outer wrapper provides a transparent paddingTop "bridge" so the
                  // cursor never crosses dead space between the link and the panel
                  // (which would fire mouseleave and close the menu).
                  <div style={{ position: 'absolute', top: '100%', left: 0, paddingTop: 8, zIndex: 60 }}>
                    <div style={{
                      minWidth: 200, background: '#fff', border: '1px solid var(--border-card, #E5E5E5)',
                      borderRadius: 8, boxShadow: '0 2px 8px rgba(16, 65, 79, 0.04)',
                      padding: 8, display: 'flex', flexDirection: 'column',
                    }}>
                      {l.children.map((c) => (
                        <a key={c.label} {...navLinkAttrs(c)} style={{
                          ...linkStyle, fontWeight: 500, padding: '10px 12px', borderRadius: 6,
                        }}>{c.label}</a>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </nav>
      )}

      {/* ── Right side: CTA (desktop) / hamburger (mobile) ── */}
      <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 36 }}>
        {!isMobile && contact}
        {!isMobile && cta}
        {isMobile && (
          <button
            type="button"
            aria-label="Toggle menu"
            aria-expanded={mobileOpen}
            onClick={() => setMobileOpen((o) => !o)}
            style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 8, display: 'inline-flex' }}
          >
            <Hamburger open={mobileOpen} />
          </button>
        )}
      </div>

      {/* ── Mobile dropdown panel ── */}
      {isMobile && mobileOpen && (
        <div style={{
          position: 'absolute', top: '100%', left: 0, right: 0,
          background: '#fff', borderBottom: '1px solid var(--border-card, #E5E5E5)',
          boxShadow: '0 8px 24px rgba(16,65,79,.12)', padding: '10px 20px 20px', zIndex: 60,
        }}>
          {links.map((l, i) => {
            const hasChildren = l.children && l.children.length > 0;
            return (
              <div key={l.label} style={{ borderBottom: '1px solid #F0F0F0' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <a {...navLinkAttrs(l)} style={{ ...linkStyle, padding: '12px 0', flex: 1 }}>{l.label}</a>
                  {hasChildren && (
                    <button
                      type="button"
                      aria-label={`Toggle ${l.label} submenu`}
                      aria-expanded={expanded === i}
                      onClick={() => setExpanded((e) => (e === i ? null : i))}
                      style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#255461', padding: 8 }}
                    >
                      <Chevron open={expanded === i} />
                    </button>
                  )}
                </div>
                {hasChildren && expanded === i && (
                  <div style={{ paddingLeft: 16, paddingBottom: 8 }}>
                    {l.children.map((c) => (
                      <a key={c.label} {...navLinkAttrs(c)} style={{ ...linkStyle, fontWeight: 500, display: 'block', padding: '8px 0' }}>{c.label}</a>
                    ))}
                  </div>
                )}
              </div>
            );
          })}
          <div style={{ marginTop: 16, display: 'flex', flexDirection: 'column', gap: 12 }}>{contact}{cta}</div>
        </div>
      )}
    </header>
  );
}

// ─── Footer (shared across pages) ─────────────────────────────────────────────
const FOOTER_QUICK_LINKS = [
  { label: 'FAQ',           href: '#' },
  { label: 'Media & News',  href: '#' },
  { label: 'Buy a Paddle',  href: '#' },
  { label: 'Join Our Team', href: '#' },
];
const FOOTER_MORE_SPORTS = [
  { label: 'Basketball',              href: '#' },
  { label: 'Pickleball',            href: '#' },
  { label: 'Camps',                  href: '#' },
  { label: 'Community Partnerships', href: '#' },
];
// Fallback used when the matching WP menu (Appearance → Menus) is not assigned.
const FOOTER_CONTACT = [
  { label: 'pickleball@elevationathletics.ca', href: 'mailto:pickleball@elevationathletics.ca' },
];

// Map WP menu items to the flat {label, href, target} shape the footer uses.
function flatMenu(items) {
  return (items || []).map((m) => ({ label: m.title, href: m.url, target: m.target }));
}

function PageFooter({ isMobile, t }) {
  const logoSrc = t.images.logo || t.asset('ea-logo.svg');   // same logo as the nav bar

  // Display-scale heading, matching the section titles used across the site.
  const heading = {
    fontFamily: 'var(--font-display, "BBH Bogle", system-ui, sans-serif)',
    fontSize: isMobile ? 'var(--fs-display-xxs, 16px)' : 'var(--fs-display-xs, 24px)',
    fontWeight: 'var(--fw-regular, 400)',   // <h4> defaults to bold; BBH Bogle ships Regular only → reset to avoid faux-bold
    lineHeight: 'var(--lh-display, 1)',
    letterSpacing: 'var(--ls-display, 0.02em)',
    textTransform: 'uppercase',
    color: 'var(--text-heading, #10414F)',
    margin: 0,
  };
  // Body-scale link using the design-system link colour token.
  const link = {
    display: 'block', marginTop: 12, textDecoration: 'none',
    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
    fontSize: isMobile ? 'var(--fs-body-sm, 14px)' : 'var(--fs-body, 16px)',
    lineHeight: 'var(--lh-body, 1.5)',
    letterSpacing: 'var(--ls-body, -0.02em)',
    color: 'var(--ea-ink, #0D5265)',
  };
  const LinkColumn = ({ title, links }) => (
    <div>
      <h4 style={heading}>{title}</h4>
      <nav>{links.map((l) => <a key={l.label} {...linkAttrs(l)} style={link}>{l.label}</a>)}</nav>
    </div>
  );

  // Each footer group is driven by a WP menu (Appearance → Menus) when assigned,
  // otherwise the hardcoded fallback above.
  const m = t.menus || {};
  const quickLinks  = m.footerQuickLinks && m.footerQuickLinks.length ? flatMenu(m.footerQuickLinks) : FOOTER_QUICK_LINKS;
  const moreSports  = m.footerMoreSports && m.footerMoreSports.length ? flatMenu(m.footerMoreSports) : FOOTER_MORE_SPORTS;
  const contactList = m.footerContact && m.footerContact.length ? flatMenu(m.footerContact) : FOOTER_CONTACT;

  // Social icons come from Customizer URL fields (EA Social Links); blank = hidden.
  const social = t.social || {};
  const socials = [
    { label: 'Instagram', icon: 'instagram.svg', href: social.instagram },
    { label: 'Facebook',  icon: 'facebook.svg',  href: social.facebook },
  ].filter((s) => s.href);

  // Desktop: sits under the left block. Mobile: moved below everything, centred.
  const copyright = (
    <p style={{
      fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
      fontSize: 'var(--fs-label, 14px)', letterSpacing: 'var(--ls-body, -0.02em)',
      color: 'var(--text-muted, #47636B)',
      marginTop: 32, marginBottom: 0,
      textAlign: 'left',
    }}>
      © {new Date().getFullYear()} {t.texts.footerCopyright || 'Elevation Athletics. All rights reserved.'}
      {' · '}<a href="https://elevationathletics.ca/privacy-policy/" style={{ color: 'inherit', textDecoration: 'underline' }}>Privacy Policy</a>
    </p>
  );

  return (
    <footer id="site-footer" style={{
      background: 'var(--surface-page, #fff)', borderTop: '1px solid var(--border-card, #E5E5E5)',
      padding: isMobile ? '40px 20px' : '56px 40px',
    }}>
      <div style={{
        maxWidth: 1184, margin: '0 auto',
        display: 'grid',
        // Mobile: left block spans the full width on top, link columns sit side by side below.
        gridTemplateColumns: isMobile ? '1fr 1fr' : '1.6fr 1fr 1fr',
        columnGap: 40, rowGap: isMobile ? 36 : 0,
        alignItems: 'start',
      }}>
        {/* Left block: logo, contact, socials, copyright */}
        <div style={{ gridColumn: isMobile ? '1 / -1' : undefined }}>
          <a href={t.siteUrl || '/'} style={{ display: 'inline-flex' }}>
            <img src={logoSrc} alt="Elevation Athletics" style={{ display: 'block', height: isMobile ? 56 : 80, width: 'auto' }} />
          </a>

          <h4 style={{ ...heading, marginTop: 28 }}>{t.texts.footerContactHeading || 'Contact Us'}</h4>
          {contactList.map((c) => (
            <a key={c.href || c.label} {...linkAttrs(c)}
               style={{ ...link, display: 'inline-flex', alignItems: 'center', gap: 8 }}>
              <img src={t.asset('mail.svg')} alt="" aria-hidden="true" style={{ display: 'block', height: 24, width: 'auto' }} /> {c.label}
            </a>
          ))}

          {socials.length > 0 && (
            <>
              <h4 style={{ ...heading, marginTop: 28 }}>{t.texts.footerSocialsHeading || 'Follow us on our socials!'}</h4>
              <div style={{ display: 'flex', gap: 12, marginTop: 12 }}>
                {socials.map((s) => (
                  <a key={s.label} href={s.href} target="_blank" rel="noopener noreferrer" aria-label={s.label} style={{ display: 'inline-flex' }}>
                    <img src={t.asset(s.icon)} alt={s.label} width={34} height={34} style={{ display: 'block' }} />
                  </a>
                ))}
              </div>
            </>
          )}

          {!isMobile && copyright}
        </div>

        <LinkColumn title={t.texts.footerQuickLinksTitle || 'Quick Links'} links={quickLinks} />
        <LinkColumn title={t.texts.footerMoreSportsTitle || 'More Sports'} links={moreSports} />
      </div>

      {/* Mobile: copyright sits below everything, full width and centred. */}
      {isMobile && <div style={{ maxWidth: 1184, margin: '0 auto' }}>{copyright}</div>}
    </footer>
  );
}

// ─── Layout — the shell every page renders inside ─────────────────────────────
export function Layout({ children }) {
  const DS = useDSComponents();
  const { isMobile, width } = useViewport();
  const t = getThemeData();
  // The nav collapses to the hamburger at a wider breakpoint than the page's
  // 768px mobile layout, so it never gets squished on tablet-ish widths.
  const navCollapsed = width < NAV_COLLAPSE_WIDTH;
  return (
    <div style={{ background: '#fff', minHeight: '100vh', fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)' }}>
      <NavSection DS={DS} t={t} isMobile={navCollapsed} />
      {/* A sticky nav stays in normal flow, so the content needs no offset. */}
      {children}
      <PageFooter isMobile={isMobile} t={t} />
    </div>
  );
}
