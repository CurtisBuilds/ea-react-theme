/**
 * City-specific programs template.
 *
 * WordPress provides the page slug through template-city-programs.php. The page
 * then filters the shared programs feed by pageIdentifyer/URL/city slug so one
 * reusable template can power pages like /newmarket/ or /richmond-hill/.
 */
import { useMemo, useState } from 'react';
import { Layout, useDSComponents, useViewport, getThemeData, FB } from '../lib/shared.jsx';
import {
  cityRecordPageSegments,
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
  buildCitySummaries,
  citySlug,
  normalizePrograms,
  siteSport,
  useProgramsFeed,
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
  return ['bask'];
}

function numericLevel(program) {
  const raw = program.level ?? program.Level ?? program.LEVEL;
  if (raw === undefined || raw === null || raw === '') return null;
  const match = String(raw).match(/\d+/);
  return match ? Number(match[0]) : null;
}

function levelLabel(program) {
  const level = numericLevel(program);
  if (level !== null) {
    if (level <= 1) return 'Beginner';
    if (level === 2) return 'Experienced Beginner';
    if (level === 3) return 'Intermediate';
    if (level >= 4) return 'Advanced';
  }

  const title = String(program.Title || '').toLowerCase();
  if (title.includes('advanced')) return 'Advanced';
  if (title.includes('intermediate')) return 'Intermediate';
  if (title.includes('experienced beginner')) return 'Experienced Beginner';
  return 'Beginner';
}

function parseLocalDate(dateStr) {
  if (!dateStr) return null;
  const parts = String(dateStr).split('-');
  if (parts.length !== 3) {
    const parsed = new Date(dateStr);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  }
  const [year, month, day] = parts.map(Number);
  const parsed = new Date(year, month - 1, day);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function formatProgramDate(dateStr) {
  const date = parseLocalDate(dateStr);
  if (!date) return '';
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function todayStart() {
  const today = new Date();
  return new Date(today.getFullYear(), today.getMonth(), today.getDate());
}

function getStartDate(program) {
  return parseLocalDate(program['Start Date'] || program.StartDate || program.startDate);
}

function nextAvailableSessionDate(program) {
  const today = todayStart();
  const sessionDates = String(program.SessionDates || '')
    .split(',')
    .map((value) => parseLocalDate(value.trim()))
    .filter((date) => date instanceof Date && !Number.isNaN(date.getTime()) && date >= today)
    .sort((a, b) => a.getTime() - b.getTime());

  if (sessionDates.length) return sessionDates[0];

  const startDate = getStartDate(program);
  return startDate instanceof Date && !Number.isNaN(startDate.getTime()) && startDate >= today ? startDate : null;
}

function trialSessionOption(program) {
  const nextDate = nextAvailableSessionDate(program);
  return [
    levelLabel(program),
    nextDate ? formatProgramDate(nextDate) : '',
    program.Time,
    program.LocationName,
  ].filter(Boolean).join(' | ');
}

function CityFreeTrialForm({ cityName, programs, isMobile, t }) {
  const [form, setForm] = useState({ name: '', email: '', phone: '', session: '', website: '' });
  const [sending, setSending] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [error, setError] = useState('');

  const sessionOptions = useMemo(() => {
    const seen = new Set();
    return (programs || [])
      .map((program) => ({
        label: trialSessionOption(program),
        date: nextAvailableSessionDate(program)?.getTime() ?? Infinity,
      }))
      .sort((a, b) => a.date - b.date || a.label.localeCompare(b.label))
      .map((choice) => choice.label)
      .filter((label) => {
        if (!label || seen.has(label)) return false;
        seen.add(label);
        return true;
      });
  }, [programs]);

  const update = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  const handleSubmit = async (event) => {
    event.preventDefault();
    setError('');

    if (!form.name.trim() || !form.email.trim() || !form.phone.trim() || !form.session.trim()) {
      setError('Please enter your name, email, phone number, and session.');
      return;
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
      setError('Please enter a valid email address.');
      return;
    }

    setSending(true);
    try {
      const response = await fetch(`${t.apiUrl}ea/v1/free-trial`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': t.nonce },
        body: JSON.stringify({
          ...form,
          city: cityName,
          source: 'city-programs',
        }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(data && data.message ? data.message : 'Something went wrong. Please try again.');
      }

      setSubmitted(true);
      setForm({ name: '', email: '', phone: '', session: '', website: '' });
    } catch (err) {
      setError(err.message || 'Something went wrong. Please try again.');
    } finally {
      setSending(false);
    }
  };

  const labelStyle = {
    position: 'absolute', width: 1, height: 1, padding: 0, margin: -1,
    overflow: 'hidden', clip: 'rect(0, 0, 0, 0)', whiteSpace: 'nowrap', border: 0,
  };
  const fieldStyle = {
    width: '100%',
    minHeight: isMobile ? 48 : 44,
    boxSizing: 'border-box',
    padding: isMobile ? '12px 14px' : '10px 13px',
    border: '1px solid var(--border-card, #E5E5E5)',
    borderRadius: 8,
    background: '#fff',
    color: 'var(--ea-ink, #1E526E)',
    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
    fontSize: isMobile ? 16 : 15,
    lineHeight: 1.25,
  };

  return (
    <section
      aria-label={`Free trial signup for ${cityName}`}
      style={{
        ...FB.card,
        display: 'grid',
        gridTemplateColumns: isMobile ? '1fr' : 'minmax(220px, 0.44fr) minmax(0, 1fr)',
        gap: isMobile ? 18 : 24,
        alignItems: 'center',
        margin: isMobile ? '0 0 18px' : '0 0 22px',
        padding: isMobile ? '20px' : '20px 24px',
        background: '#fff',
      }}
    >
      <div>
        <h2 style={{
          ...FB.h(isMobile ? 28 : 27),
          margin: 0,
          fontWeight: 'var(--fw-regular, 400)',
          letterSpacing: '0.01em',
          color: 'var(--ea-navy, #10414F)',
          lineHeight: 1.06,
        }}>
          Try a Free Class in {cityName}
        </h2>
        <p style={{
          margin: '8px 0 0',
          maxWidth: 360,
          fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
          fontSize: isMobile ? 15 : 15,
          lineHeight: 1.4,
          color: 'var(--ea-ink, #1E526E)',
        }}>
          Send us your details and our team will follow up about the session that works best.
        </p>
      </div>

      {submitted ? (
        <div role="status" style={{
          padding: isMobile ? '16px' : '18px 20px',
          borderRadius: 8,
          background: '#CFF6D9',
          color: '#287545',
          fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
          fontWeight: 'var(--fw-bold, 700)',
        }}>
          Thank you. We received your free trial request and will follow up soon.
        </div>
      ) : (
        <form onSubmit={handleSubmit}>
          <div style={{
            display: 'grid',
            gridTemplateColumns: isMobile ? '1fr' : 'repeat(3, minmax(0, 1fr))',
            gap: isMobile ? 12 : 10,
            alignItems: 'center',
          }}>
            <div>
              <label style={labelStyle} htmlFor="city-ft-name">Name</label>
              <input id="city-ft-name" value={form.name} onChange={update('name')} placeholder="Name" autoComplete="name" style={fieldStyle} />
            </div>
            <div>
              <label style={labelStyle} htmlFor="city-ft-email">Email</label>
              <input id="city-ft-email" type="email" value={form.email} onChange={update('email')} placeholder="Email" autoComplete="email" style={fieldStyle} />
            </div>
            <div>
              <label style={labelStyle} htmlFor="city-ft-phone">Phone Number</label>
              <input id="city-ft-phone" type="tel" value={form.phone} onChange={update('phone')} placeholder="Phone Number" autoComplete="tel" style={fieldStyle} />
            </div>
            <div style={{ gridColumn: isMobile ? undefined : '1 / span 2' }}>
              <label style={labelStyle} htmlFor="city-ft-session">Session</label>
              <select id="city-ft-session" value={form.session} onChange={update('session')} style={{ ...fieldStyle, appearance: 'auto' }}>
                <option value="">Choose Session</option>
                {sessionOptions.map((option) => (
                  <option key={option} value={option}>{option}</option>
                ))}
              </select>
            </div>
            <div style={{ display: 'flex', justifyContent: isMobile ? 'stretch' : 'flex-end' }}>
              <button
                type="submit"
                disabled={sending}
                style={{
                  ...FB.btn('primary'),
                  width: isMobile ? '100%' : 'auto',
                  minHeight: isMobile ? undefined : 44,
                  padding: isMobile ? undefined : '10px 24px',
                  opacity: sending ? 0.7 : 1,
                  cursor: sending ? 'default' : 'pointer',
                }}
              >
                {sending ? 'Submitting...' : 'Submit'}
              </button>
            </div>
          </div>

          <div aria-hidden="true" style={{ position: 'absolute', left: '-9999px', width: 1, height: 1, overflow: 'hidden' }}>
            <label htmlFor="city-ft-website">Website</label>
            <input id="city-ft-website" tabIndex={-1} autoComplete="off" value={form.website} onChange={update('website')} />
          </div>

          {error && (
            <p role="alert" style={{
              margin: '12px 0 0',
              fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
              fontSize: 14,
              color: 'var(--ea-error, #C0392B)',
            }}>
              {error}
            </p>
          )}
        </form>
      )}
    </section>
  );
}

export default function CityProgramsPage({ showFreeTrial = false }) {
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

    if (codedNearby.length) return codedNearby;

    const origin = currentSummary?.coords || (
      resolvedCityRecord?.lat && resolvedCityRecord?.lng ? [Number(resolvedCityRecord.lat), Number(resolvedCityRecord.lng)] : null
    );
    return buildCitySummaries(
      allPrograms.filter((program) => norm(program.City) !== cityKey),
      origin,
      false
    ).slice(0, NEARBY_CITY_LIMIT).map((summary) => ({
      summary,
      record: findCityRecord(cities, summary.slug, summary.city, summary.province),
    }));
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
          </header>

          {showFreeTrial && cityPrograms.length > 0 && (
            <CityFreeTrialForm
              cityName={displayCityName}
              programs={cityPrograms}
              isMobile={isMobile}
              t={t}
            />
          )}

          <div style={{ display: 'grid', gap: isMobile ? 12 : 18 }}>
            {cityPrograms.length ? cityPrograms.map((program) => (
              <ProgramCard
                key={program._key}
                program={program}
                isMobile={isMobile}
                onSubscribe={setSubscribeLoc}
                t={t}
              />
            )) : status === 'loading' ? (
              <div style={{ ...FB.card, textAlign: 'center' }}>
                <strong>Loading programs...</strong>
              </div>
            ) : (
              <div style={{ ...FB.card, textAlign: 'center' }}>
                <strong>No active programs found for {displayCityName}.</strong>
                <p style={{ margin: '8px 0 0', fontFamily: 'var(--font-body)', color: 'var(--ea-slate, #47636B)' }}>
                  Check nearby cities below, or come back soon for new programs.
                </p>
              </div>
            )}
          </div>

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
