/**
 * City-specific programs template.
 *
 * WordPress provides the page slug through template-city-programs.php. The page
 * then filters the shared programs feed by pageIdentifyer/URL/city slug so one
 * reusable template can power pages like /newmarket/ or /richmond-hill/.
 */
import { Fragment, useMemo, useState } from 'react';
import { Layout, useDSComponents, useViewport, getThemeData, FB } from '../lib/shared.jsx';
import {
  cityRecordPageSegments,
  cityRecordCoords,
  cityRecordSlug,
  findCityRecord,
  nearbyCityRecords,
  summaryCityHref,
  slugify,
  urlPathSegments as parseUrlPathSegments,
  useCitiesFeed,
} from '../data/cities.js';
import {
  ProgramCard,
  LeagueCityCard,
  NewsletterModal,
  ProgramSubscribeButton,
  buildCitySummaries,
  citySlug,
  normalizePrograms,
  siteSport,
  useProgramsFeed,
  seasonSections,
} from './LeagueHubPage.jsx';

const norm = (value) => String(value || '').trim().toLowerCase();
const NEARBY_CITY_LIMIT = 4;

function currentPageSlug() {
  if (typeof document !== 'undefined') {
    const root = document.getElementById('ea-react-root');
    const fromTemplate = root?.dataset.citySlug || root?.dataset.wpSlug;
    if (fromTemplate) return slugify(fromTemplate);
  }

  if (typeof window === 'undefined') return '';
  const params = new URLSearchParams(window.location.search);
  return slugify(params.get('city') || window.location.pathname.split('/').filter(Boolean).pop() || '');
}

function pageIdentifier(program) {
  return slugify(program.pageIdentifyer || program.pageIdentifier || program.PageIdentifier || program['Page Identifier']);
}

function programUrlPathSegments(program) {
  return parseUrlPathSegments(program.URL || program.url);
}

function programMatchesPage(program, slug, cityRecord = null) {
  if (!slug) return false;
  const cityPageSegments = cityRecord ? cityRecordPageSegments(cityRecord) : [];
  if (pageIdentifier(program) === slug) return true;
  if (cityPageSegments.includes(pageIdentifier(program))) return true;
  if (programUrlPathSegments(program).includes(slug)) return true;
  if (programUrlPathSegments(program).some((segment) => cityPageSegments.includes(segment))) return true;
  if (cityRecord && citySlug(program.City || program.city) === cityRecordSlug(cityRecord)) return true;
  return citySlug(program.City || program.city) === slug;
}

function titleCaseSlug(slug) {
  return String(slug || '')
    .split('-')
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

function mostCommon(values) {
  const counts = new Map();
  values.filter(Boolean).forEach((value) => counts.set(value, (counts.get(value) || 0) + 1));
  return [...counts.entries()].sort((a, b) => b[1] - a[1])[0]?.[0] || '';
}

function cityDisplayName(programs, slug) {
  return mostCommon(programs.map((program) => String(program.City || '').trim())) || titleCaseSlug(slug);
}

function selectedSports(t) {
  return ['pb'];
}

export default function CityProgramsPage() {
  const DS = useDSComponents();
  const { isMobile } = useViewport();
  const t = getThemeData();
  const { rows, status } = useProgramsFeed();
  const cities = useCitiesFeed();
  const [subscribeLoc, setSubscribeLoc] = useState(null);

  const slug = currentPageSlug();
  const sport = siteSport(t);
  const backdrop = t.images.cityProgramsBackdrop || t.images.leagueHubBackdrop || '';
  const allPrograms = useMemo(
    () => normalizePrograms(rows || [], selectedSports(t), null).filter((program) => !program._comingSoon),
    [rows, t.options]
  );
  const cityRecord = useMemo(
    () => findCityRecord(cities, slug),
    [cities, slug]
  );
  const cityPrograms = useMemo(
    () => allPrograms.filter((program) => programMatchesPage(program, slug, cityRecord)),
    [allPrograms, slug, cityRecord]
  );
  const cityName = cityDisplayName(cityPrograms, slug);
  const resolvedCityRecord = useMemo(
    () => cityRecord || findCityRecord(cities, slug, cityName),
    [cities, slug, cityName, cityRecord]
  );
  const displayCityName = String(resolvedCityRecord?.City || cityName).trim() || cityName;
  const cityKey = norm(cityName);
  const currentSummary = useMemo(
    () => buildCitySummaries(cityPrograms, null, false)[0] || null,
    [cityPrograms]
  );
  const nearbySummaries = useMemo(() => {
    const allSummaries = buildCitySummaries(allPrograms, null, false);
    const summaryForRecord = (record) => allSummaries.find((summary) => (
      citySlug(summary.city) === cityRecordSlug(record)
      && (!record.Province || norm(summary.province) === norm(record.Province))
    ));

    const codedNearby = nearbyCityRecords(resolvedCityRecord, cities)
      .map((record) => ({ record, summary: summaryForRecord(record) }))
      .filter(({ summary }) => summary && summary.availableCount > 0)
      .slice(0, NEARBY_CITY_LIMIT);

    const usedNearbyKeys = new Set(codedNearby.map(({ summary }) => summary.key));

    const origin = currentSummary?.coords || (
      cityRecordCoords(resolvedCityRecord)
    );
    const fallbackNearby = buildCitySummaries(
      allPrograms.filter((program) => norm(program.City) !== cityKey),
      origin,
      false
    )
      .filter((summary) => !usedNearbyKeys.has(summary.key))
      .slice(0, NEARBY_CITY_LIMIT - codedNearby.length)
      .map((summary) => ({
        summary,
        record: findCityRecord(cities, summary.slug, summary.city, summary.province),
      }));

    return [...codedNearby, ...fallbackNearby];
  }, [allPrograms, cityKey, currentSummary, resolvedCityRecord, cities]);

  return (
    <Layout>
      <main style={{ position: 'relative', background: '#fff', minHeight: '100vh', overflow: 'hidden' }}>
        {backdrop && (
          <div
            aria-hidden="true"
            style={{
              position: 'absolute',
              inset: 0,
              backgroundImage: `url("${backdrop}")`,
              backgroundRepeat: 'repeat-y',
              backgroundPosition: 'center top',
              backgroundSize: '100% auto',
              opacity: 0.5,
              pointerEvents: 'none',
            }}
          />
        )}
        <section style={{
          position: 'relative',
          maxWidth: 1184,
          margin: '0 auto',
          padding: isMobile ? '30px 16px 72px' : '48px 32px 96px',
        }}>
          <header style={{ marginBottom: isMobile ? 18 : 26 }}>
            <h1 style={{ ...FB.h(isMobile ? 40 : 58), maxWidth: 980 }}>
              EA {sport} {displayCityName}
            </h1>
            {resolvedCityRecord?.facebook_link && resolvedCityRecord.facebook_link !== 'N/A' && (
              <a
                href={resolvedCityRecord.facebook_link}
                target="_blank"
                rel="noopener noreferrer"
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: 7,
                  marginTop: 6,
                  color: 'var(--ea-slate, #47636B)',
                  fontFamily: 'var(--font-body)',
                  fontSize: 13,
                  fontWeight: 600,
                  textDecoration: 'none',
                  opacity: 0.78,
                }}
              >
                <img src={t.asset('facebook.svg')} alt="" aria-hidden="true" style={{ width: 18, height: 18, display: 'block' }} />
                Facebook group
              </a>
            )}
          </header>

          <div style={{ display: 'grid', gap: isMobile ? 12 : 18 }}>
            {cityPrograms.length ? seasonSections(cityPrograms).map((section, i, all) => (
              <Fragment key={section.key}>
                {all.length > 1 && (
                  <h2 style={{ ...FB.h(isMobile ? 24 : 28), margin: i ? (isMobile ? '16px 0 0' : '24px 0 0') : 0 }}>{section.label}</h2>
                )}
                {section.programs.map((program) => (
                  <ProgramCard
                    key={program._key}
                    program={program}
                    isMobile={isMobile}
                    onSubscribe={setSubscribeLoc}
                    t={t}
                  />
                ))}
              </Fragment>
            )) : status === 'loading' ? (
              <div style={{ ...FB.card, textAlign: 'center' }}>
                <strong>Loading programs...</strong>
              </div>
            ) : (
              <div style={{ ...FB.card, textAlign: 'center', color: 'var(--ea-navy, #10414F)' }}>
                <strong style={{
                  display: 'block',
                  fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
                  fontSize: isMobile ? 20 : 24,
                  lineHeight: 1.2,
                  letterSpacing: 0,
                  color: 'var(--ea-navy, #10414F)',
                }}>
                  No active programs found for {displayCityName}.
                </strong>
                <p style={{
                  margin: '8px auto 0',
                  maxWidth: 720,
                  fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
                  fontSize: isMobile ? 16 : 19,
                  lineHeight: 1.4,
                  letterSpacing: 0,
                  color: 'var(--ea-ink, #1E526E)',
                }}>
                  Check nearby cities below or sign up for the {displayCityName} newsletter to stay updated with future programming.
                </p>
                <div style={{ display: 'flex', justifyContent: 'center', marginTop: 16 }}>
                  <ProgramSubscribeButton
                    city={displayCityName}
                    province={String(resolvedCityRecord?.Province || '').trim()}
                    isMobile={isMobile}
                    onSubscribe={setSubscribeLoc}
                  />
                </div>
              </div>
            )}
          </div>

          {!cityPrograms.length && status !== 'loading' && (
            <div
              aria-hidden="true"
              style={{
                display: 'flex',
                justifyContent: 'center',
                marginTop: isMobile ? 26 : 34,
                marginBottom: isMobile ? -24 : -34,
                pointerEvents: 'none',
              }}
            >
              <img
                src={t.images.faqQuestionSquid || t.asset('faq-pickleball-question-squid.svg')}
                alt=""
                style={{
                  display: 'block',
                  width: isMobile ? 126 : 176,
                  maxWidth: '42vw',
                  height: 'auto',
                }}
              />
            </div>
          )}

          {nearbySummaries.length > 0 && (
            <section style={{ marginTop: isMobile ? 52 : 72 }}>
              <h2 style={{
                ...FB.h(isMobile ? 30 : 34),
                fontWeight: 'var(--fw-regular, 400)',
                letterSpacing: '0.01em',
              }}>Nearby Programs</h2>
              <p style={{
                margin: '8px 0 28px',
                fontFamily: 'var(--font-body)',
                fontSize: isMobile ? 16 : 18,
                color: 'var(--ea-ink, #1E526E)',
                lineHeight: 1.35,
              }}>
                Looking for more? Check out programs in nearby cities.
              </p>
              <div style={{
                display: 'grid',
                gridTemplateColumns: isMobile ? '1fr' : 'repeat(2, minmax(0, 1fr))',
                gap: isMobile ? 12 : 16,
              }}>
                {nearbySummaries.map(({ summary, record }) => (
                  <LeagueCityCard
                    key={summary.key}
                    summary={summary}
                    isMobile={isMobile}
                    onSubscribe={setSubscribeLoc}
                    t={t}
                    href={summaryCityHref(summary, cities, t)}
                  />
                ))}
              </div>
            </section>
          )}
        </section>
        {subscribeLoc && <NewsletterModal DS={DS} t={t} location={subscribeLoc} onClose={() => setSubscribeLoc(null)} />}
      </main>
    </Layout>
  );
}
