/**
 * src/pages/LeagueHubPage.jsx — searchable program hub.
 * Used by template-league-hub.php and intended as the destination for the
 * homepage "View All Programs" button.
 */
import { useEffect, useMemo, useState } from 'react';
import { Layout, useDSComponents, useViewport, getThemeData, FB } from '../lib/shared.jsx';
import { ViewToggle, LeagueHubMapView, LeagueHubCalendarView } from './LeagueHubMapCalendar.jsx';
import { resolveVenueCoords } from '../data/venueCoords.js';
import { summaryCityHref, useCitiesFeed } from '../data/cities.js';

const SCROLL_OFFSET = 100;
export const PROGRAMS_DATA_URL = 'https://curtisbuilds.github.io/ea-programs-json/data/programs.json';
const LIST_BATCH_SIZE = 12;

const FALLBACK_PROGRAMS = [
  {
    Title: 'Georgina - Jr Basketball (5 - 7 yrs)',
    TotalPrice: 134,
    StaticPriceText: '134',
    Day: 'Mondays',
    Time: '5:30 - 6:30 PM',
    'Start Date': '2026-07-08',
    'End Date': '2026-08-20',
    SessionDates: '2026-07-08,2026-07-15,2026-07-22,2026-07-29,2026-08-05,2026-08-12,2026-08-20',
    LocationName: 'Langstaff CC',
    RegisterLink: 'https://elevationathletics.ca/register/',
    City: 'Georgina',
    Category: 'TS',
    sport: 'bask',
    is_full: false,
    level: '1',
    MinAge: '8',
    MaxAge: '10',
  },
  {
    Title: 'Georgina - Jr Basketball (8 - 11 yrs)',
    TotalPrice: 134,
    StaticPriceText: '134',
    Day: 'Mondays',
    Time: '5:30 - 6:30 PM',
    'Start Date': '2026-07-08',
    'End Date': '2026-08-20',
    SessionDates: '2026-07-08,2026-07-15,2026-07-22,2026-07-29,2026-08-05,2026-08-12,2026-08-20',
    LocationName: 'Langstaff CC',
    RegisterLink: 'https://elevationathletics.ca/register/',
    City: 'Georgina',
    Category: 'TS',
    sport: 'bask',
    is_full: false,
    level: '1',
    MinAge: '11',
    MaxAge: '13',
  },
  {
    Title: 'Newmarket - Basketball Development Program',
    TotalPrice: 240,
    StaticPriceText: '240',
    Day: 'Mondays',
    Time: '5:30 - 6:30 PM',
    'Start Date': '2026-07-08',
    'End Date': '2026-08-20',
    SessionDates: '2026-07-08,2026-07-15,2026-07-22,2026-07-29,2026-08-05,2026-08-12,2026-08-20',
    LocationName: 'Dr J.M. Dennison',
    RegisterLink: 'https://elevationathletics.ca/register/',
    City: 'Newmarket',
    Category: 'TS',
    sport: 'bask',
    is_full: false,
    level: '2',
    MinAge: '9',
    MaxAge: '18',
  },
];

const norm = (v) => String(v || '').trim().toLowerCase();
const DEFAULT_FILTERS = { search: '', level: '', type: '', age: '', time: '', days: '', location: '' };

function cityKey(p) {
  return norm(p.City || p.city || p.Location || p.location);
}

// Identity fields only - deliberately NO list index. The feed rows carry no id,
// so this composite stands in for one. normalizePrograms turns it into the
// final `_key` and disambiguates any duplicates.
function programIdentity(program) {
  return [
    program.id,
    program.ID,
    program.ProgramID,
    program.ProgramId,
    program.program_id,
    program.slug,
    program.RegisterLink,
    program.Title,
    program.City,
    program.LocationName,
    program.Day,
    program.Time,
    program['Start Date'],
    program['End Date'],
  ].filter((part) => part !== undefined && part !== null && part !== '').join('|');
}

// Free-text search only: strips punctuation and collapses whitespace so
// "st catharines" matches data stored as "St. Catharines". norm() alone is a
// literal substring check where the period is a real character, so
// "st catharines" (no period) is not a substring of "st. catharines" and the
// search silently returns nothing. Kept separate from norm() since that's
// also used for exact-match dropdown comparisons (level/type/location) where
// changing punctuation handling is out of scope for this fix.
const normSearch = (v) => norm(v).replace(/[.,]/g, '').replace(/\s+/g, ' ').trim();

// City search synonyms: some venues sit in a smaller community that most
// people know by a different, more common name (Fonthill is part of the
// town of Welland). Listed both ways so searching either term surfaces
// programs tagged with the other. Add future pairs here rather than
// creating one-off matching logic per city.
const CITY_SEARCH_SYNONYMS = [
  ['fonthill', 'welland'],
  ['bolton', 'caledon'],
];
function citySearchSynonyms(city) {
  const c = normSearch(city);
  const out = [];
  for (const pair of CITY_SEARCH_SYNONYMS) {
    if (pair.includes(c)) out.push(...pair.filter((name) => name !== c));
  }
  return out;
}

const SPORTS = {
  pb: { label: 'Pickleball', aliases: ['pb', 'pickleball', 'pickle'] },
  bad: { label: 'Badminton', aliases: ['bad', 'badm', 'badmin', 'badminton'] },
  bask: { label: 'Basketball', aliases: ['bask', 'basketball', 'bball'] },
  vball: { label: 'Volleyball', aliases: ['vball', 'volleyball', 'volley'] },
  s_camp: { label: 'Sports Camp', aliases: ['s_camp', 'camp', 'camps', 's_camps'] },
};

function parseLocalDate(dateStr) {
  if (!dateStr) return null;
  const parts = String(dateStr).split('-');
  if (parts.length !== 3) {
    const d = new Date(dateStr);
    return isNaN(d) ? null : d;
  }
  const [y, m, d] = parts.map(Number);
  const out = new Date(y, m - 1, d);
  return isNaN(out) ? null : out;
}

function todayStart() {
  const today = new Date();
  return new Date(today.getFullYear(), today.getMonth(), today.getDate());
}

function getStartDate(p) {
  return parseLocalDate(p['Start Date'] || p.StartDate || p.startDate);
}

function getEndDate(p) {
  return parseLocalDate(p['End Date'] || p.EndDate || p.endDate);
}

function isEAorTS(p) {
  const c = String(p.Category || '').trim().toUpperCase();
  return c === 'EA' || c === 'TS';
}

function rowSportKey(p) {
  const s = norm(p.sport || p.Sport || p.SPORT);
  for (const key in SPORTS) {
    if (SPORTS[key].aliases.includes(s)) return key;
  }
  return null;
}

function isFullProgram(p) {
  return p.is_full === true || String(p.is_full).toLowerCase() === 'true';
}

function isEnrollmentOpen(p) {
  const raw = p.enrollment_open ?? p.enrollmentOpen ?? p.EnrollmentOpen ?? p.enrollment_status ?? p.EnrollmentStatus ?? p.status;
  if (raw === undefined || raw === null || raw === '') return true;
  const value = norm(raw);
  return !(value === 'false' || value === 'closed' || value === 'enrollment closed' || value === 'registration closed');
}

function hasRegisterLink(p) {
  const link = String(p.RegisterLink || p.URL || '').trim();
  return Boolean(link) && !/^tbd$/i.test(link);
}

function rawProgramPrice(p) {
  return p.updated_price ?? p.StaticPriceText ?? p.TotalPrice;
}

function hasRealProgramPrice(p) {
  const raw = rawProgramPrice(p);
  if (raw === undefined || raw === null) return false;
  const text = String(raw).trim();
  if (!text || /^tbd$/i.test(text)) return false;
  const normalized = text.replace(/[$,\s]/g, '');
  return normalized !== '' && !Number.isNaN(Number(normalized));
}

function isComingSoonProgram(p) {
  return !hasRegisterLink(p) || !hasRealProgramPrice(p);
}

function isStartingSoon(p, today0 = todayStart()) {
  const start = getStartDate(p);
  return start ? start > today0 : false;
}

function haversineKm(a, b) {
  const R = 6371;
  const toRad = (d) => (d * Math.PI) / 180;
  const dLat = toRad(b[0] - a[0]);
  const dLon = toRad(b[1] - a[1]);
  const h = Math.sin(dLat / 2) ** 2
    + Math.cos(toRad(a[0])) * Math.cos(toRad(b[0])) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}

// Ordering buckets for the list. Reads values derived once in decorateProgram
// rather than re-deriving them on every comparison.
function sortGroupFor(p, allInProgress) {
  const open = p._open;
  const full = p._full;
  if (allInProgress) {
    if (open && !full) return 0;
    if (open && full) return 1;
    if (!open && !full) return 2;
    return 3;
  }
  if (p._startingSoon && open && !full) return 0;
  if (p._inProgress && open && !full) return 1;
  if (p._inProgress && open && full) return 2;
  if (p._inProgress && !open && !full) return 3;
  if (p._inProgress && !open && full) return 4;
  return 5;
}

// Derives every value the sort comparator and the filter need, ONCE per
// program. Previously the comparator called isEnrollmentOpen / isStartingSoon /
// isInProgress / getStartDate / getEndDate on both operands for every one of
// the ~n·log(n) comparisons - roughly 8-12 `new Date()` allocations per
// comparison, several thousand per sort - and filterPrograms rebuilt the search
// haystack and re-ran the label regexes for every program on every keystroke.
function decorateProgram(p, todayMs) {
  const startMs = getStartDate(p)?.getTime() ?? null;
  const endMs = getEndDate(p)?.getTime() ?? null;
  const sportKey = rowSportKey(p);

  const sessionMs = String(p.SessionDates || '')
    .split(',')
    .map((s) => parseLocalDate(s.trim()))
    .filter((d) => d instanceof Date && !isNaN(d))
    .map((d) => d.getTime());

  // Mirrors isActiveProgram: active while at least one session is still ahead,
  // or (with no session list) while the end date has not passed.
  const active = sessionMs.length
    ? sessionMs.some((ms) => ms >= todayMs)
    : (endMs === null || endMs >= todayMs);

  // Mirrors isInProgress / isStartingSoon.
  const inProgress = startMs === null
    ? true
    : (startMs > todayMs ? false : (endMs === null || endMs >= todayMs));

  // Real venue coordinates, so "nearest to you" sorts by the actual
  // school/gym. The old city-only table missed most cities entirely -
  // including "Newmarket/Aurora", which sorted every one of its programs
  // last because it had no coordinate to measure.
  const c = resolveVenueCoords(p);
  const ageRange = programAgeRange(p);

  return {
    ...p,
    coords: c ? [c.lat, c.lng] : null,
    _sportKey: sportKey,
    _active: active,
    _inProgress: inProgress,
    _startingSoon: startMs !== null && startMs > todayMs,
    _open: isEnrollmentOpen(p),
    _full: isFullProgram(p),
    _comingSoon: isComingSoonProgram(p),
    _startMs: startMs,
    _endMs: endMs,
    // `|| 0` / `|| Infinity` preserve the original comparator's null handling.
    _endSort: endMs || 0,
    _startSort: startMs || Infinity,
    _title: String(p.Title || ''),
    _level: norm(levelLabel(p)),
    _type: norm(typeLabel(p)),
    _time: timeBucket(p),
    _day: dayBucket(p),
    _city: cityKey(p),
    _minAge: ageRange.min,
    _maxAge: ageRange.max,
    _haystack: normSearch(
      [p.Title, p.City, p.LocationName, p.Day, p.Time, ...citySearchSynonyms(p.City)]
        .filter(Boolean).join(' ')
    ),
  };
}

function sortPrograms(programs, userCoords) {
  const activePrograms = programs.filter((p) => p._active);
  const allInProgress = activePrograms.length > 0 && activePrograms.every((p) => p._inProgress);

  // Second pass, now that allInProgress is known: stamp the sort group and the
  // distance so the comparator itself is nothing but number comparisons.
  // Distance in particular drops from ~n·log(n) haversine calls to n.
  for (const p of activePrograms) {
    p._group = sortGroupFor(p, allInProgress);
    p._dist = userCoords && p.coords ? haversineKm(userCoords, p.coords) : Infinity;
  }

  return [...activePrograms].sort((a, b) => {
    if (a._comingSoon !== b._comingSoon) return a._comingSoon ? 1 : -1;
    if (userCoords && a._dist !== b._dist) return a._dist - b._dist;
    if (a._group !== b._group) return a._group - b._group;
    if (allInProgress && a._endSort !== b._endSort) return b._endSort - a._endSort;
    if (a._startSort !== b._startSort) return a._startSort - b._startSort;
    return a._title.localeCompare(b._title);
  });
}

export function normalizePrograms(rows, sports, userCoords) {
  const allow = new Set(sports && sports.length ? sports : ['bask']);
  const todayMs = todayStart().getTime();

  const decorated = rows
    .filter((p) => {
      const sportKey = rowSportKey(p);
      return p && sportKey && allow.has(sportKey) && isEAorTS(p) && p.City && !p.is_cancelled;
    })
    .map((p) => decorateProgram(p, todayMs));

  // Stable per-program React key, assigned here rather than at render time.
  // The old key included the program's index in the *filtered* list, so
  // filtering reshuffled indices and forced React to remount cards that had
  // not actually changed. Identity fields only, with an occurrence counter to
  // break ties between genuinely identical rows.
  const seen = new Map();
  for (const p of decorated) {
    const base = programIdentity(p);
    const n = seen.get(base) || 0;
    seen.set(base, n + 1);
    p._key = n ? `${base}#${n}` : base;
  }

  return sortPrograms(decorated, userCoords);
}

// Returns { rows, status } where status is 'loading' | 'ready' | 'error'.
// Loading and error MUST be distinguishable: when both were represented by
// rows === null the page rendered the "No programs match those filters."
// empty state while the feed was still in flight, so every visitor saw
// "no programs" first and the real list second.
export function useProgramsFeed() {
  const [state, setState] = useState({ rows: null, status: 'loading' });
  useEffect(() => {
    let alive = true;
    // No cache-buster here on purpose. The feed already serves
    // `cache-control: max-age=600` plus a strong ETag; appending
    // `?v=${Date.now()}` made every URL unique, which defeated both the browser
    // cache and the CDN edge and forced a full ~189 KB re-download on every
    // single page view.
    fetch(PROGRAMS_DATA_URL)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error('bad response'))))
      .then((data) => { if (alive) setState({ rows: Array.isArray(data) ? data : [], status: 'ready' }); })
      .catch(() => { if (alive) setState({ rows: null, status: 'error' }); });
    return () => { alive = false; };
  }, []);
  return state;
}

function formatProgramDate(dateStr, opts = {}) {
  const d = parseLocalDate(dateStr);
  if (!d) return '';
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', ...opts });
}

function formatDateRange(p) {
  const start = formatProgramDate(p['Start Date'] || p.StartDate || p.startDate);
  const end = formatProgramDate(p['End Date'] || p.EndDate || p.endDate);
  if (start && end) return `${start} - ${end}`;
  return start || end || '';
}

function sessionCount(p) {
  return String(p.SessionDates || '').split(',').map((s) => s.trim()).filter(Boolean).length;
}

function displayPrice(p) {
  const raw = p.updated_price || p.StaticPriceText || p.TotalPrice;
  if (raw === undefined || raw === null || raw === '') return '';
  const num = Number(raw);
  if (!Number.isNaN(num)) return `$${num % 1 === 0 ? num.toFixed(0) : num.toFixed(2)}`;
  return `$${String(raw).replace(/^\$/, '')}`;
}

function numericLevel(p) {
  const raw = p.level ?? p.Level ?? p.LEVEL;
  if (raw === undefined || raw === null || raw === '') return null;
  const match = String(raw).match(/\d+/);
  return match ? Number(match[0]) : null;
}

function levelLabelFromNumber(p) {
  const level = numericLevel(p);
  if (level === null) return null;
  if (level <= 1) return 'Beginner';
  if (level === 2) return 'Experienced Beginner';
  if (level === 3) return 'Intermediate';
  if (level >= 4) return 'Advanced';
  return null;
}

function levelLabel(p) {
  const levelFromJson = levelLabelFromNumber(p);
  if (levelFromJson) return levelFromJson;

  const text = `${p.Title || ''} ${p.level || ''}`.toLowerCase();
  if (text.includes('advanced')) return 'Advanced';
  if (text.includes('intermediate')) return 'Intermediate';
  if (text.includes('experienced beginner')) return 'Experienced Beginner';
  return 'Beginner';
}

function typeLabel(p) {
  const title = String(p.Title || '').toLowerCase();
  if (title.includes('camp')) return 'Camps';
  if (title.includes('league')) return 'Leagues';
  return 'Lessons';
}

export function statusLabels(p) {
  if (isComingSoonProgram(p)) {
    const labels = ['Coming Soon'];
    const level = levelLabel(p);
    const type = typeLabel(p);
    if (level) labels.push(level);
    if (type) labels.push(type);
    return labels;
  }

  const labels = [
    isEnrollmentOpen(p) ? 'Enrollment Open' : 'Enrollment Closed',
    isStartingSoon(p) ? 'Starting Soon' : 'In Progress',
    levelLabel(p),
    typeLabel(p),
  ];
  if (isFullProgram(p)) labels.push('Full');
  return labels;
}

export function chipStyle(label) {
  const key = norm(label);
  if (key.includes('open')) return { bg: '#CFF6D9', color: '#287545' };
  if (key.includes('closed') || key === 'full') return { bg: '#ECEFF1', color: '#66757B' };
  if (key.includes('coming soon')) return { bg: '#FFF1E7', color: '#A85B1F', border: '1px solid #FFD7BF' };
  if (key.includes('starting')) return { bg: '#FFE9AF', color: '#8A640F' };
  if (key.includes('progress')) return { bg: '#D7F1FF', color: '#206A87' };
  if (key.includes('experienced beginner')) return { bg: '#A0E4F2', color: '#0B5364' };
  if (key.includes('beginner')) return { bg: '#BDEEFF', color: '#0B5B73' };
  if (key.includes('intermediate')) return { bg: '#73D3E8', color: '#0B4F63' };
  if (key.includes('advanced')) return { bg: '#0B5B73', color: '#FFFFFF' };
  if (key.includes('camp')) return { bg: '#FFBB91', color: '#0077A3' };
  if (key.includes('league')) return { bg: '#F1ECFF', color: '#55438F', border: '1px solid #D8CCFF' };
  if (key.includes('lesson')) return { bg: '#FFFFFF', color: '#0B5B73', border: '1px solid #0B5B73' };
  return { bg: '#BDEEFF', color: '#0B5B73' };
}

export function ProgramChip({ label }) {
  const styles = chipStyle(label);
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', width: 'fit-content',
      padding: '4px 7px', borderRadius: 6,
      background: styles.bg, color: styles.color,
      border: styles.border || '1px solid transparent',
      fontFamily: 'var(--font-body)', fontSize: 14, fontWeight: 'var(--fw-medium)',
      textTransform: 'none', lineHeight: 1.1,
    }}>
      {label}
    </span>
  );
}

function cleanProgramTitle(p) {
  return String(p.Title || 'Basketball Program')
    .trim()
    .replace(/\s*-\s*/g, ' – ')
    .replace(/\((\d+\s*[-–]\s*\d+)\)/g, '($1 yrs)')
    .replace(/\byrs yrs\b/i, 'yrs');
}

function programMetaLine(p) {
  const count = sessionCount(p);
  return [
    count ? `${count} Session${count === 1 ? '' : 's'}` : '',
    p.Day,
    formatDateRange(p),
    p.Time,
    p.LocationName,
  ].filter(Boolean).join(' · ');
}

function MailIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ flex: 'none' }}>
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="m3 7 9 6 9-6" />
    </svg>
  );
}

export function ProgramSubscribeButton({ city, province = '', sessionStart = '', programSummary = '', isMobile = false, onSubscribe }) {
  const [hover, setHover] = useState(false);
  return (
    <button
      type="button"
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      onClick={(e) => {
        e.preventDefault();
        e.stopPropagation();
        if (onSubscribe) onSubscribe({ city, province, sessionStart, programSummary });
      }}
      style={{
        display: 'inline-flex', alignItems: 'center', gap: 8,
        // Capped at the card width so the expanded text can't spill out of the
        // narrower map/calendar panels; the label ellipsizes instead.
        width: 'fit-content', maxWidth: '100%', padding: '5px 10px', borderRadius: 6,
        fontFamily: 'var(--font-body)', fontSize: isMobile ? 13 : 14, fontWeight: 'var(--fw-medium)',
        textTransform: 'none', border: 'none', cursor: 'pointer', whiteSpace: 'nowrap',
        background: '#F9F4FF', color: '#6F677B',
      }}
    >
      <span style={{ display: 'inline-flex', alignItems: 'center', lineHeight: 1, flex: 'none' }}>
        <MailIcon />
      </span>
      <span style={{ display: 'inline-flex', alignItems: 'center', lineHeight: 1.1, whiteSpace: 'nowrap', minWidth: 0, overflow: 'hidden' }}>
        <span style={{ flex: 'none' }}>Subscribe</span>
        <span style={{ display: 'inline-block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', minWidth: 0, maxWidth: hover ? 420 : 0, opacity: hover ? 1 : 0, transition: 'max-width .3s ease, opacity .3s ease' }}>
          &nbsp;to {city}{isMobile ? '' : '’s Newsletter'}
        </span>
      </span>
    </button>
  );
}

export function siteSport(t) {
  return (t.defaults && t.defaults.sport) || 'Basketball';
}

function sportBrand(t) {
  return `EA ${siteSport(t)}`;
}

function firstSessionDate(p) {
  return String(p.SessionDates || '').split(',')[0]?.trim()
    || String(p['Start Date'] || p.StartDate || p.startDate || '').trim()
    || '';
}

function programSummaryLine(p) {
  return [p.Title, p.LocationName, [p.Day, p.Time].filter(Boolean).join(' '), formatDateRange(p)]
    .filter(Boolean)
    .join(' - ');
}

export function ProgramCard({ program, isMobile, onSubscribe, stacked = false, t, sportOverride = '' }) {
  const sport = sportOverride || siteSport(t);
  const city = String(program.City || '').trim() || `General ${sport}`;
  const province = String(program.Province || '').trim();
  const sessionStart = firstSessionDate(program);
  const programSummary = programSummaryLine(program);
  const full = isFullProgram(program);
  const enrollmentOpen = isEnrollmentOpen(program);
  const comingSoon = isComingSoonProgram(program);
  const registerHref = enrollmentOpen && !comingSoon
    ? (program.RegisterLink || program.URL || `${t.siteUrl || ''}/signup/`)
    : `mailto:info@elevationathletics.ca?subject=${encodeURIComponent(`${sport} program enrollment`)}`;
  const cta = comingSoon ? 'Coming Soon' : !enrollmentOpen ? 'Email Us' : full ? 'Join Waitlist' : 'Register';
  const price = displayPrice(program);
  const meta = programMetaLine(program);

  const priceStacked = price && (
    <div style={{ textAlign: stacked ? 'left' : (isMobile ? 'left' : 'right'), color: 'var(--ea-teal-800, #0B5364)', lineHeight: 1 }}>
      <div style={{ fontFamily: 'var(--font-body)', fontSize: isMobile ? 28 : 30, fontWeight: 'var(--fw-bold)' }}>{price}</div>
    </div>
  );
  const registerStyle = {
      display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
      minWidth: isMobile ? 108 : 126,
      padding: '12px 18px',
      borderRadius: 7,
      background: comingSoon || full || !enrollmentOpen ? '#F9F4FF' : '#0A98D6',
      color: comingSoon || full || !enrollmentOpen ? '#6F677B' : '#fff',
      textDecoration: 'none',
      fontFamily: 'var(--font-body)', fontSize: 16, fontWeight: 'var(--fw-bold)',
  };
  const registerLink = comingSoon ? (
    <span aria-disabled="true" style={{ ...registerStyle, cursor: 'not-allowed' }}>
      {cta}
    </span>
  ) : (
    <a href={registerHref} target={registerHref.startsWith('mailto:') ? undefined : '_blank'} rel={registerHref.startsWith('mailto:') ? undefined : 'noopener noreferrer'} style={registerStyle}>
      {cta}
    </a>
  );

  return (
    <article style={{
      display: 'grid',
      gridTemplateColumns: (isMobile || stacked) ? '1fr' : '1fr 138px',
      gap: (isMobile || stacked) ? 14 : 20,
      alignItems: stacked ? 'start' : 'center',
      padding: isMobile ? '18px 20px' : '18px 24px',
      border: '1px solid var(--border-card, #E5E5E5)',
      borderRadius: 8,
      background: '#fff',
      boxShadow: '0 1px 4px rgba(16,65,79,.04)',
      fontFamily: 'var(--font-body)',
    }}>
      <div style={{ minWidth: 0 }}>
        <h3 style={{
          margin: 0,
          fontFamily: 'var(--font-body)',
          fontWeight: 'var(--fw-bold)',
          fontSize: isMobile ? 19 : 22,
          lineHeight: 1.18,
          letterSpacing: 'var(--ls-body)',
          textTransform: 'none',
          color: 'var(--ea-teal-800, #0B5364)',
        }}>
          {cleanProgramTitle(program)}
        </h3>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 12 }}>
          {statusLabels(program).map((label) => <ProgramChip key={label} label={label} />)}
        </div>
        {meta && (
          <p style={{ margin: '12px 0 0', fontFamily: 'var(--font-body)', fontSize: isMobile ? 14 : 15, color: 'var(--ea-slate, #47636B)', lineHeight: 1.45 }}>
            {meta}
          </p>
        )}
        {onSubscribe && (
          <div style={{ marginTop: 10 }}>
            <ProgramSubscribeButton city={city} province={province} sessionStart={sessionStart} programSummary={programSummary} isMobile={isMobile} onSubscribe={onSubscribe} />
          </div>
        )}
      </div>
      {stacked ? (
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 14 }}>
          {priceStacked}
          {registerLink}
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: isMobile ? 'row' : 'column', alignItems: isMobile ? 'center' : 'flex-end', justifyContent: isMobile ? 'space-between' : 'center', gap: 14 }}>
          {priceStacked}
          {registerLink}
        </div>
      )}
    </article>
  );
}

export function citySlug(city) {
  return norm(city).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}

function programProvince(program) {
  return String(program.Province || program.province || '').trim();
}

export function cityDisplayName(city, province) {
  return [city, province].filter(Boolean).join(', ');
}

function cityRecordCoords(city) {
  if (!city) return null;
  const lat = Number(city.lat ?? city.Lat ?? city.latitude ?? city.Latitude);
  const lng = Number(city.lng ?? city.Lng ?? city.longitude ?? city.Longitude);
  return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : null;
}

function cityTypeCounts(programs) {
  return programs.reduce((acc, program) => {
    const type = norm(typeLabel(program));
    if (type.includes('league')) acc.leagues += 1;
    else if (type.includes('camp')) acc.camps += 1;
    else acc.lessons += 1;
    return acc;
  }, { lessons: 0, leagues: 0, camps: 0 });
}

export function cityStatus(summary) {
  if (!summary.programCount) return 'Inactive';
  if (summary.availableCount === 0) return 'Coming Soon';
  if (summary.allFull) return 'Full - Join Waitlist';
  if (summary.allClosed) return 'Enrollment Closed';
  if (summary.startingSoonCount > 0) return 'Starting Soon';
  if (summary.inProgressCount > 0) return 'In Progress';
  return 'Enrollment Open';
}

export function cityStatusChips(summary) {
  const activeCount = summary.availableCount || summary.programCount;
  const countLabel = `${activeCount} Active Program${activeCount === 1 ? '' : 's'}`;
  const chips = [countLabel];

  if (activeCount > 0 && !summary.allClosed) chips.push('Enrollment Open');
  if (summary.startingSoonCount > 0) chips.push('Starting Soon');

  if (chips.length === 1) chips.push(cityStatus(summary));

  return chips;
}

export function buildCitySummaries(programs, userCoords, includeInactive = false, cityCoords = null) {
  const byCity = new Map();
  programs.forEach((program) => {
    const city = String(program.City || '').trim();
    const key = cityKey(program);
    if (!city || !key) return;
    if (!byCity.has(key)) {
      byCity.set(key, {
        key,
        slug: citySlug(city),
        city,
        province: programProvince(program),
        coords: cityCoords?.get(key) || program.coords || null,
        programs: [],
      });
    }
    const summary = byCity.get(key);
    if (!summary.province) summary.province = programProvince(program);
    if (!summary.coords) summary.coords = cityCoords?.get(key) || program.coords || null;
    summary.programs.push(program);
  });

  return [...byCity.values()]
    .map((summary) => {
      const programsForCity = summary.programs;
      const availablePrograms = programsForCity.filter((program) => !program._comingSoon);
      const nextProgram = programsForCity
        .map((program) => ({ program, start: program._startMs ?? Infinity }))
        .sort((a, b) => a.start - b.start)[0]?.program || null;
      const distance = userCoords && summary.coords ? haversineKm(userCoords, summary.coords) : Infinity;
      const typeCounts = cityTypeCounts(programsForCity);
      const allFull = availablePrograms.length > 0 && availablePrograms.every((program) => program._full);
      const allClosed = availablePrograms.length > 0 && availablePrograms.every((program) => !program._open);
      const inactive = availablePrograms.length === 0;

      return {
        ...summary,
        displayName: cityDisplayName(summary.city, summary.province),
        programCount: programsForCity.length,
        availableCount: availablePrograms.length,
        startingSoonCount: availablePrograms.filter((program) => program._startingSoon).length,
        inProgressCount: availablePrograms.filter((program) => program._inProgress).length,
        fullCount: availablePrograms.filter((program) => program._full).length,
        allFull,
        allClosed,
        inactive,
        typeCounts,
        nextProgram,
        nextStart: nextProgram ? nextProgram._startMs ?? Infinity : Infinity,
        distance,
      };
    })
    .filter((summary) => includeInactive || !summary.inactive)
    .sort((a, b) => {
      if (userCoords && a.distance !== b.distance) return a.distance - b.distance;
      if (a.inactive !== b.inactive) return a.inactive ? 1 : -1;
      if (a.nextStart !== b.nextStart) return a.nextStart - b.nextStart;
      return a.displayName.localeCompare(b.displayName);
    });
}

export function LeagueCityCard({ summary, isMobile = false, onSubscribe, t, href }) {
  const [hover, setHover] = useState(false);
  const statusChips = cityStatusChips(summary);
  const cityUrl = href || `${t.siteUrl || ''}/programs/?city=${encodeURIComponent(summary.key)}`;
  const detailItems = [
    summary.typeCounts.lessons ? `${summary.typeCounts.lessons} Lesson${summary.typeCounts.lessons === 1 ? '' : 's'}` : '',
    summary.typeCounts.leagues ? `${summary.typeCounts.leagues} League${summary.typeCounts.leagues === 1 ? '' : 's'}` : '',
    summary.typeCounts.camps ? `${summary.typeCounts.camps} Camp${summary.typeCounts.camps === 1 ? '' : 's'}` : '',
  ].filter(Boolean);

  return (
    <article
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
      position: 'relative',
      display: 'grid',
      gap: isMobile ? 14 : 16,
      alignItems: 'start',
      background: '#fff',
      border: `1px solid ${hover ? 'rgba(10, 152, 214, 0.35)' : 'var(--border-card, #E5E5E5)'}`,
      borderRadius: 8,
      boxShadow: hover ? '0 4px 18px rgba(16,65,79,.07)' : '0 1px 4px rgba(16,65,79,.04)',
      padding: isMobile ? '18px 20px' : '22px 24px',
      fontFamily: 'var(--font-body)',
      transition: 'border-color .15s ease, box-shadow .15s ease',
      cursor: 'pointer',
    }}>
      <a
        href={cityUrl}
        aria-label={`View programs in ${summary.displayName}`}
        style={{
          position: 'absolute',
          inset: 0,
          zIndex: 1,
          borderRadius: 8,
          textDecoration: 'none',
        }}
      />
      <div style={{ minWidth: 0 }}>
        <h3 style={{
          margin: 0,
          fontFamily: 'var(--font-body)',
          fontWeight: 'var(--fw-bold)',
          fontSize: isMobile ? 20 : 22,
          lineHeight: 1.18,
          letterSpacing: 'var(--ls-body)',
          textTransform: 'none',
          color: 'var(--ea-teal-800, #0B5364)',
        }}>
          {summary.displayName}
        </h3>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 12 }}>
          {statusChips.map((label) => <ProgramChip key={label} label={label} />)}
        </div>
        <p style={{
          margin: '10px 0 0',
          fontFamily: 'var(--font-body)',
          fontSize: isMobile ? 14 : 15,
          color: 'var(--ea-slate, #47636B)',
          lineHeight: 1.35,
        }}>
          {detailItems.length ? detailItems.join(' · ') : 'Programs available'}
        </p>
      </div>
      <div style={{
        display: 'flex',
        alignItems: 'center',
        gap: 12,
        marginTop: 2,
      }}>
        <div style={{ position: 'relative', zIndex: 2, display: 'inline-flex', minWidth: 0, overflow: 'visible' }}>
          <ProgramSubscribeButton
            city={summary.city}
            province={summary.province}
            sessionStart={summary.nextProgram ? firstSessionDate(summary.nextProgram) : ''}
            programSummary={summary.nextProgram ? programSummaryLine(summary.nextProgram) : `${summary.displayName} programs`}
            isMobile={isMobile}
            onSubscribe={onSubscribe}
          />
        </div>
      </div>
    </article>
  );
}

export function NewsletterModal({ DS, t, location, onClose }) {
  const { Button } = DS;
  const [email, setEmail] = useState('');
  const [website, setWebsite] = useState('');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  const [submitted, setSubmitted] = useState(false);
  const city = location && typeof location === 'object' ? location.city : location;
  const province = location && typeof location === 'object' ? location.province : '';
  const sessionStart = location && typeof location === 'object' ? location.sessionStart : '';
  const programSummary = location && typeof location === 'object' ? location.programSummary : '';
  const brand = sportBrand(t);
  const subscriptionLabel = city ? `${brand} ${city}` : `the ${brand} Newsletter`;

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setError('Please enter a valid email address.');
      return;
    }
    setSending(true);
    try {
      const res = await fetch(`${t.apiUrl}ea/v1/newsletter`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': t.nonce },
        body: JSON.stringify({ email, location: city, province, sessionStart, programSummary, website }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data && data.message ? data.message : 'Something went wrong. Please try again.');
      setSubmitted(true);
    } catch (err) {
      setError(err.message || 'Something went wrong. Please try again.');
    } finally {
      setSending(false);
    }
  };

  const inputStyle = {
    width: '100%', boxSizing: 'border-box', padding: '12px 16px',
    border: '1px solid var(--border-card, #E5E5E5)', borderRadius: 8,
    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: 16,
    color: 'var(--ea-ink, #1E526E)', background: '#fff',
  };

  return (
    <div role="dialog" aria-modal="true" onClick={onClose} style={{ position: 'fixed', inset: 0, zIndex: 1000, background: 'rgba(16,65,79,.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
      <div onClick={(e) => e.stopPropagation()} style={{ position: 'relative', background: '#fff', borderRadius: 12, padding: '44px 32px 36px', maxWidth: 420, width: '100%', textAlign: 'center', boxShadow: '0 12px 40px rgba(16,65,79,.25)' }}>
        <button onClick={onClose} aria-label="Close" style={{ position: 'absolute', top: 12, right: 12, background: 'none', border: 'none', cursor: 'pointer', color: 'var(--ea-navy, #10414F)', display: 'inline-flex', padding: 4 }}>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
        </button>
        {submitted ? (
          <>
            <h3 style={{ ...FB.h(28), fontWeight: 'var(--fw-regular, 400)', margin: '0 0 12px' }}>Thank you!</h3>
            <p style={{ fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: 16, color: 'var(--ea-ink, #1E526E)', lineHeight: 1.5, margin: 0 }}>
              You're subscribed for <strong>{subscriptionLabel}</strong>.
            </p>
            <div style={{ marginTop: 24, display: 'flex', justifyContent: 'center' }}>
              {Button ? <Button variant="dark" onClick={onClose}>Close</Button> : <button onClick={onClose} style={{ ...FB.btn('primary'), background: 'var(--ea-teal-900, #004356)' }}>Close</button>}
            </div>
          </>
        ) : (
          <form onSubmit={handleSubmit}>
            <h3 style={{ ...FB.h(28), fontWeight: 'var(--fw-regular, 400)', margin: '0 0 8px' }}>{t.texts.newsletterHeading || 'Join Our Newsletter!'}</h3>
            <p style={{ fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: 16, color: 'var(--ea-ink, #1E526E)', lineHeight: 1.5, margin: '0 0 20px' }}>
              Subscribing to <strong>{subscriptionLabel}</strong>.
            </p>
            <input type="email" placeholder="Your Email" value={email} autoFocus onChange={(e) => setEmail(e.target.value)} style={inputStyle} />
            <div aria-hidden="true" style={{ position: 'absolute', left: '-9999px', width: 1, height: 1, overflow: 'hidden' }}>
              <label htmlFor="lh-nl-website">Website</label>
              <input id="lh-nl-website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
            </div>
            {error && <p role="alert" style={{ marginTop: 12, marginBottom: 0, fontFamily: 'var(--font-body, sans-serif)', fontSize: 14, color: 'var(--ea-error, #C0392B)' }}>{error}</p>}
            <div style={{ marginTop: 20, display: 'flex', justifyContent: 'center' }}>
              {Button ? <Button variant="dark" type="submit" disabled={sending}>{sending ? 'Subscribing...' : (t.texts.newsletterSubscribe || 'Subscribe')}</Button> : <button type="submit" disabled={sending} style={{ ...FB.btn('primary'), background: 'var(--ea-teal-900, #004356)', opacity: sending ? 0.7 : 1 }}>{sending ? 'Subscribing...' : (t.texts.newsletterSubscribe || 'Subscribe')}</button>}
            </div>
          </form>
        )}
      </div>
    </div>
  );
}

function SearchIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4A98C8" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="11" cy="11" r="7" />
      <path d="m20 20-3.5-3.5" />
    </svg>
  );
}

function FilterIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4A98C8" strokeWidth="2.3" strokeLinecap="round" strokeLinejoin="round">
      <path d="M22 3H2l8 9.4V19l4 2v-8.6L22 3Z" />
    </svg>
  );
}

function SelectChip({ label, value, onChange, options, filterKey }) {
  return (
    <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
      <span style={{ position: 'absolute', left: '-9999px' }}>{label}</span>
      <select
        value={value}
        data-league-filter={filterKey}
        autoComplete="off"
        onInput={(e) => onChange(e.currentTarget.value)}
        onChange={(e) => onChange(e.currentTarget.value)}
        style={{
        appearance: 'none',
        border: 'none',
        borderRadius: 7,
        background: '#F1F4FA',
        color: '#405C66',
        padding: '5px 28px 5px 9px',
        fontFamily: 'var(--font-body)',
        fontSize: 14,
        fontWeight: 'var(--fw-medium)',
        backgroundImage: 'linear-gradient(45deg, transparent 50%, #405C66 50%), linear-gradient(135deg, #405C66 50%, transparent 50%)',
        backgroundPosition: 'calc(100% - 13px) 10px, calc(100% - 8px) 10px',
        backgroundSize: '5px 5px, 5px 5px',
        backgroundRepeat: 'no-repeat',
      }}>
        <option value="">{label}: All</option>
        {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
      </select>
    </label>
  );
}

function startHour(p) {
  const match = String(p.Time || '').match(/(\d{1,2})(?::(\d{2}))?\s*(AM|PM)/i);
  if (!match) return null;
  let hour = Number(match[1]);
  const meridiem = match[3].toUpperCase();
  if (meridiem === 'PM' && hour !== 12) hour += 12;
  if (meridiem === 'AM' && hour === 12) hour = 0;
  return hour;
}

function dayBucket(p) {
  const d = norm(p.Day);
  if (d.includes('sat') || d.includes('sun')) return 'weekends';
  return 'weekdays';
}

function timeBucket(p) {
  const hour = startHour(p);
  if (hour == null) return '';
  if (hour < 12) return 'morning';
  if (hour < 17) return 'afternoon';
  return 'evening';
}

function programAgeRange(p) {
  const min = Number(p.MinAge || p.age || 5);
  const max = Number(p.MaxAge || p.age || 18);
  return {
    min: Number.isNaN(min) ? 5 : min,
    max: Number.isNaN(max) ? 18 : max,
  };
}


// Reads only values precomputed by decorateProgram, so a keystroke costs one
// substring test per program instead of rebuilding the haystack and re-running
// the level/type/time/day derivations for all of them.
function filterPrograms(programs, filters) {
  const q = normSearch(filters.search);
  const selectedLocation = norm(filters.location);
  const age = filters.age ? Number(filters.age) : NaN;
  const hasAge = !Number.isNaN(age);
  return programs.filter((p) => {
    if (q && !p._haystack.includes(q)) return false;
    if (filters.level && p._level !== filters.level) return false;
    if (filters.type && p._type !== filters.type) return false;
    if (hasAge && (age < p._minAge || age > p._maxAge)) return false;
    if (filters.time && p._time !== filters.time) return false;
    if (filters.days && p._day !== filters.days) return false;
    if (selectedLocation && p._city !== selectedLocation) return false;
    return true;
  });
}

function LeagueHubFilters({
  filters,
  setFilters,
  locationFilter,
  onLocationChange,
  provinceFilter,
  onProvinceChange,
  onFilterChange,
  onClearFilters,
  provinces,
  options,
  isMobile,
  showInactiveCities,
  onShowInactiveCitiesChange,
}) {
  const show = (key) => options[key] !== false;
  const update = (key) => (value) => {
    if (key === 'location') {
      onLocationChange(value);
      if (onFilterChange) onFilterChange(key, value);
      return;
    }

    setFilters((current) => (
      { ...current, [key]: value }
    ));
    if (onFilterChange) onFilterChange(key, value);
  };
  const clear = () => {
    setFilters({ ...DEFAULT_FILTERS });
    onLocationChange('');
    onProvinceChange('');
    onShowInactiveCitiesChange(false);
    if (onClearFilters) onClearFilters();
  };

  return (
    <div style={{ display: 'grid', gap: 10, marginTop: isMobile ? 16 : 20 }}>
      {show('leagueHubFilterSearch') && (
        <label style={{ position: 'relative', display: 'block' }}>
          <span style={{ position: 'absolute', left: 14, top: '50%', transform: 'translateY(-50%)', display: 'inline-flex' }}><SearchIcon /></span>
          <input value={filters.search} onChange={(e) => update('search')(e.target.value)} placeholder="Search" style={{
            width: '100%',
            boxSizing: 'border-box',
            border: '1px solid var(--border-card, #E5E5E5)',
            borderRadius: 8,
            padding: '13px 16px 13px 44px',
            fontFamily: 'var(--font-body)',
            fontSize: 16,
            color: 'var(--ea-ink, #1E526E)',
            background: '#fff',
          }} />
        </label>
      )}
      <div style={{
        display: 'flex',
        alignItems: isMobile ? 'flex-start' : 'center',
        justifyContent: 'space-between',
        gap: 10,
        border: '1px solid var(--border-card, #E5E5E5)',
        borderRadius: 8,
        padding: isMobile ? '10px 12px' : '10px 16px',
        background: '#fff',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', flex: '1 1 auto', minWidth: 0 }}>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7, fontFamily: 'var(--font-body)', color: 'var(--ea-slate, #47636B)', fontSize: 15 }}>
            <FilterIcon /> Filter By:
          </span>
          {show('leagueHubFilterProvince') && (
            <SelectChip filterKey="province" label="Province" value={provinceFilter} onChange={(value) => {
              onProvinceChange(value);
              if (onFilterChange) onFilterChange('province', value);
            }} options={provinces.map((province) => ({ value: norm(province), label: province }))} />
          )}
          {show('leagueHubShowInactiveToggle') && (
            <label style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 7,
              minHeight: 28,
              padding: '5px 9px',
              borderRadius: 7,
              background: '#F1F4FA',
              color: '#405C66',
              fontFamily: 'var(--font-body)',
              fontSize: 14,
              fontWeight: 'var(--fw-medium)',
              cursor: 'pointer',
            }}>
              <input
                type="checkbox"
                checked={showInactiveCities}
                onChange={(event) => onShowInactiveCitiesChange(event.currentTarget.checked)}
                style={{ margin: 0 }}
              />
              Show inactive cities
            </label>
          )}
        </div>
        <button type="button" onClick={clear} style={{ flex: '0 0 auto', alignSelf: isMobile ? 'flex-end' : 'center', border: 'none', background: 'transparent', color: '#2E91C8', fontFamily: 'var(--font-body)', fontSize: 14, cursor: 'pointer', padding: isMobile ? '6px 0 0' : 0 }}>
          Clear Filters
        </button>
      </div>
    </div>
  );
}

export default function LeagueHubPage() {
  const DS = useDSComponents();
  const { isMobile } = useViewport();
  const t = getThemeData();
  const { rows, status: feedStatus } = useProgramsFeed();
  const cityRecords = useCitiesFeed();
  const [userCoords, setUserCoords] = useState(null);
  const [locating, setLocating] = useState(false);
  const [geoError, setGeoError] = useState('');
  const [subscribeLoc, setSubscribeLoc] = useState(null);
  const [filters, setFilters] = useState({ ...DEFAULT_FILTERS });
  const [locationFilter, setLocationFilter] = useState(() => {
    if (typeof window === 'undefined') return '';
    const params = new URLSearchParams(window.location.search);
    return norm(params.get('city') || '');
  });
  const [provinceFilter, setProvinceFilter] = useState('');
  const [showInactiveCities, setShowInactiveCities] = useState(false);
  const [view, setView] = useState('list');
  const [visibleCount, setVisibleCount] = useState(LIST_BATCH_SIZE);
  const showMapView = !(t.options && t.options.leagueHubShowMapView === false);
  const showCalendarView = !(t.options && t.options.leagueHubShowCalendarView === false);
  const showComingSoon = t.options && t.options.leagueHubShowComingSoon === true;

  const selectedSports = ['bask'];

  useEffect(() => {
    const optionKeyByFilter = {
      search: 'leagueHubFilterSearch',
      level: 'leagueHubFilterLevel',
      type: 'leagueHubFilterType',
      age: 'leagueHubFilterAge',
      time: 'leagueHubFilterTime',
      days: 'leagueHubFilterDays',
      location: 'leagueHubFilterLocation',
    };

    setFilters((current) => {
      let changed = false;
      const next = { ...current };
      Object.entries(optionKeyByFilter).forEach(([filterKey, optionKey]) => {
        if (t.options && t.options[optionKey] === false && next[filterKey]) {
          next[filterKey] = '';
          changed = true;
        }
      });
      return changed ? next : current;
    });

    if (t.options && t.options.leagueHubFilterLocation === false && locationFilter) {
      setLocationFilter('');
    }
    if (t.options && t.options.leagueHubFilterProvince === false && provinceFilter) {
      setProvinceFilter('');
    }
    if (t.options && t.options.leagueHubShowInactiveToggle === false && showInactiveCities) {
      setShowInactiveCities(false);
    }
  }, [t.options, locationFilter, provinceFilter, showInactiveCities]);

  const allPrograms = useMemo(
    () => normalizePrograms(rows || FALLBACK_PROGRAMS, selectedSports, userCoords),
    [rows, selectedSports, userCoords]
  );
  const programs = useMemo(
    () => (showComingSoon ? allPrograms : allPrograms.filter((program) => !program._comingSoon)),
    [allPrograms, showComingSoon]
  );
  const cityCoords = useMemo(() => {
    const byKey = new Map();
    cityRecords.forEach((city) => {
      const key = norm(city?.City || city?.city);
      const coords = cityRecordCoords(city);
      if (key && coords) byKey.set(key, coords);
    });
    return byKey;
  }, [cityRecords]);
  const citySourcePrograms = showInactiveCities ? allPrograms : programs;
  const filteredPrograms = useMemo(() => (
    locationFilter
      ? citySourcePrograms.filter((program) => program._city === locationFilter)
      : filterPrograms(citySourcePrograms, filters)
  ), [citySourcePrograms, filters, locationFilter]);
  const filteredCitySummaries = useMemo(
    () => buildCitySummaries(filteredPrograms, userCoords, showInactiveCities, cityCoords).filter((summary) => (
      !provinceFilter || norm(summary.province) === provinceFilter
    )),
    [filteredPrograms, userCoords, showInactiveCities, provinceFilter, cityCoords]
  );
  const visibleCitySummaries = useMemo(
    () => filteredCitySummaries.slice(0, visibleCount),
    [filteredCitySummaries, visibleCount]
  );
  const hasMoreListCities = view === 'list' && visibleCount < filteredCitySummaries.length;
  const listRenderKey = [
    locationFilter || 'all-locations',
    filters.search,
    filters.level,
    filters.type,
    filters.age,
    filters.time,
    filters.days,
    provinceFilter || 'all-provinces',
    userCoords ? 'near-me' : 'default-sort',
    showComingSoon ? 'with-coming-soon' : 'without-coming-soon',
    showInactiveCities ? 'show-inactive-cities' : 'hide-inactive-cities',
  ].join('|');
  const cities = useMemo(() => {
    const byKey = new Map();
    allPrograms.forEach((p) => {
      const label = String(p.City || '').trim();
      const key = cityKey(p);
      if (label && key && !byKey.has(key)) byKey.set(key, label);
    });
    return [...byKey.values()].sort((a, b) => a.localeCompare(b));
  }, [allPrograms]);
  const provinces = useMemo(() => {
    const byKey = new Map();
    allPrograms.forEach((program) => {
      const province = programProvince(program);
      const key = norm(province);
      if (province && key && !byKey.has(key)) byKey.set(key, province);
    });
    return [...byKey.values()].sort((a, b) => a.localeCompare(b));
  }, [allPrograms]);

  const resetFiltersAndSort = () => {
    setUserCoords(null);
    setGeoError('');
    setLocating(false);
  };

  const handleFilterChange = (_key, value) => {
    setUserCoords(null);
    setGeoError('');
    setLocating(false);
    if (!value) return;
  };

  const handleLocationChange = (value) => {
    setLocationFilter(norm(value));
    setFilters({ ...DEFAULT_FILTERS });
    setUserCoords(null);
    setGeoError('');
    setLocating(false);
  };

  useEffect(() => {
    setVisibleCount(LIST_BATCH_SIZE);
  }, [listRenderKey, view]);

  useEffect(() => {
    const handleNativeFilterChange = (event) => {
      const target = event.target;
      if (!target || target.tagName !== 'SELECT' || !target.dataset.leagueFilter) return;

      const key = target.dataset.leagueFilter;
      const value = target.value;
      if (key === 'location') {
        handleLocationChange(value);
      } else if (key === 'province') {
        setProvinceFilter(norm(value));
        handleFilterChange(key, value);
      } else {
        setFilters((current) => ({ ...current, [key]: value }));
        handleFilterChange(key, value);
      }
    };

    document.addEventListener('change', handleNativeFilterChange, true);
    document.addEventListener('input', handleNativeFilterChange, true);
    return () => {
      document.removeEventListener('change', handleNativeFilterChange, true);
      document.removeEventListener('input', handleNativeFilterChange, true);
    };
  }, []);

  const findNearMe = () => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      setGeoError('Location is not available in this browser.');
      return;
    }
    setLocating(true);
    setGeoError('');
    navigator.geolocation.getCurrentPosition(
      (pos) => { setUserCoords([pos.coords.latitude, pos.coords.longitude]); setLocating(false); },
      () => { setGeoError('Could not get your location, so we are showing the soonest available programs.'); setLocating(false); },
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 600000 }
    );
  };

  const backdrop = t.images.leagueHubBackdrop || '';
  const showSubheading = t.options.leagueHubShowSubheading === true;
  const cityGridColumns = isMobile ? '1fr' : 'repeat(2, minmax(0, 1fr))';

  return (
    <Layout>
      <main style={{ position: 'relative', minHeight: '100vh', overflow: 'hidden', background: '#fff' }}>
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
        <section style={{ position: 'relative', maxWidth: 1184, margin: '0 auto', padding: isMobile ? '28px 16px 72px' : '46px 32px 96px', scrollMarginTop: SCROLL_OFFSET }}>
          <div style={{ position: 'relative', textAlign: 'center', display: 'grid', justifyItems: 'center', minHeight: isMobile ? 150 : 172, alignContent: 'center' }}>
            <h1 style={{ ...FB.h(isMobile ? 42 : 58), textAlign: 'center' }}>
              {t.texts.leagueHubHeading || 'Our Programs'}
            </h1>
            {showSubheading && (
              <p style={{ margin: '6px auto 0', maxWidth: 560, fontFamily: 'var(--font-body)', color: 'var(--ea-ink, #1E526E)', fontSize: isMobile ? 15 : 16, lineHeight: 1.35 }}>
                {t.texts.leagueHubSubheading || `Find ${siteSport(t).toLowerCase()} lessons, leagues, and camps that are currently open for registration.`}
              </p>
            )}
            {t.options?.leagueHubShowLocationButton && (
              <button type="button" onClick={findNearMe} disabled={locating} style={{
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                marginTop: showSubheading ? 10 : 12,
                minWidth: isMobile ? 190 : 280,
                minHeight: isMobile ? 48 : 44,
                padding: isMobile ? '11px 24px' : '10px 28px',
                borderRadius: 7,
                border: '1px solid var(--border-card, #E5E5E5)',
                background: '#fff',
                color: 'var(--ea-navy, #10414F)',
                fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
                fontSize: isMobile ? 16 : 17,
                fontWeight: 700,
                cursor: locating ? 'default' : 'pointer',
                opacity: locating ? 0.7 : 1,
                boxShadow: 'none',
              }}>
                {locating ? 'Locating...' : userCoords ? 'Nearest to You' : (t.texts.leagueHubLocationButton || 'Use My Location')}
              </button>
            )}
            {geoError && <p role="alert" style={{ margin: '10px 0 0', color: 'var(--ea-error, #C0392B)', fontFamily: 'var(--font-body)', fontSize: 14 }}>{geoError}</p>}
          </div>

          <LeagueHubFilters
            filters={filters}
            setFilters={setFilters}
            locationFilter={locationFilter}
            onLocationChange={handleLocationChange}
            provinceFilter={provinceFilter}
            onProvinceChange={(value) => setProvinceFilter(norm(value))}
            onFilterChange={handleFilterChange}
            onClearFilters={resetFiltersAndSort}
            cities={cities}
            provinces={provinces}
            options={t.options}
            isMobile={isMobile}
            showInactiveCities={showInactiveCities}
            onShowInactiveCitiesChange={setShowInactiveCities}
          />

          {(showMapView || showCalendarView) && (
            <ViewToggle view={view} setView={setView} isMobile={isMobile} showMap={showMapView} showCalendar={showCalendarView} />
          )}

          {/* The list container deliberately has NO React `key`. It used to be
              keyed on a string containing filters.search, so every keystroke
              changed the key and React tore down and rebuilt every card.
              Cards now reconcile on their own stable program._key. */}
          {view === 'list' && (
            <div style={{
              display: 'grid',
              gridTemplateColumns: cityGridColumns,
              gap: isMobile ? 10 : 12,
              marginTop: 12,
            }}>
              {filteredCitySummaries.length ? visibleCitySummaries.map((summary) => (
                <LeagueCityCard
                  key={summary.key}
                  summary={summary}
                  isMobile={isMobile}
                  onSubscribe={setSubscribeLoc}
                  t={t}
                  href={summaryCityHref(summary, cityRecords, t)}
                />
              )) : feedStatus === 'loading' ? (
                <div style={{ ...FB.card, textAlign: 'center' }}>
                  <strong>Loading programs…</strong>
                </div>
              ) : feedStatus === 'error' ? (
                <div style={{ ...FB.card, textAlign: 'center' }}>
                  <strong>We couldn’t load the programs list.</strong>
                  <p style={{ margin: '8px 0 0', fontFamily: 'var(--font-body)', color: 'var(--ea-slate, #47636B)' }}>Please refresh the page, or contact us if it keeps happening.</p>
                </div>
              ) : (
                <div style={{ ...FB.card, textAlign: 'center' }}>
                  <strong>No programs match those filters.</strong>
                  <p style={{ margin: '8px 0 0', fontFamily: 'var(--font-body)', color: 'var(--ea-slate, #47636B)' }}>Try clearing one filter or searching a nearby city.</p>
                </div>
              )}
              {hasMoreListCities && (
                <div style={{ display: 'flex', justifyContent: 'center', marginTop: isMobile ? 8 : 12, gridColumn: '1 / -1' }}>
                  <button
                    type="button"
                    onClick={() => setVisibleCount((count) => count + LIST_BATCH_SIZE)}
                    style={{
                      display: 'inline-flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      minHeight: 46,
                      padding: '12px 24px',
                      borderRadius: 8,
                      border: '1px solid var(--ea-navy, #10414F)',
                      background: '#fff',
                      color: 'var(--ea-navy, #10414F)',
                      fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
                      fontSize: 16,
                      fontWeight: 700,
                      cursor: 'pointer',
                    }}
                  >
                    Load More Cities
                  </button>
                </div>
              )}
            </div>
          )}

          {view === 'map' && showMapView && (
            <LeagueHubMapView programs={filteredPrograms} statusLabels={statusLabels} chipStyle={chipStyle} SubscribeButton={ProgramSubscribeButton} onSubscribe={setSubscribeLoc} isMobile={isMobile} ProgramCard={ProgramCard} t={t} />
          )}

          {view === 'calendar' && showCalendarView && (
            <LeagueHubCalendarView programs={filteredPrograms} statusLabels={statusLabels} chipStyle={chipStyle} SubscribeButton={ProgramSubscribeButton} onSubscribe={setSubscribeLoc} isMobile={isMobile} ProgramCard={ProgramCard} t={t} />
          )}
        </section>
        {subscribeLoc !== null && <NewsletterModal DS={DS} t={t} location={subscribeLoc} onClose={() => setSubscribeLoc(null)} />}
      </main>
    </Layout>
  );
}
