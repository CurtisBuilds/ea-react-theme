import { useMemo } from 'react';
import { ActionButton, FB, Layout, getThemeData, useDSComponents, useViewport } from '../lib/shared.jsx';
import {
  ProgramCard,
  cityDisplayName,
  normalizePrograms,
  useProgramsFeed,
} from './LeagueHubPage.jsx';
import { slugify } from '../data/cities.js';

const DEFAULT_NAV_ROWS = [
  'Programs|#volleyball-programs',
  'All Sports|https://elevationathletics.ca/',
];

const DEFAULT_FOOTER_QUICK_ROWS = [
  'Volleyball Programs|#volleyball-programs',
  'Community Partnerships|https://elevationathletics.ca/partnerships/',
];

const DEFAULT_FOOTER_SPORT_ROWS = [
  'Basketball|https://elevationathletics.ca/home/',
  'Badminton|https://eabadminton.com/',
  'Pickleball|https://eapickleball.com/',
];

const DEFAULT_FOOTER_CONTACT_ROWS = [
  'info@elevationathletics.ca|mailto:info@elevationathletics.ca',
];

const DEFAULT_FOOTER_SOCIAL_ROWS = [];

function pick(value, fallback) {
  return value === undefined || value === null || value === '' ? fallback : value;
}

function parseRows(value, fallbackRows) {
  const source = String(value || '').trim() ? String(value) : fallbackRows.join('\n');
  return source
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      const [label, href = '#'] = line.split('|').map((part) => part.trim());
      return label ? { label, href: href || '#' } : null;
    })
    .filter(Boolean);
}

function parseSocialRows(value) {
  return parseRows(value, DEFAULT_FOOTER_SOCIAL_ROWS)
    .map((item) => {
      const key = item.label.toLowerCase();
      const icon = key.includes('facebook') ? 'facebook.svg' : key.includes('instagram') ? 'instagram.svg' : '';
      return icon && item.href ? { ...item, icon } : null;
    })
    .filter(Boolean);
}

function actionLink(value, fallback) {
  const href = pick(value, fallback);
  if (!href) return null;
  return { url: href };
}

// Town page (/volleyball/innisfil/) vs hub (/volleyball/): set by template-volleyball.php.
function volleyballRoute() {
  const root = typeof document !== 'undefined' ? document.getElementById('ea-react-root') : null;
  let towns = {};
  try { towns = JSON.parse((root && root.dataset.towns) || '{}') || {}; } catch (e) { towns = {}; }
  return { town: slugify((root && root.dataset.town) || ''), hubUrl: (root && root.dataset.hubUrl) || '/volleyball/', towns };
}

function programTownSlug(program) {
  return slugify(program.City || program.city || '');
}

export default function VolleyballPage() {
  const DS = useDSComponents();
  const { isMobile } = useViewport();
  const t = getThemeData();
  const v = t.volleyball || {};
  const { rows, status } = useProgramsFeed();

  const route = useMemo(volleyballRoute, []);
  const programs = useMemo(
    () => normalizePrograms(rows || [], ['vball'], null)
      .filter((program) => !program._comingSoon)
      .filter((program) => !route.town || programTownSlug(program) === route.town),
    [rows, route.town]
  );
  const townName = route.town
    ? (programs[0] && String(programs[0].City || '').trim()) || route.town.split('-').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join('-')
    : '';
  const groupedPrograms = useMemo(() => {
    const groups = new Map();
    programs.forEach((program) => {
      const city = String(program.City || '').trim() || 'Volleyball Programs';
      const province = String(program.Province || '').trim();
      const key = `${city.toLowerCase()}|${province.toLowerCase()}`;
      if (!groups.has(key)) {
        groups.set(key, {
          key,
          title: cityDisplayName(city, province),
          href: route.town ? '' : (route.towns[slugify(city)] || ''),
          programs: [],
        });
      }
      groups.get(key).programs.push(program);
    });
    return Array.from(groups.values());
  }, [programs]);

  const logoSrc = v.logoImage || t.asset('ea-logo-horizontal.png');
  const layoutOverrides = {
    logoSrc,
    logoHref: pick(v.logoHref, 'https://elevationathletics.ca/'),
    navLinks: parseRows(v.navRows, DEFAULT_NAV_ROWS),
    navCtaLabel: pick(v.navButtonLabel, 'View Programs'),
    navCtaLink: actionLink(v.navButtonUrl, '#volleyball-programs'),
    navConnectLabel: pick(v.navContactLabel, 'Contact'),
    footerQuickLinksTitle: pick(v.footerQuickHeading, 'Quick Links'),
    footerMoreSportsTitle: pick(v.footerSportsHeading, 'More Sports'),
    footerSocialsHeading: pick(v.footerSocialHeading, 'Follow us on our socials!'),
    footerQuickLinks: parseRows(v.footerQuickRows, DEFAULT_FOOTER_QUICK_ROWS),
    footerMoreSports: parseRows(v.footerSportsRows, DEFAULT_FOOTER_SPORT_ROWS),
    footerContact: parseRows(v.footerContactRows, DEFAULT_FOOTER_CONTACT_ROWS),
    footerSocials: parseSocialRows(v.footerSocialRows),
  };

  return (
    <Layout overrides={layoutOverrides}>
      <main style={{ background: '#fff', color: 'var(--ea-navy, #10414F)', fontFamily: 'var(--font-body)', overflow: 'hidden' }}>
        <section style={{
          maxWidth: 1184,
          margin: '0 auto',
          padding: isMobile ? '54px 18px 44px' : '84px 32px 58px',
          textAlign: 'center',
        }}>
          {v.heroImage && (
            <img
              src={v.heroImage}
              alt=""
              style={{ width: isMobile ? 118 : 160, height: isMobile ? 118 : 160, objectFit: 'cover', borderRadius: 8, margin: '0 auto 22px', display: 'block' }}
            />
          )}
          <h1 style={{ ...FB.h(isMobile ? 52 : 78), textAlign: 'center' }}>
            {townName ? `Volleyball in ${townName}` : pick(v.heroHeading, 'Play Volleyball in Canada')}
          </h1>
          <p style={{
            margin: isMobile ? '16px auto 0' : '22px auto 0',
            maxWidth: 760,
            fontFamily: 'var(--font-body)',
            fontSize: isMobile ? 18 : 23,
            lineHeight: 1.42,
            color: 'var(--ea-ink, #1E526E)',
            whiteSpace: 'pre-line',
          }}>
            {townName ? `Youth volleyball programs in ${townName}, run by Elevation Athletics with our community partners. Pick a session below and register.` : pick(v.heroSubheading, 'Find youth volleyball programs run by Elevation Athletics and our community partners. Browse upcoming sessions by city and register for the program that fits your schedule.')}
          </p>
          <div style={{
            display: 'grid',
            gridTemplateColumns: isMobile ? '1fr' : 'repeat(2, minmax(0, 1fr))',
            gap: isMobile ? 10 : 16,
            maxWidth: 680,
            margin: isMobile ? '28px auto 0' : '36px auto 0',
          }}>
            <ActionButton DS={DS} link={actionLink(v.primaryButtonUrl, '#volleyball-programs')} full>{townName ? 'View Programs' : pick(v.primaryButtonLabel, 'View All Programs')}</ActionButton>
            {townName
              ? <ActionButton DS={DS} link={{ url: route.hubUrl }} variant="secondary" full>All Volleyball Locations</ActionButton>
              : <ActionButton DS={DS} link={actionLink(v.secondaryButtonUrl, 'https://elevationathletics.ca/partnerships/')} variant="secondary" full>{pick(v.secondaryButtonLabel, 'Community Partnerships')}</ActionButton>}
          </div>
        </section>

        <section id="volleyball-programs" style={{
          maxWidth: 1184,
          margin: '0 auto',
          padding: isMobile ? '28px 18px 52px' : '40px 32px 76px',
          scrollMarginTop: 100,
        }}>
          <div>
            {groupedPrograms.length ? groupedPrograms.map((group) => (
              <section key={group.key} style={{ marginTop: isMobile ? 30 : 38 }}>
                <h3 style={{
                  ...FB.h(isMobile ? 34 : 42),
                  marginBottom: isMobile ? 14 : 16,
                }}>
                  {group.href
                    ? <a href={group.href} style={{ color: 'inherit', textDecoration: 'none' }}>{group.title} <span aria-hidden="true" style={{ fontSize: '0.6em' }}>→</span></a>
                    : group.title}
                </h3>
                <div style={{
                  display: 'grid',
                  gridTemplateColumns: '1fr',
                  gap: 10,
                }}>
                  {group.programs.map((program) => (
                    <ProgramCard key={program._key} program={program} isMobile={isMobile} stacked={isMobile} t={t} sportOverride="Volleyball" />
                  ))}
                </div>
              </section>
            )) : status === 'loading' ? (
              <div style={{ ...FB.card, textAlign: 'center' }}>
                <strong>Loading volleyball programs...</strong>
              </div>
            ) : (
              <div style={{ ...FB.card, textAlign: 'center' }}>
                <strong>{townName ? `No active volleyball programs in ${townName} right now.` : pick(v.emptyHeading, 'No active volleyball programs are listed right now.')}</strong>
                <p style={{ margin: '8px 0 0', fontFamily: 'var(--font-body)', color: 'var(--ea-slate, #47636B)' }}>
                  {pick(v.emptyText, 'Please check back soon for new volleyball programming.')}
                </p>
              </div>
            )}
          </div>
        </section>
      </main>
    </Layout>
  );
}
