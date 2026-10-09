/**
 * src/pages/HomePage.jsx — the badminton marketing home page.
 * Rendered when the mount div has data-page="home" (front-page.php / index.php).
 */
import { useState, useEffect, useRef } from 'react';
import { Layout, useDSComponents, useViewport, getThemeData, FB, MediaSlot, ActionButton } from '../lib/shared.jsx';
import { summaryCityHref, useCitiesFeed } from '../data/cities.js';
import {
  LeagueCityCard,
  buildCitySummaries,
  normalizePrograms as normalizeLeaguePrograms,
  useProgramsFeed as useLeagueProgramsFeed,
} from './LeagueHubPage.jsx';

// Scroll offset so a smooth-scrolled section isn't hidden under the sticky nav.
const SCROLL_OFFSET = 100;

// ─── Consistent section spacing ───────────────────────────────────────────────
const SECTION_MAX = 1184;                                          // content max-width
const sectionGap  = (isMobile) => (isMobile ? 56 : 120);            // vertical gap above each section
const sectionPadX = (isMobile) => (isMobile ? '0 16px' : '0 32px'); // horizontal padding

// ─── Page data ────────────────────────────────────────────────────────────────
// Fallback cards used before the live programs feed loads / if it fails.
const PROGRAM_FALLBACKS = [
  {
    Title: 'Richmond Hill - Jr. Badminton (8 - 10 yrs)',
    TotalPrice: 134,
    StaticPriceText: '134',
    Day: 'Mondays',
    Time: '5:30 - 6:30 PM',
    'Start Date': '2026-07-08',
    'End Date': '2026-08-20',
    SessionDates: '2026-07-08,2026-07-15,2026-07-22,2026-07-29,2026-08-05,2026-08-12,2026-08-20',
    LocationName: 'Langstaff CC',
    RegisterLink: 'https://eabadminton.com/signup/',
    City: 'Richmond Hill',
    Category: 'TS',
    sport: 'bad',
    is_full: false,
    level: '1',
    MinAge: '8',
    MaxAge: '10',
  },
  {
    Title: 'Richmond Hill - Jr. Badminton (11 - 13 yrs)',
    TotalPrice: 134,
    StaticPriceText: '134',
    Day: 'Mondays',
    Time: '5:30 - 6:30 PM',
    'Start Date': '2026-07-08',
    'End Date': '2026-08-20',
    SessionDates: '2026-07-08,2026-07-15,2026-07-22,2026-07-29,2026-08-05,2026-08-12,2026-08-20',
    LocationName: 'Langstaff CC',
    RegisterLink: 'https://eabadminton.com/signup/',
    City: 'Richmond Hill',
    Category: 'TS',
    sport: 'bad',
    is_full: false,
    level: '1',
    MinAge: '11',
    MaxAge: '13',
  },
  {
    Title: 'Newmarket - Advanced Jr. Badminton (9 - 18 yrs)',
    TotalPrice: 240,
    StaticPriceText: '240',
    Day: 'Mondays',
    Time: '5:30 - 6:30 PM',
    'Start Date': '2026-07-08',
    'End Date': '2026-08-20',
    SessionDates: '2026-07-08,2026-07-15,2026-07-22,2026-07-29,2026-08-05,2026-08-12,2026-08-20',
    LocationName: 'Dr J.M. Dennison',
    RegisterLink: 'https://eabadminton.com/signup/',
    City: 'Newmarket',
    Category: 'TS',
    sport: 'bad',
    is_full: false,
    level: '3',
    MinAge: '9',
    MaxAge: '18',
  },
];

function HeroSection({ DS, isMobile, t }) {
  const { SectionHeading, Button } = DS;
  const heading = t.texts.heroHeading || 'Play pickleball in Ontario';
  const primaryCta = t.texts.heroBtnPrimary || 'Find a League Near You';
  const secondaryCta = t.texts.heroBtnSecondary || 'New to Pickleball? Start Here';
  return (
    <section id="hero" style={{ textAlign: 'center', padding: isMobile ? '40px 20px 40px' : '60px 24px 36px', maxWidth: 1000, margin: '0 auto', scrollMarginTop: SCROLL_OFFSET }}>
      {SectionHeading
        ? <SectionHeading level={isMobile ? 'xl' : 'lg'} align="center" as="h1">{heading}</SectionHeading>
        : <h1 style={FB.h(isMobile ? 36 : 56)}>{heading}</h1>
      }
      <p style={{ fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: isMobile ? 16 : 18, color: 'var(--ea-ink, #1E526E)', lineHeight: 1.6, margin: '16px auto 0', maxWidth: 650 }}>
        {t.texts.heroDesc || 'Join Canada’s most exciting and fastest-growing pickleball community! We welcome players of all skill levels onto the court.'}
      </p>
      {/* Outer wrapper centres the button group (works for inline-grid too). */}
      <div style={{ marginTop: isMobile ? 24 : 28, textAlign: 'center' }}>
        <div style={{
          // Equal-width buttons. Desktop: a shrink-to-fit inline-grid whose two equal
          // `1fr` auto-columns BOTH size to the wider button — so they always match,
          // with no clipping and no hardcoded width. Mobile: one full-width column.
          display: isMobile ? 'grid' : 'inline-grid',
          gridTemplateColumns: isMobile ? '1fr' : undefined,
          gridAutoFlow: isMobile ? 'row' : 'column',
          gridAutoColumns: isMobile ? undefined : '1fr',
          gap: 16,
          // Mobile: cap the width so the stacked buttons are shorter, and centre them.
          width: isMobile ? '100%' : undefined,
          maxWidth: isMobile ? 260 : undefined,
          marginLeft: isMobile ? 'auto' : undefined,
          marginRight: isMobile ? 'auto' : undefined,
        }}>
          <ActionButton DS={DS} link={t.links.heroPrimary}   variant="primary"   size={isMobile ? 'md' : 'lg'} full>{primaryCta}</ActionButton>
          <ActionButton DS={DS} link={t.links.heroSecondary} variant="secondary" size={isMobile ? 'md' : 'lg'} full>{secondaryCta}</ActionButton>
        </div>
      </div>
    </section>
  );
}

function HeroImageSection({ isMobile, t }) {
  const src = isMobile
    ? (t.images.heroMobile || t.asset('mobile-hero.svg'))
    : (t.images.hero || t.asset('hero.png'));
  return (
    <div style={{ maxWidth: SECTION_MAX, margin: '0 auto', padding: isMobile ? 0 : sectionPadX(isMobile) }}>
      <img src={src} style={{ width: '100%' }} />
    </div>
  );
}

function LinkedCarousel({ slides, alt = '', ratio = '3 / 4', autoPlay = false, interval = 4000, style = {} }) {
  const [index, setIndex] = useState(0);
  const count = slides.length;

  useEffect(() => {
    if (!autoPlay || count < 2) return undefined;
    const timer = setInterval(() => setIndex((current) => (current + 1) % count), interval);
    return () => clearInterval(timer);
  }, [autoPlay, count, interval]);

  if (!count) return null;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 14, ...style }}>
      <div style={{ position: 'relative', width: '100%', aspectRatio: ratio, overflow: 'hidden', background: 'var(--ea-mist)' }}>
        {slides.map((slide, slideIndex) => {
          const visible = slideIndex === index;
          const image = (
            <img
              src={slide.src}
              alt={alt}
              style={{
                width: '100%',
                height: '100%',
                objectFit: 'cover',
                display: 'block',
              }}
            />
          );
          const sharedStyle = {
            position: 'absolute',
            inset: 0,
            opacity: visible ? 1 : 0,
            pointerEvents: visible ? 'auto' : 'none',
            transition: 'opacity .4s ease',
          };
          return slide.href ? (
            <a key={slideIndex} data-ea-carousel={slide.slot || slideIndex + 1} target="_blank" rel="noopener noreferrer" href={slide.href} style={{ ...sharedStyle, display: 'block' }}>
              {image}
            </a>
          ) : (
            <div key={slideIndex} style={sharedStyle}>
              {image}
            </div>
          );
        })}
      </div>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        {slides.map((slide, slideIndex) => (
          <button
            key={`${slideIndex}-dot`}
            type="button"
            onClick={() => setIndex(slideIndex)}
            aria-label={`Slide ${slideIndex + 1}`}
            style={{
              width: 8,
              height: 8,
              padding: 0,
              border: 'none',
              borderRadius: 'var(--radius-pill)',
              cursor: 'pointer',
              background: slideIndex === index ? '#8ac4e7' : 'var(--ea-line-soft)',
              transition: 'background .25s ease',
            }}
          />
        ))}
      </div>
    </div>
  );
}

function NewProgramsSection({ DS, isMobile, t }) {
  const { Carousel, SectionHeading } = DS;

  // Slides for the carousel — admin-set Customizer photos (EA Images → Carousel
  // image 1–3) when present, else the bundled program photos so it's never empty.
  const carouselSlides = [
    { slot: 1, src: t.images.carousel1 || t.asset('hero.png'), href: t.carouselLinks.carousel1 },
    { slot: 2, src: t.images.carousel2 || t.asset('net.png'), href: t.carouselLinks.carousel2 },
    { slot: 3, src: t.images.carousel3 || t.asset('birdie.png'), href: t.carouselLinks.carousel3 },
  ].filter((slide) => slide.src);
  const carouselImages = carouselSlides.map((slide) => slide.src);
  const hasCarouselLinks = carouselSlides.some((slide) => slide.href);

  const fallbackCarouselImage = carouselSlides[0] ? (
    carouselSlides[0].href ? (
      <a href={carouselSlides[0].href} data-ea-carousel={carouselSlides[0].slot || 1} target="_blank" rel="noopener noreferrer" style={{ display: 'block', width: '100%', maxWidth: 380 }}>
        <img src={carouselSlides[0].src} alt="Our new programs" style={{ width: '100%', aspectRatio: '4 / 5', objectFit: 'cover', display: 'block' }} />
      </a>
    ) : (
      <img src={carouselSlides[0].src} alt="Our new programs" style={{ width: '100%', maxWidth: 380, aspectRatio: '4 / 5', objectFit: 'cover', display: 'block' }} />
    )
  ) : null;

  const linkedCarousel = (
    <LinkedCarousel
      slides={carouselSlides}
      alt="Our new programs"
      ratio="4 / 5"
      autoPlay
      interval={4000}
      style={{ width: '100%', maxWidth: 380 }}
    />
  );

  // Portrait "poster"/flyer proportions (4:5).
  const standardCarousel = Carousel ? (
    <Carousel images={carouselImages} alt="Our new programs" ratio="4 / 5" autoPlay interval={4000} style={{ width: '100%', maxWidth: 380 }} />
  ) : (
    fallbackCarouselImage
  );

  const carousel = hasCarouselLinks ? linkedCarousel : standardCarousel;

  // Heading text. On mobile it matches the other section headers (SectionHeading "lg");
  // on desktop it's overlaid on top of the illustration in the left column.
  const headingText = t.texts.newProgramsHeading || 'Check out our new programs!';
  const mobileHeading = SectionHeading
    ? <SectionHeading level="lg" align="center">{headingText}</SectionHeading>
    : <h2 style={{ ...FB.h(28), textAlign: 'center' }}>{headingText}</h2>;

  // ── Mobile: heading (consistent with other sections) → carousel → full-bleed SVG ──
  if (isMobile) {
    return (
      <section id="new-programs" style={{ maxWidth: SECTION_MAX, margin: `${sectionGap(isMobile)}px auto 0`, padding: 0, scrollMarginTop: SCROLL_OFFSET }}>
        <div style={{ padding: '16px 0 0', display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
          <div style={{ textAlign: 'center', width: '75%' }}>{mobileHeading}</div>
          <div style={{
            background: '#fff', marginTop: 12,
            display: 'flex', flexDirection: 'column', alignItems: 'center',
            padding: '24px 20px', width: '88%'
          }}>
            {carousel}
          </div>
        </div>
        {/* Full-bleed illustration below the carousel (no side padding) */}
        <img
          src={t.images.sectionImageMobile || t.asset('mobile-announcements.svg')}
          alt=""
          aria-hidden="true"
          style={{ display: 'block', width: '100%', height: 'auto', marginTop: 24 }}
        />
      </section>
    );
  }

  // ── Desktop: heading overlaid on the illustration (left), carousel panel (right) ──
  return (
    <section id="new-programs" style={{ maxWidth: SECTION_MAX, margin: `${sectionGap(isMobile)}px auto 0`, padding: sectionPadX(isMobile), scrollMarginTop: SCROLL_OFFSET }}>
      <div style={{ display: 'flex', alignItems: 'stretch' }}>
        {/* Illustration — fills its column at its natural ratio; the carousel panel
            drives the row height and the image covers the area, cropping from the
            LEFT (objectPosition: right) so the form-facing side is always kept. */}
        <div style={{ position: 'relative', flex: '0 1 832px', minWidth: 0, overflow: 'hidden' }}>
          <img
            src={t.images.sectionImage || t.asset('new-programs-left.svg')}
            alt=""
            aria-hidden="true"
            style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover', objectPosition: 'right' }}
          />
          {/* Height floor: an aspect-ratio spacer keeps the column at least the
              image's natural height (832×555). The panel can push it taller. */}
          <div aria-hidden="true" style={{ width: '100%', aspectRatio: '832 / 555' }} />
          <div style={{ position: 'absolute', top: 0, left: 0, right: 0, padding: '50px 32px 0', width: '60%', textAlign: 'left' }}>
            {SectionHeading
              ? <SectionHeading level="md" align="left">{headingText}</SectionHeading>
              : <h2 style={{ ...FB.h(32), fontWeight: 'var(--fw-regular, 400)', margin: 0 }}>{headingText}</h2>
            }
          </div>
        </div>
        {/* Carousel panel — fills the remaining width and centres the photo strip. */}
        <div style={{
          flex: '1 1 0', minWidth: '35%', background: '#F9FDFF',
          display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center',
          padding: '48px 40px',
        }}>
          {carousel}
        </div>
      </div>
    </section>
  );
}

// ─── SessionSelect — custom dropdown styled to the Design System tokens ────────
// Replaces the native <select> with a button + floating popover so the option
// list matches the DS (input radius, pop shadow, hover/selected states) and looks
// consistent across browsers. Closes on outside click / Escape; keyboard-navigable.
function SessionSelect({ id, value, options, placeholder = 'Select Choice', onChange }) {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);          // highlighted row (keyboard)
  const rootRef = useRef(null);
  const listRef = useRef(null);

  // Close when clicking outside or pressing Escape.
  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => { if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === 'Escape') { setOpen(false); } };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey); };
  }, [open]);

  // When opening, highlight the current selection.
  useEffect(() => { if (open) setActive(options.indexOf(value)); }, [open, value, options]);

  const choose = (opt) => { onChange(opt); setOpen(false); };

  const onTriggerKey = (e) => {
    if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
      e.preventDefault(); setOpen(true); setActive((i) => (i < 0 ? 0 : i));
    }
  };
  const onListKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((i) => Math.min(options.length - 1, i + 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(0, i - 1)); }
    else if (e.key === 'Enter') { e.preventDefault(); if (active >= 0) choose(options[active]); }
    else if (e.key === 'Home') { e.preventDefault(); setActive(0); }
    else if (e.key === 'End') { e.preventDefault(); setActive(options.length - 1); }
  };

  const controlBase = {
    width: '100%', boxSizing: 'border-box',
    display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12,
    padding: '12px 16px', textAlign: 'left',
    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: 16,
    fontWeight: 400,
    lineHeight: 1.4,
    letterSpacing: 'normal',
    textTransform: 'none',
    background: 'var(--surface-card, #fff)',
    border: '0.5px solid var(--border-card, #E5E5E5)',
    borderRadius: 'var(--radius-input, 6px)',
    outline: 'none',
    cursor: 'pointer', transition: 'border-color .15s ease, box-shadow .15s ease',
  };

  return (
    <div ref={rootRef} style={{ position: 'relative', zIndex: open ? 30 : 'auto' }}>
      <button
        type="button" id={id}
        role="combobox" aria-haspopup="listbox" aria-expanded={open}
        onClick={() => setOpen((o) => !o)} onKeyDown={onTriggerKey}
        style={{ ...controlBase, color: value ? 'var(--ea-ink, #1E526E)' : 'var(--ea-muted, #787878)' }}
      >
        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', textTransform: 'none' }}>{value || placeholder}</span>
        <span style={{ display: 'inline-flex', color: 'var(--ea-navy, #10414F)', transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .18s ease' }}>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="m6 9 6 6 6-6" /></svg>
        </span>
      </button>

      {open && (
        <ul
          ref={listRef} role="listbox" tabIndex={-1} aria-activedescendant={active >= 0 ? `${id}-opt-${active}` : undefined}
          onKeyDown={onListKey}
          style={{
            position: 'absolute', top: 'calc(100% + 6px)', left: 0, right: 0, zIndex: 40,
            margin: 0, padding: 6, listStyle: 'none',
            background: 'var(--surface-card, #fff)',
            border: '1px solid var(--border-card, #E5E5E5)',
            borderRadius: 'var(--radius-input, 6px)',
            boxShadow: '0 4px 24px rgba(16, 65, 79, .02)',
            maxHeight: 260, overflowY: 'auto',
          }}
        >
          {options.map((opt, i) => {
            const selected = opt === value;
            const highlighted = i === active;
            return (
              <li
                key={opt} id={`${id}-opt-${i}`} role="option" aria-selected={selected}
                onMouseEnter={() => setActive(i)} onClick={() => choose(opt)}
                style={{
                  display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8,
                  padding: '10px 12px', borderRadius: 'var(--radius-input, 6px)', cursor: 'pointer',
                  fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: 15,
                  letterSpacing: 'normal',
                  textTransform: 'none',
                  color: 'var(--ea-ink, #1E526E)',
                  fontWeight: selected ? 700 : 500,
                  background: highlighted ? 'var( --ea-mist, #F0F0F0)' : 'transparent',
                  transition: 'background .12s ease',
                }}
              >
                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', textTransform: 'none' }}>{opt}</span>
                {selected && (
                  <span style={{ display: 'inline-flex', color: 'var(--ea-navy, #10414F)', flex: 'none' }}>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                  </span>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

function isTrialProgram(p) {
  const value = norm(p.Trial ?? p.trial ?? p.FreeTrial ?? p.free_trial);
  return value === 'y' || value === 'yes' || value === 'true' || value === '1';
}

function skillLevelFromProgram(p) {
  const raw = p.level ?? p.Level ?? p.LEVEL;
  const match = String(raw ?? '').match(/\d+/);
  const level = match ? Number(match[0]) : null;
  if (level !== null) return level > 1 ? 'Advanced' : 'Beginner';

  const title = norm(p.Title);
  if (title.includes('advanced') || title.includes('level 2')) return 'Advanced';
  return 'Beginner';
}

function weekdayLabel(date) {
  return date.toLocaleDateString('en-US', { weekday: 'long' });
}

// Stable id for one trial date: program id + ISO day (e.g. EA-PROGRAM-123@2026-10-14).
function trialSessionId(p, date) {
  const id = String((p && (p.ProgramID || p.ListingCode)) || '').trim();
  if (!id || !(date instanceof Date) || isNaN(date)) return '';
  return `${id}@${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function trialSessionLabel(p, date) {
  const dateLabel = date
    ? `${weekdayLabel(date)}, ${formatProgramDate(`${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`)}`
    : [p.Day, formatDateRange(p)].filter(Boolean).join(' · ');
  return [dateLabel, p.Time, p.LocationName || p.City]
    .filter(Boolean)
    .join(' · ');
}

function skillLevelSortValue(label) {
  const text = norm(label);
  if (text === 'beginner') return 0;
  if (text === 'advanced') return 1;
  return 99;
}

function addDays(date, days) {
  const out = new Date(date);
  out.setDate(out.getDate() + days);
  return out;
}

export function buildTrialSessionChoices(rows, sports) {
  const allow = new Set(sports && sports.length ? sports : ['bad']);
  const seen = new Set();
  const earliest = addDays(todayStart(), 7);
  const choices = [];

  (rows || [])
    .filter((p) => {
      const sportKey = rowSportKey(p) || (norm(p.Title).includes('badminton') ? 'bad' : null);
      return p && sportKey && allow.has(sportKey) && isEAorTS(p) && !p.is_cancelled && isTrialProgram(p);
    })
    .forEach((program) => {
      const skillLevel = skillLevelFromProgram(program);
      const sessionDates = String(program.SessionDates || '')
        .split(',')
        .map((s) => parseLocalDate(s.trim()))
        .filter((date) => date instanceof Date && !isNaN(date) && date >= earliest);
      const dates = sessionDates.length
        ? sessionDates
        : [getStartDate(program)].filter((date) => date instanceof Date && !isNaN(date) && date >= earliest);

      dates.forEach((date) => {
        const label = trialSessionLabel(program, date);
        if (!label) return;
        const key = `${skillLevel}|${date.getTime()}|${program.Time}|${program.LocationName || program.City}`;
        if (seen.has(key)) return;
        seen.add(key);
        choices.push({ skillLevel, label, date: date.getTime(), sessionId: trialSessionId(program, date), venue: program.LocationName || program.City || '' });
      });
    });

  return choices.sort((a, b) => a.date - b.date || a.label.localeCompare(b.label));
}

// Free Trial registration form. Shown in place of the carousel when the
// Customizer toggle "Show photo carousel" is unchecked (options.useCarousel = false).
export function FreeTrialSection({ DS, isMobile, t, variant = '', title = '', subline = '', onSessionPick }) {
  const { Button, SectionHeading } = DS;
  const [form, setForm] = useState({ name: '', email: '', phone: '', skillLevel: '', session: '', website: '' });
  const [submitted, setSubmitted] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  const update = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const rows = useProgramsFeed();
  const trialChoices = buildTrialSessionChoices(rows || [], ['bad']);
  const skillLevels = Array.from(new Set(trialChoices.map((choice) => choice.skillLevel)))
    .sort((a, b) => skillLevelSortValue(a) - skillLevelSortValue(b) || a.localeCompare(b));
  const skillLevelKey = skillLevels.join('|');
  const hasTrialChoices = trialChoices.length > 0;
  const isTrialFeedLoading = rows === null && !hasTrialChoices;

  useEffect(() => {
    if (form.skillLevel && skillLevels.length && !skillLevels.includes(form.skillLevel)) {
      setForm((f) => ({ ...f, skillLevel: '', session: '' }));
    }
  }, [skillLevelKey, form.skillLevel]);

  const handleSubmit = async (e) => {
    if (e && e.preventDefault) e.preventDefault();
    setError('');

    // Client-side validation before hitting the server.
    if (!form.name.trim() || !form.email.trim() || !form.phone.trim() || !form.skillLevel.trim() || !form.session.trim()) {
      setError('Please enter the athlete’s name, email, phone number, skill level, and session.');
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
      setError('Please enter a valid email address.');
      return;
    }

    setSending(true);
    try {
      const picked = trialChoices.find((choice) => choice.label === form.session && choice.skillLevel === form.skillLevel) || {};
      const res = await fetch(`${t.apiUrl}ea/v1/free-trial`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': t.nonce },
        body: JSON.stringify({ ...form, sessionId: picked.sessionId || '', venue: picked.venue || '', ...(variant === 'landing' ? { source: 'free-trial-page' } : {}) }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data && data.message ? data.message : 'Something went wrong. Please try again.');
      }
      setSubmitted(true);
      if (window.eaTrackTrial) window.eaTrackTrial(data.track);
      setForm({ name: '', email: '', phone: '', skillLevel: '', session: '', website: '' });
    } catch (err) {
      setError(err.message || 'Something went wrong. Please try again.');
    } finally {
      setSending(false);
    }
  };

  // Landing page on phones: tighter spacing so the Register button shows without scrolling.
  const compact = variant === 'landing' && isMobile;
  const fieldGap = compact ? 10 : 20;
  const labelStyle = {
    display: 'block', fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
    fontSize: 14, fontWeight: 600, color: 'var(--ea-navy, #10414F)', marginBottom: compact ? 4 : 8,
  };
  const inputStyle = {
    width: '100%', boxSizing: 'border-box', padding: compact ? '10px 14px' : '12px 16px',
    border: '1px solid var(--border-card, #E5E5E5)', borderRadius: 8,
    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: 16,
    color: 'var(--ea-ink, #1E526E)', background: '#fff',
  };
  const freeTrialButtonStyle = {
    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
    fontSize: 16,
    fontWeight: 600,
    letterSpacing: 'normal',
    textTransform: 'none',
  };

  const sessions = hasTrialChoices
    ? trialChoices.filter((choice) => choice.skillLevel === form.skillLevel).slice(0, 3).map((choice) => choice.label)
    : [];

  // Heading text. On mobile it matches the other section headers (SectionHeading "lg");
  // on desktop it's overlaid on top of the illustration in the left column.
  useEffect(() => {
    if (typeof onSessionPick !== 'function') return;
    const picked = trialChoices.find((choice) => choice.label === form.session && choice.skillLevel === form.skillLevel) || null;
    onSessionPick(picked);
  }, [form.session, form.skillLevel]);

  const headingText = t.texts.freeTrialHeading || 'Register for your free trial!';
  const subheadingText = t.texts.freeTrialSubheading || '';
  const mobileHeading = SectionHeading
    ? <SectionHeading level="lg" align="center">{headingText}</SectionHeading>
    : <h2 style={{ ...FB.h(28), textAlign: 'center' }}>{headingText}</h2>;
  const subheading = subheadingText ? (
    <p style={{
      margin: isMobile ? '10px auto 0' : '12px 0 0',
      maxWidth: isMobile ? 520 : 430,
      fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
      fontSize: isMobile ? 15 : 16,
      lineHeight: 1.4,
      color: 'var(--ea-ink, #1E526E)',
    }}>
      {subheadingText}
    </p>
  ) : null;

  const formInner = (
    <form id="free-trial" onSubmit={handleSubmit} style={{ width: '100%', maxWidth: 440, scrollMarginTop: 140 }}>
      <div>
        <label style={labelStyle} htmlFor="ft-name">{t.texts.freeTrialNameLabel || 'Athlete\'s Name'}</label>
        <input id="ft-name" style={inputStyle} placeholder="Name" value={form.name} onChange={update('name')} />
      </div>
      <div style={{ marginTop: fieldGap }}>
        <label style={labelStyle} htmlFor="ft-email">{t.texts.freeTrialEmailLabel || 'Email'}</label>
        <input id="ft-email" type="email" style={inputStyle} placeholder="Email" value={form.email} onChange={update('email')} />
      </div>
      <div style={{ marginTop: fieldGap }}>
        <label style={labelStyle} htmlFor="ft-phone">Phone Number</label>
        <input id="ft-phone" type="tel" autoComplete="tel" style={inputStyle} placeholder="Phone Number" value={form.phone} onChange={update('phone')} />
      </div>
      <div style={{ marginTop: fieldGap }}>
        <label style={labelStyle} htmlFor="ft-skill-level">{t.texts.freeTrialSkillLabel || 'Skill Level'}</label>
        <SessionSelect
          id="ft-skill-level"
          value={form.skillLevel}
          options={hasTrialChoices ? skillLevels : []}
          placeholder={isTrialFeedLoading ? 'Loading skill levels...' : 'Select Skill Level'}
          onChange={(val) => setForm((f) => ({ ...f, skillLevel: val, session: '' }))}
        />
      </div>
      <div style={{ marginTop: fieldGap }}>
        <label style={labelStyle} htmlFor="ft-session">{t.texts.freeTrialSessionLabel || 'Choose Session'}</label>
        <SessionSelect
          id="ft-session"
          value={form.session}
          options={sessions}
          placeholder={hasTrialChoices && !form.skillLevel ? 'Select a skill level first' : 'Select Session'}
          onChange={(val) => setForm((f) => ({ ...f, session: val }))}
        />
      </div>
      {/* Honeypot — hidden from real users; bots that fill it are silently dropped. */}
      <div aria-hidden="true" style={{ position: 'absolute', left: '-9999px', width: 1, height: 1, overflow: 'hidden' }}>
        <label htmlFor="ft-website">Website</label>
        <input id="ft-website" tabIndex={-1} autoComplete="off" value={form.website} onChange={update('website')} />
      </div>

      {error && (
        <p role="alert" style={{ marginTop: 16, marginBottom: 0, fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: 14, color: 'var(--ea-error, #C0392B)' }}>
          {error}
        </p>
      )}

      <div style={{ marginTop: compact ? 16 : 24, display: 'flex', justifyContent: isMobile ? 'center' : 'flex-start' }}>
        {Button
          ? <Button variant="dark" type="submit" disabled={sending} style={freeTrialButtonStyle}>{sending ? 'Registering…' : (variant === 'landing' ? 'Book free trial' : (t.texts.freeTrialSubmit || 'Register'))}</Button>
          : <button type="submit" disabled={sending} style={{ ...FB.btn('primary'), ...freeTrialButtonStyle, background: 'var(--ea-teal-900, #004356)', opacity: sending ? 0.7 : 1, cursor: sending ? 'default' : 'pointer' }}>{sending ? 'Registering…' : (variant === 'landing' ? 'Book free trial' : (t.texts.freeTrialSubmit || 'Register'))}</button>
        }
      </div>
    </form>
  );

  const modal = submitted ? (
    <div
      role="dialog" aria-modal="true"
      onClick={() => setSubmitted(false)}
      style={{ position: 'fixed', inset: 0, zIndex: 1000, background: 'rgba(16,65,79,.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}
    >
      <div onClick={(e) => e.stopPropagation()} style={{ position: 'relative', background: '#fff', borderRadius: 12, padding: '44px 32px 36px', maxWidth: 420, width: '100%', textAlign: 'center', boxShadow: '0 12px 40px rgba(16,65,79,.25)' }}>
        <button onClick={() => setSubmitted(false)} aria-label="Close" style={{ position: 'absolute', top: 12, right: 12, background: 'none', border: 'none', cursor: 'pointer', color: 'var(--ea-navy, #10414F)', display: 'inline-flex', padding: 4 }}>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
        </button>
        <h3 style={{ ...FB.h(28), fontWeight: 'var(--fw-regular, 400)', margin: '0 0 12px' }}>{t.texts.freeTrialThanksTitle || 'Thank you!'}</h3>
        <p style={{ fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: 16, color: 'var(--ea-ink, #1E526E)', lineHeight: 1.5, margin: 0 }}>
          {t.texts.freeTrialThanksBody || 'We\'ll be in touch about your free trial.'}
        </p>
        <div style={{ marginTop: 24, display: 'flex', justifyContent: 'center' }}>
          {Button
            ? <Button variant="dark" onClick={() => setSubmitted(false)} style={freeTrialButtonStyle}>Close</Button>
            : <button onClick={() => setSubmitted(false)} style={{ ...FB.btn('primary'), ...freeTrialButtonStyle, background: 'var(--ea-teal-900, #004356)' }}>Close</button>
          }
        </div>
      </div>
    </div>
  ) : null;

  // ── Landing page (/free-trial/): short headline, then the form — nothing else above it ──
  if (variant === 'landing') {
    return (
      <section style={{ maxWidth: 520, margin: '0 auto', padding: isMobile ? '14px 20px 8px' : '40px 24px 16px', boxSizing: 'border-box' }}>
        <h1 style={{ ...FB.h(isMobile ? 26 : 38), fontWeight: 'var(--fw-regular, 400)', textAlign: 'center', margin: '0 0 6px' }}>{title || headingText}</h1>
        {(subline || subheadingText) && (
          <p style={{ margin: '0 auto', textAlign: 'center', maxWidth: 440, fontWeight: subline ? 600 : undefined, fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: isMobile ? 15 : 16, lineHeight: 1.4, color: 'var(--ea-ink, #1E526E)' }}>{subline || subheadingText}</p>
        )}
        <div style={{ display: 'flex', justifyContent: 'center', marginTop: isMobile ? 12 : 20 }}>{formInner}</div>
        {modal}
      </section>
    );
  }

  // ── Mobile: heading (consistent with other sections) → form → full-bleed SVG ──
  if (isMobile) {
    return (
      <section id="new-programs" style={{ maxWidth: SECTION_MAX, margin: `${sectionGap(isMobile)}px auto 0`, padding: 0, scrollMarginTop: SCROLL_OFFSET }}>
        <div style={{ padding: '16px 0 0', display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
          <div style={{ textAlign: 'center', width: '75%' }}>
            {mobileHeading}
            {subheading}
          </div>
          <div style={{
            background: '#fff', marginTop: 12,
            display: 'flex', flexDirection: 'column', alignItems: 'center',
            padding: '24px 20px', width: '88%'
          }}>
            {formInner}
          </div>
        </div>
        {/* Full-bleed illustration below the form (no side padding) */}
        <img
          src={t.images.sectionImageMobile || t.asset('free-trial-mobile.svg')}
          alt=""
          aria-hidden="true"
          style={{ display: 'block', width: '100%', height: 'auto', marginTop: 24 }}
        />
        {modal}
      </section>
    );
  }

  // ── Desktop: heading overlaid on the illustration (left), form panel (right) ──
  return (
    <section id="new-programs" style={{ maxWidth: SECTION_MAX, margin: `${sectionGap(isMobile)}px auto 0`, padding: sectionPadX(isMobile), scrollMarginTop: SCROLL_OFFSET }}>
      <div style={{ display: 'flex', alignItems: 'stretch' }}>
        <div style={{ position: 'relative', flex: '0 1 832px', minWidth: 0, overflow: 'hidden' }}>
          <img
            src={t.images.sectionImage || t.asset('freetrial-left.svg')}
            alt=""
            aria-hidden="true"
            style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover', objectPosition: 'right' }}
          />
          <div aria-hidden="true" style={{ width: '100%', aspectRatio: '832 / 555' }} />
          <div style={{ position: 'absolute', top: 0, left: 0, right: 0, padding: '50px 32px 0', width: '60%', textAlign: 'left' }}>
            {SectionHeading
              ? <SectionHeading level="md" align="left">{headingText}</SectionHeading>
              : <h2 style={{ ...FB.h(32), fontWeight: 'var(--fw-regular, 400)', margin: 0 }}>{headingText}</h2>
            }
            {subheading}
          </div>
        </div>
        <div style={{
          flex: '1 1 0', minWidth: '35%', background: '#F9FDFF',
          display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'flex-start',
          padding: '48px 40px',
        }}>
          {formInner}
        </div>
      </div>
      {modal}
    </section>
  );
}

// Hover wrapper — signals a card is clickable with a pointer cursor and a
// subtle lift + shadow on hover.
function CardHover({ children }) {
  const [hover, setHover] = useState(false);
  return (
    <div
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        cursor: 'pointer',
        borderRadius: 8,
        transition: 'transform .15s ease, box-shadow .15s ease',
        transform: hover ? 'translateY(-1.5px)' : 'none',
        boxShadow: hover ? '0 4px 24px rgba(16, 65, 79, 0.05)' : 'none',
      }}
    >
      {children}
    </div>
  );
}

function MailIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ flex: 'none' }}>
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="m3 7 9 6 9-6" />
    </svg>
  );
}

function bodyButtonStyle(variant = 'primary', isMobile = false, extra = {}) {
  const isDark = variant === 'dark';
  const isPrimary = variant === 'primary' || isDark;
  return {
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: isMobile ? 48 : 52,
    minWidth: isMobile ? 160 : 220,
    padding: isMobile ? '11px 22px' : '13px 28px',
    borderRadius: 8,
    border: isPrimary ? '1px solid transparent' : '1px solid var(--border-card, #E5E5E5)',
    background: isDark ? 'var(--ea-teal-900, #004356)' : isPrimary ? '#0092DB' : '#fff',
    color: isPrimary ? '#fff' : 'var(--ea-navy, #10414F)',
    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
    fontSize: isMobile ? 15 : 16,
    fontWeight: 'var(--fw-bold, 700)',
    lineHeight: 1,
    letterSpacing: 'var(--ls-body, 0)',
    textTransform: 'none',
    textDecoration: 'none',
    cursor: 'pointer',
    boxSizing: 'border-box',
    ...extra,
  };
}

function ProgramSubscribeButton({ city, province = '', sessionStart = '', programSummary = '', isMobile = false, onSubscribe }) {
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
        width: 'fit-content', padding: '5px 10px', borderRadius: 6,
        fontFamily: 'var(--font-body)', fontSize: isMobile ? 14 : 15, fontWeight: 'var(--fw-medium)',
        textTransform: 'none',
        border: 'none', cursor: 'pointer', whiteSpace: 'nowrap',
        background: '#F9F4FF', color: '#6F677B',
      }}
    >
      <span style={{ display: 'inline-flex', alignItems: 'center', lineHeight: 1 }}>
        <MailIcon />
      </span>
      <span style={{ display: 'inline-flex', alignItems: 'center', lineHeight: 1.1, whiteSpace: 'nowrap' }}>
        Subscribe
        <span
          style={{
            display: 'inline-block', overflow: 'hidden', whiteSpace: 'nowrap',
            maxWidth: hover ? 420 : 0, opacity: hover ? 1 : 0,
            transition: 'max-width .3s ease, opacity .3s ease',
          }}
        >
          &nbsp;to {city}{isMobile ? '' : '’s Newsletter'}
        </span>
      </span>
    </button>
  );
}

function siteSport(t) {
  return (t.defaults && t.defaults.sport) || 'Badminton';
}

function generalNewsletterLocation(t) {
  return `General ${siteSport(t)}`;
}

function sportBrand(t) {
  return `EA ${siteSport(t)}`;
}

// ─── Live programs feed (public JSON) ─────────────────────────────────────────
// Fetch the program rows, keep active EA/TS rows, and render direct registration
// cards instead of collapsing them into city pages.
const PROGRAMS_DATA_URL = 'https://curtisbuilds.github.io/ea-programs-json/data/programs.json';

// Approximate coordinates for the cities that appear in the feed, keyed by the
// normalized city name. Used to sort cards by distance from the visitor when they
// tap "Programs near me". A city missing here just sorts last (never breaks).
const CITY_COORDS = {
  'aurora':           [44.0065, -79.4504],
  'newmarket':        [44.0592, -79.4613],
  'newmarket/aurora': [44.0330, -79.4560],
  'richmond hill':    [43.8828, -79.4403],
  'vaughan':          [43.8361, -79.4983],
  'georgina':         [44.2496, -79.4665],
  'uxbridge':         [44.1092, -79.1204],
  'innisfil':         [44.3000, -79.5833],
  'essa':             [44.2501, -79.7833],
  'barrie':           [44.3894, -79.6903],
  'oro-medonte':      [44.5501, -79.5833],
  'severn':           [44.7501, -79.5333],
  'cambridge':        [43.3601, -80.3127],
  'kamloops':         [50.6745, -120.3273],
  'squamish':         [49.7016, -123.1558],
  'surrey':           [49.1913, -122.8490],
  'terrace':          [54.5182, -128.6032],
  'okotoks':          [50.7256, -113.9749],
};

// Great-circle distance (km) between two [lat, lng] points.
function haversineKm(a, b) {
  const R = 6371;
  const toRad = (d) => (d * Math.PI) / 180;
  const dLat = toRad(b[0] - a[0]);
  const dLon = toRad(b[1] - a[1]);
  const h = Math.sin(dLat / 2) ** 2
    + Math.cos(toRad(a[0])) * Math.cos(toRad(b[0])) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}

const norm = (v) => String(v || '').trim().toLowerCase();

function parseLocalDate(dateStr) {
  if (!dateStr) return null;
  const parts = String(dateStr).split('-');
  if (parts.length !== 3) { const d = new Date(dateStr); return isNaN(d) ? null : d; }
  const [y, m, d] = parts.map(Number);
  const out = new Date(y, m - 1, d);
  return isNaN(out) ? null : out;
}

// Sports present in the feed (code → label + the raw values that map to it).
// Keys match the Customizer multi-select (EA Options -> Active Programs sports).
const SPORTS = {
  pb:     { label: 'Pickleball',  aliases: ['pb', 'pickleball', 'pickle'] },
  bad:    { label: 'Badminton',   aliases: ['bad', 'badm', 'badmin', 'badminton'] },
  bask:   { label: 'Basketball',  aliases: ['bask', 'basketball', 'bball'] },
  s_camp: { label: 'Sports Camp', aliases: ['s_camp', 'camp', 'camps', 's_camps'] },
};

// Which sport key a program row belongs to (or null if unrecognised).
function rowSportKey(p) {
  const s = norm(p.sport || p.Sport || p.SPORT);
  for (const key in SPORTS) {
    if (SPORTS[key].aliases.indexOf(s) !== -1) return key;
  }
  return null;
}

function isEAorTS(p) {
  const c = String(p.Category || '').trim().toUpperCase();
  return c === 'EA' || c === 'TS';
}

function isActiveProgram(p) {
  const today = new Date();
  const today0 = new Date(today.getFullYear(), today.getMonth(), today.getDate());
  const sessionDates = (p.SessionDates || '')
    .split(',')
    .map((s) => parseLocalDate(s.trim()))
    .filter((d) => d instanceof Date && !isNaN(d));
  if (sessionDates.length) {
    const completed = sessionDates.filter((d) => d < today0).length;
    return completed < sessionDates.length;
  }
  const end = parseLocalDate(p['End Date'] || p.EndDate || p.endDate);
  if (!end) return true;
  return end >= today0;
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

function isFullProgram(p) {
  return p.is_full === true || String(p.is_full).toLowerCase() === 'true';
}

function isEnrollmentOpen(p) {
  const raw = p.enrollment_open ?? p.enrollmentOpen ?? p.EnrollmentOpen ?? p.enrollment_status ?? p.EnrollmentStatus ?? p.status;
  if (raw === undefined || raw === null || raw === '') return true;
  const value = norm(raw);
  if (value === 'false' || value === 'closed' || value === 'enrollment closed' || value === 'registration closed') return false;
  return true;
}

function isStartingSoon(p, today0 = todayStart()) {
  const start = getStartDate(p);
  return start ? start > today0 : false;
}

function isInProgress(p, today0 = todayStart()) {
  const start = getStartDate(p);
  const end = getEndDate(p);
  if (!start) return true;
  if (start > today0) return false;
  return !end || end >= today0;
}

function programSortGroup(p, allInProgress, today0) {
  const open = isEnrollmentOpen(p);
  const full = isFullProgram(p);
  if (allInProgress) {
    if (open && !full) return 0;
    if (open && full) return 1;
    if (!open && !full) return 2;
    return 3;
  }
  if (isStartingSoon(p, today0) && open && !full) return 0;
  if (isInProgress(p, today0) && open && !full) return 1;
  if (isInProgress(p, today0) && open && full) return 2;
  if (isInProgress(p, today0) && !open && !full) return 3;
  if (isInProgress(p, today0) && !open && full) return 4;
  return 5;
}

function sortPrograms(programs, userCoords) {
  const today0 = todayStart();
  const activePrograms = programs.filter((p) => isActiveProgram(p));
  const allInProgress = activePrograms.length > 0 && activePrograms.every((p) => isInProgress(p, today0));
  return [...activePrograms].sort((a, b) => {
    if (userCoords) {
      const da = a.coords ? haversineKm(userCoords, a.coords) : Infinity;
      const db = b.coords ? haversineKm(userCoords, b.coords) : Infinity;
      if (da !== db) return da - db;
    }
    const ga = programSortGroup(a, allInProgress, today0);
    const gb = programSortGroup(b, allInProgress, today0);
    if (ga !== gb) return ga - gb;
    const aEnd = getEndDate(a)?.getTime() || 0;
    const bEnd = getEndDate(b)?.getTime() || 0;
    if (allInProgress && aEnd !== bEnd) return bEnd - aEnd;
    const aStart = getStartDate(a)?.getTime() || Infinity;
    const bStart = getStartDate(b)?.getTime() || Infinity;
    if (aStart !== bStart) return aStart - bStart;
    return String(a.Title || '').localeCompare(String(b.Title || ''));
  });
}

// Keep individual program rows whose sport is selected. When `userCoords` is
// provided, nearest city sorts first; otherwise use the registration priority.
function buildProgramList(programs, sports, userCoords) {
  const allow = new Set(sports && sports.length ? sports : ['bad']);
  return sortPrograms(
    programs
      .filter((p) => {
        const sportKey = rowSportKey(p);
        return p && sportKey && allow.has(sportKey) && isEAorTS(p) && p.City && !p.is_cancelled;
      })
      .map((p) => ({ ...p, coords: CITY_COORDS[norm(p.City)] || null })),
    userCoords
  );
}

// Fetch the raw rows once on mount; returns null while loading or on error.
export function useProgramsFeed() {
  const [rows, setRows] = useState(null);
  useEffect(() => {
    let alive = true;
    fetch(`${PROGRAMS_DATA_URL}?v=${Date.now()}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error('bad response'))))
      .then((data) => { if (alive) setRows(Array.isArray(data) ? data : []); })
      .catch(() => { if (alive) setRows(null); });
    return () => { alive = false; };
  }, []);
  return rows;
}

const PROGRAMS_LIMIT = 4;

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
  return String(p.SessionDates || '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean).length;
}

function displayPrice(p) {
  const raw = p.updated_price || p.StaticPriceText || p.TotalPrice;
  if (raw === undefined || raw === null || raw === '') return '';
  const num = Number(raw);
  if (!Number.isNaN(num)) return `$${num % 1 === 0 ? num.toFixed(0) : num.toFixed(2)}`;
  return `$${String(raw).replace(/^\$/, '')}`;
}

function inferLevelLabel(p) {
  const text = `${p.Title || ''} ${p.level || ''}`.toLowerCase();
  if (text.includes('advanced') || text.includes('level 3') || text.match(/\b3\b/)) return 'Advanced';
  if (text.includes('intermediate') || text.includes('level 2') || text.match(/\b2\b/)) return 'Intermediate';
  if (text.includes('beginner') || text.includes('level 1') || text.match(/\b1\b/)) return 'Beginner';
  return null;
}

function inferProgramTypeLabel(p) {
  const title = String(p.Title || '').toLowerCase();
  if (title.includes('camp')) return 'Camps';
  if (title.includes('league')) return 'League';
  return 'Lessons';
}

function statusLabels(p) {
  const labels = [];
  labels.push(isEnrollmentOpen(p) ? 'Enrollment Open' : 'Enrollment Closed');
  labels.push(isStartingSoon(p) ? 'Starting Soon' : 'In Progress');
  const level = inferLevelLabel(p);
  const type = inferProgramTypeLabel(p);
  if (level) labels.push(level);
  if (type) labels.push(type);
  if (isFullProgram(p)) labels.push('Full');
  return labels;
}

function chipStyle(label) {
  const key = norm(label);
  if (key.includes('open')) return { bg: '#CFF6D9', color: '#287545' };
  if (key.includes('closed') || key === 'full') return { bg: '#ECEFF1', color: '#66757B' };
  if (key.includes('starting')) return { bg: '#FFE9AF', color: '#8A640F' };
  if (key.includes('progress')) return { bg: '#D7F1FF', color: '#206A87' };
  if (key.includes('advanced')) return { bg: '#0B5B73', color: '#FFFFFF' };
  if (key.includes('camp')) return { bg: '#FFBB91', color: '#0077A3' };
  if (key.includes('lesson')) return { bg: '#FFFFFF', color: '#0B5B73', border: '1px solid #0B5B73' };
  return { bg: '#BDEEFF', color: '#0B5B73' };
}

function ProgramChip({ label }) {
  const styles = chipStyle(label);
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', width: 'fit-content',
      padding: '4px 7px', borderRadius: 6,
      background: styles.bg, color: styles.color,
      border: styles.border || '1px solid transparent',
      fontFamily: 'var(--font-body)', fontSize: 14, fontWeight: 'var(--fw-medium)',
      textTransform: 'none',
      lineHeight: 1.1,
    }}>
      {label}
    </span>
  );
}

function cleanProgramTitle(p) {
  const title = String(p.Title || 'Badminton Program').trim();
  return title
    .replace(/\s*-\s*/g, ' – ')
    .replace(/\((\d+\s*[-–]\s*\d+)\)/g, '($1 yrs)')
    .replace(/\byrs yrs\b/i, 'yrs');
}

function programMetaLine(p) {
  const count = sessionCount(p);
  const sessions = count ? `${count} Session${count === 1 ? '' : 's'}` : '';
  return [sessions, p.Day, formatDateRange(p), p.Time, p.LocationName].filter(Boolean).join(' · ');
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

function ActiveProgramCard({ program, isMobile = false, onSubscribe, t }) {
  const sport = siteSport(t);
  const city = String(program.City || '').trim() || `General ${sport}`;
  const province = String(program.Province || '').trim();
  const sessionStart = firstSessionDate(program);
  const programSummary = programSummaryLine(program);
  const full = isFullProgram(program);
  const enrollmentOpen = isEnrollmentOpen(program);
  const registerHref = enrollmentOpen
    ? (program.RegisterLink || program.URL || `${t.siteUrl || ''}/signup/`)
    : `mailto:info@elevationathletics.ca?subject=${encodeURIComponent(`${sport} program enrollment`)}`;
  const cta = !enrollmentOpen ? 'Email Us' : full ? 'Join Waitlist' : 'Register';
  const meta = programMetaLine(program);
  const price = displayPrice(program);
  return (
    <article style={{
      position: 'relative',
      display: 'grid',
      gridTemplateColumns: isMobile ? '1fr' : '1fr 150px',
      gap: isMobile ? 14 : 20,
      alignItems: 'center',
      background: 'var(--ea-white, #fff)',
      border: '1px solid var(--border-card, #E5E5E5)',
      borderRadius: 8,
      boxShadow: 'var(--shadow-card, 0 1px 4px rgba(16,65,79,.04))',
      padding: isMobile ? '18px 20px' : '18px 24px',
      fontFamily: 'var(--font-body)',
    }}>
      <div style={{ minWidth: 0 }}>
        <h3 style={{
          fontFamily: 'var(--font-body)',
          fontWeight: 'var(--fw-bold)',
          fontSize: isMobile ? 19 : 22,
          lineHeight: 1.18,
          color: 'var(--ea-teal-800, #0B5364)',
          margin: 0,
          textTransform: 'none',
          letterSpacing: 'var(--ls-body)',
        }}>
          {cleanProgramTitle(program)}
        </h3>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 12 }}>
          {statusLabels(program).map((label) => <ProgramChip key={label} label={label} />)}
        </div>
        {meta && (
          <p style={{
            margin: '12px 0 0',
            fontFamily: 'var(--font-body)',
            fontSize: isMobile ? 14 : 15,
            color: 'var(--ea-slate, #47636B)',
            lineHeight: 1.45,
          }}>
            {meta}
          </p>
        )}
        {!enrollmentOpen && (
          <p style={{ margin: '8px 0 0', fontFamily: 'var(--font-body)', fontSize: 13, color: 'var(--ea-slate, #47636B)', lineHeight: 1.4 }}>
            Enrollment is closed. Please contact info@elevationathletics.ca for details on how to enroll.
          </p>
        )}
        <div style={{ marginTop: 10 }}>
          <ProgramSubscribeButton city={city} province={province} sessionStart={sessionStart} programSummary={programSummary} isMobile={isMobile} onSubscribe={onSubscribe} />
        </div>
      </div>
      <div style={{
        display: 'flex',
        flexDirection: isMobile ? 'row' : 'column',
        alignItems: isMobile ? 'center' : 'flex-end',
        justifyContent: isMobile ? 'space-between' : 'center',
        gap: 14,
      }}>
        {price && (
          <div style={{ textAlign: isMobile ? 'left' : 'right', color: 'var(--ea-teal-800, #0B5364)', lineHeight: 1 }}>
            <div style={{ fontFamily: 'var(--font-body)', fontSize: isMobile ? 28 : 30, fontWeight: 'var(--fw-bold)' }}>{price}</div>
          </div>
        )}
        <a
          href={registerHref}
          data-ea-program={String(program.ProgramID || '').trim() || String(program.ListingCode || '').trim() || 'external'} data-ea-cta={cta === 'Join Waitlist' ? 'waitlist' : 'register'} data-ea-city={String(program.City || '')} data-ea-sport={String(program.sport || '')} data-ea-name={String(program.Title || '')}
          target={registerHref.startsWith('mailto:') ? undefined : '_blank'}
          rel={registerHref.startsWith('mailto:') ? undefined : 'noopener noreferrer'}
          style={{
            display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
            minWidth: isMobile ? 108 : 144,
            padding: '12px 18px',
            borderRadius: 7,
            background: full || !enrollmentOpen ? '#F9F4FF' : '#0A98D6',
            color: full || !enrollmentOpen ? '#6F677B' : '#fff',
            textDecoration: 'none',
            fontFamily: 'var(--font-body)', fontSize: 16, fontWeight: 'var(--fw-bold)',
          }}
        >
          {cta}
        </a>
      </div>
    </article>
  );
}

// Popup newsletter signup, opened from a location card's Subscribe button. Mirrors
// the Free Trial confirmation modal: enter email → submit → confirmation, all in place.
// When `startSubmitted` is true it opens straight to the thank-you view — used as a
// confirmation dialog for forms that already handled their own submit (e.g. the
// bottom-of-page newsletter section).
function NewsletterModal({ DS, t, location, onClose, startSubmitted = false }) {
  const { Button } = DS;
  const [email, setEmail] = useState('');
  const [website, setWebsite] = useState('');   // honeypot
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  const [submitted, setSubmitted] = useState(startSubmitted);
  const city = location && typeof location === 'object' ? location.city : location;
  const province = location && typeof location === 'object' ? location.province : '';
  const sessionStart = location && typeof location === 'object' ? location.sessionStart : '';
  const programSummary = location && typeof location === 'object' ? location.programSummary : '';
  const brand = sportBrand(t);
  const newsletterName = `${brand} Newsletter`;
  const isGeneralNewsletter = city === generalNewsletterLocation(t);
  const subscriptionLabel = city ? `${brand} ${city}` : `the ${newsletterName}`;
  const confirmationText = isGeneralNewsletter
    ? <>You're subscribed for the <strong>{newsletterName}</strong>. We'll keep you posted with any updates.</>
    : <>You're subscribed for <strong>{subscriptionLabel}</strong>.</>;

  const handleSubmit = async (e) => {
    if (e && e.preventDefault) e.preventDefault();
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
    <div
      role="dialog" aria-modal="true"
      onClick={onClose}
      style={{ position: 'fixed', inset: 0, zIndex: 1000, background: 'rgba(16,65,79,.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}
    >
      <div onClick={(e) => e.stopPropagation()} style={{ position: 'relative', background: '#fff', borderRadius: 12, padding: '44px 32px 36px', maxWidth: 420, width: '100%', textAlign: 'center', boxShadow: '0 12px 40px rgba(16,65,79,.25)' }}>
        <button onClick={onClose} aria-label="Close" style={{ position: 'absolute', top: 12, right: 12, background: 'none', border: 'none', cursor: 'pointer', color: 'var(--ea-navy, #10414F)', display: 'inline-flex', padding: 4 }}>
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
        </button>

        {submitted ? (
          <>
            <h3 style={{ ...FB.h(28), fontWeight: 'var(--fw-regular, 400)', margin: '0 0 12px' }}>Thank you!</h3>
            <p style={{ fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: 16, color: 'var(--ea-ink, #1E526E)', lineHeight: 1.5, margin: 0 }}>
              {confirmationText}
            </p>
            <div style={{ marginTop: 24, display: 'flex', justifyContent: 'center' }}>
              <button onClick={onClose} style={bodyButtonStyle('dark')}>Close</button>
            </div>
          </>
        ) : (
          <form onSubmit={handleSubmit}>
            <h3 style={{ ...FB.h(28), fontWeight: 'var(--fw-regular, 400)', margin: '0 0 8px' }}>{t.texts.newsletterHeading || 'Join Our Newsletter!'}</h3>
            <p style={{ fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: 16, color: 'var(--ea-ink, #1E526E)', lineHeight: 1.5, margin: '0 0 20px' }}>
              {location ? <>Subscribing to <strong>{subscriptionLabel}</strong>.</> : (t.texts.newsletterDesc || 'Stay updated on upcoming programs in your area.')}
            </p>
            <input type="email" placeholder="Your Email" value={email} autoFocus onChange={(e) => setEmail(e.target.value)} style={inputStyle} />
            {/* Honeypot — hidden from real users; bots that fill it are silently dropped. */}
            <div aria-hidden="true" style={{ position: 'absolute', left: '-9999px', width: 1, height: 1, overflow: 'hidden' }}>
              <label htmlFor="nlm-website">Website</label>
              <input id="nlm-website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
            </div>
            {error && (
              <p role="alert" style={{ marginTop: 12, marginBottom: 0, fontFamily: 'var(--font-body, sans-serif)', fontSize: 14, color: 'var(--ea-error, #C0392B)' }}>{error}</p>
            )}
            <div style={{ marginTop: 20, display: 'flex', justifyContent: 'center' }}>
              <button type="submit" disabled={sending} style={{ ...bodyButtonStyle('dark'), opacity: sending ? 0.7 : 1, cursor: sending ? 'default' : 'pointer' }}>{sending ? 'Subscribing…' : (t.texts.newsletterSubscribe || 'Subscribe')}</button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}

function ProgramsSection({ DS, isMobile, t }) {
  const { SectionHeading, Button } = DS;
  const programsHref = `${t.siteUrl || ''}/programs/`;
  const cityRecords = useCitiesFeed();
  const configuredViewAllLink = t.links && t.links.programsViewAll;
  const viewAllLink = configuredViewAllLink && (configuredViewAllLink.section || configuredViewAllLink.url)
    ? configuredViewAllLink
    : { url: programsHref };
  const viewAllHref = viewAllLink.section ? `#${viewAllLink.section}` : (viewAllLink.url || programsHref);
  const viewAllIsExternal = /^https?:\/\//i.test(viewAllHref)
    && typeof window !== 'undefined'
    && !viewAllHref.includes(window.location.hostname);
  const handleViewAllClick = (e) => {
    if (!viewAllLink.section) return;
    const target = typeof document !== 'undefined' && document.getElementById(viewAllLink.section);
    if (target) {
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth' });
    }
  };
  // Badminton home now previews active cities. Individual program rows live on
  // each city page, keeping the homepage cleaner and reducing decision fatigue.
  const selectedSports = ['bad'];
  // Visitor location for nearest-first sorting (null until they opt in).
  const [userCoords, setUserCoords] = useState(null);
  const [locating, setLocating] = useState(false);
  const [geoError, setGeoError] = useState('');

  const findNearMe = () => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      setGeoError('Location isn’t available in this browser.');
      return;
    }
    setLocating(true);
    setGeoError('');
    navigator.geolocation.getCurrentPosition(
      (pos) => { setUserCoords([pos.coords.latitude, pos.coords.longitude]); setLocating(false); },
      () => { setGeoError('Couldn’t get your location — showing the soonest available programs.'); setLocating(false); },
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 600000 }
    );
  };

  // Live feed when available, otherwise bundled fallback programs.
  const feedState = useLeagueProgramsFeed();
  const rows = feedState.rows;
  const programs = normalizeLeaguePrograms(rows || PROGRAM_FALLBACKS, selectedSports, userCoords);
  const cards = buildCitySummaries(programs, userCoords, false)
    .sort((a, b) => {
      if (b.availableCount !== a.availableCount) return b.availableCount - a.availableCount;
      if (b.programCount !== a.programCount) return b.programCount - a.programCount;
      return a.displayName.localeCompare(b.displayName);
    })
    .slice(0, PROGRAMS_LIMIT);
  // Which location's newsletter popup is open (null = closed).
  const [subscribeLoc, setSubscribeLoc] = useState(null);
  const showNearMeButton = t.options.programsShowLocationButton && !t.options.hideNearMe;
  return (
    <section id="active-programs" style={{ maxWidth: SECTION_MAX, margin: `${sectionGap(isMobile)}px auto 0`, padding: sectionPadX(isMobile), scrollMarginTop: SCROLL_OFFSET }}>
      {SectionHeading
        ? <SectionHeading level={ isMobile ? 'lg' : 'md' }>{t.texts.programsHeading || 'Our Active Programs'}</SectionHeading>
        : <h2 style={FB.h(32)}>{t.texts.programsHeading || 'Our Active Programs'}</h2>
      }
      {t.options.programsShowDescription && t.texts.programsDesc && (
        <p style={{
          margin: isMobile ? '8px 0 0' : '8px 0 0',
          maxWidth: 560,
          fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
          fontSize: isMobile ? 15 : 16,
          lineHeight: 1.35,
          color: 'var(--ea-ink, #1E526E)',
        }}>
          {t.texts.programsDesc}
        </p>
      )}
      {/* Action buttons — each can be hidden via Customizer (EA Options). */}
      {(showNearMeButton || !t.options.hideViewAll) && (
        <div style={{ marginTop: 12, display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
          {/* Left of "View All Programs": sort the cards nearest-first. */}
          {showNearMeButton && (
            <button onClick={findNearMe} disabled={locating} style={{ ...bodyButtonStyle('secondary', isMobile), opacity: locating ? 0.7 : 1, cursor: locating ? 'default' : 'pointer' }}>{locating ? 'Locating…' : userCoords ? 'Nearest to You' : (t.texts.programsNearMe || 'Programs Near Me')}</button>
          )}
          {!t.options.hideViewAll && (
            <a
              href={viewAllHref}
              onClick={handleViewAllClick}
              target={viewAllIsExternal ? '_blank' : undefined}
              rel={viewAllIsExternal ? 'noopener noreferrer' : undefined}
              style={bodyButtonStyle('primary', isMobile)}
            >
              {t.texts.programsViewAll || 'View All Programs'}
            </a>
          )}
        </div>
      )}
      {geoError && (
        <p role="alert" style={{ marginTop: 10, marginBottom: 0, fontFamily: 'var(--font-body, sans-serif)', fontSize: 14, color: 'var(--ea-error, #C0392B)' }}>{geoError}</p>
      )}
      <div style={{ display: 'grid', gridTemplateColumns: isMobile ? '1fr' : 'repeat(2, minmax(0, 1fr))', gap: isMobile ? 10 : 12, marginTop: isMobile ? 18 : 16 }}>
        {cards.map((summary) => (
          <CardHover key={summary.key}>
            <LeagueCityCard
              summary={summary}
              isMobile={isMobile}
              onSubscribe={setSubscribeLoc}
              t={t}
              href={summaryCityHref(summary, cityRecords, t)}
            />
          </CardHover>
        ))}
      </div>
      {subscribeLoc !== null && (
        <NewsletterModal DS={DS} t={t} location={subscribeLoc} onClose={() => setSubscribeLoc(null)} />
      )}
    </section>
  );
}

function SpotlightSection({ DS, isMobile, isTablet, t }) {
  const { SectionHeading, Button } = DS;
  // Mobile: 2 columns → text spans both, then a 2×2 grid of four image tiles.
  const cols = isMobile ? 'repeat(2, 1fr)' : isTablet ? '1fr 1fr' : 'repeat(3, 1fr)';
  const coachingHeading = t.texts.coachingHeading || 'Small Group Coaching';
  return (
    <section id="coaching" style={{ maxWidth: SECTION_MAX, margin: `${sectionGap(isMobile)}px auto 0`, padding: sectionPadX(isMobile), scrollMarginTop: SCROLL_OFFSET }}>
      <div style={{ display: 'grid', gridTemplateColumns: cols, gap: isMobile ? 12 : 20, alignItems: 'start' }}>
        <div style={{ gridColumn: isMobile ? '1 / -1' : undefined }}>
          {SectionHeading
            ? <SectionHeading level={ isMobile ? 'lg' : 'md' }>{coachingHeading}</SectionHeading>
            : <h2 style={FB.h(32)}>{coachingHeading}</h2>
          }
          <p style={{ fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: 16, color: 'var(--ea-ink, #1E526E)', lineHeight: 1.6, marginTop: 16 }}>
            {t.texts.coachingDesc || 'Small group coaching that meets every player where they are. Our sessions build skills, confidence, and a love of the game.'}
          </p>
          {!(t.links.coachingCta && t.links.coachingCta.hidden) && (
            <div style={{ marginTop: 24 }}>
              <ActionButton DS={DS} link={t.links.coachingCta} variant="primary">{t.texts.coachingCta || 'Learn More'}</ActionButton>
            </div>
          )}
        </div>
        {/* Coloured tiles become real photos once set in Appearance → Customize → EA Images.
            Four show on mobile (2×2); the fifth is desktop-only. */}
        <MediaSlot url={t.images.spotlight1} color="var(--ea-sky-soft, #D0F5FF)" ratio="1/1" />
        <MediaSlot url={t.images.ball}       color="var(--ea-sky-soft, #D0F5FF)" ratio="1/1" />
        <MediaSlot url={t.images.spotlight2} color="var(--ea-sky, #46AFE3)"      ratio="1/1" />
        <MediaSlot url={t.images.net}        color="var(--ea-sky, #46AFE3)"      ratio="1/1" />
        {!isMobile && (
          <MediaSlot url={t.images.spotlight3} color="var(--ea-peach, #FFBB91)" ratio="1/1" />
        )}
      </div>
    </section>
  );
}

function CommunitySection({ DS, isMobile, t }) {
  const { SectionHeading, Button } = DS;
  const bodyStyle = {
    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
    fontSize: 16, color: 'var(--ea-ink, #1E526E)', lineHeight: 1.6,
  };

  // Which part of each community image stays visible when it's cropped to fill its box.
  // Options: 'center' | 'top' | 'bottom' | 'left' | 'right' (or a pair like 'top right' / '50% 20%').
  // The named side is the one that never gets cut off.
  const communityTopFocus   = 'top';
  const communityLeftFocus  = 'top';
  const communityRightFocus = 'top';

  return (
    <section id="community" style={{ maxWidth: SECTION_MAX, margin: `${sectionGap(isMobile)}px auto 0`, padding: sectionPadX(isMobile), scrollMarginTop: SCROLL_OFFSET }}>
      {/* Top: solid-colour image block + intro copy (image hidden on mobile) */}
      <div style={{
        display: 'grid',
        gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr',
        gap: isMobile ? 0 : 24,   // match the bottom grid's gap so the image == card width
        alignItems: 'stretch',
      }}>
        {!isMobile && (
          <MediaSlot url={t.images.communityTop} color="var(--ea-sky-soft, #D0F5FF)" ratio="3 / 2" alt="Our community" style={{ height: 250, objectPosition: communityTopFocus }} />
        )}
        <div>
          {SectionHeading
            ? <SectionHeading level={ isMobile ? 'lg' : 'md' }>{t.texts.communityHeading || 'Want to be a part of the community?'}</SectionHeading>
            : <h2 style={FB.h(32)}>{t.texts.communityHeading || 'Want to be a part of the community?'}</h2>
          }
          <p style={{ ...bodyStyle, marginTop: 16 }}>
            {t.texts.communityDesc1 || 'From first-timers to future champions, Elevation Athletics badminton is built around fun, inclusive play for every family.'}
          </p>
          <p style={{ ...bodyStyle, marginTop: 12 }}>
            {t.texts.communityDesc2 || 'Join a welcoming community of players, parents, and coaches who make every session something to look forward to.'}
          </p>
        </div>
      </div>

      {/* Bottom: two promo cards with solid-colour image areas */}
      <div style={{ display: 'grid', gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr', gap: 24, marginTop: isMobile ? 32 : 24 }}>
        {[
          { bg: 'var(--ea-sky, #46AFE3)',  img: t.images.communityLeft,  focus: communityLeftFocus,  title: t.texts.partnershipsTitle || 'Community Partnerships',   cta: t.texts.partnershipsCta || 'Learn More',  link: t.links.partnershipsCta, blurb: t.texts.partnershipsBlurb || 'Help bring inclusive, low-cost badminton to your township. We\'ll set you up with courts, coaching, and leagues.' },
          { bg: 'var(--ea-peach, #FFBB91)', img: t.images.communityRight, focus: communityRightFocus, title: t.texts.leadersTitle || 'Become a Community Leader', cta: t.texts.leadersCta || 'Apply Today', link: t.links.leadersCta, blurb: t.texts.leadersBlurb || 'Help bring inclusive, low-cost badminton to your township. We\'ll set you up with courts, coaching, and leagues.' },
        ].map(({ bg, img, focus, title, cta, link, blurb }) => (
          // Background is the admin image (cover) when set, else the solid colour.
          // `focus` sets which part of the image stays visible (never cropped).
          // Fixed height; the white card is pinned to the bottom and the image/colour
          // area fills the space above it.
          <div key={title} style={{
            background: img ? `url(${img}) ${focus} / cover no-repeat` : bg,
            height: isMobile ? 500 : 750,
            padding: 28,
            display: 'flex',
            flexDirection: 'column',
            justifyContent: 'flex-end',
          }}>
            <div style={{ background: '#fff', borderRadius: 8, padding: '24px 28px', textAlign: 'center', boxShadow: '0 1px 4px rgba(16,65,79,.06)' }}>
              {SectionHeading
                ? <SectionHeading level="xs" align="center" as="h3">{title}</SectionHeading>
                : <h3 style={{ ...FB.h(20), textAlign: 'center' }}>{title}</h3>
              }
              <p style={{ fontFamily: 'var(--font-body, sans-serif)', fontSize: 16, color: 'var(--ea-ink, #1E526E)', lineHeight: 1.6, margin: '12px 0 18px' }}>
                {blurb}
              </p>
              <ActionButton DS={DS} link={link} variant="primary">{cta}</ActionButton>
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}

function NewsletterSection({ DS, isMobile, t }) {
  const { SectionHeading, Button } = DS;
  const [email, setEmail] = useState('');
  const [website, setWebsite] = useState('');   // honeypot
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  // On success we pop the confirmation modal (same one the location cards use).
  const [confirmOpen, setConfirmOpen] = useState(false);

  const handleSubmit = async (e) => {
    if (e && e.preventDefault) e.preventDefault();
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
        // Bottom-of-page signup is tagged as a general sport newsletter in the admin.
        body: JSON.stringify({ email, location: generalNewsletterLocation(t), website }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data && data.message ? data.message : 'Something went wrong. Please try again.');
      }
      setEmail('');
      setConfirmOpen(true);   // show the confirmation popup
    } catch (err) {
      setError(err.message || 'Something went wrong. Please try again.');
    } finally {
      setSending(false);
    }
  };

  // Decorative badminton scene behind the form (different art for mobile vs desktop).
  // Admin-set Customizer images (EA Images → Newsletter image / (mobile)) override the bundled art.
  const decor = isMobile
    ? (t.images.newsletterMobile || t.asset('newsletter-mobile.svg'))
    : (t.images.newsletter || t.asset('newsletter.svg'));

  return (
    <section id="newsletter" style={{ position: 'relative', marginTop: sectionGap(isMobile), background: '#fff', overflow: 'hidden', scrollMarginTop: SCROLL_OFFSET }}>
      {/* Full-width decorative SVG; sets the section height. Content is overlaid on top. */}
      <img src={decor} alt="" aria-hidden="true" style={{ display: 'block', width: '100%', height: 'auto' }} />

      {/* Centered newsletter content overlaid on the scene */}
      <div style={{
        position: 'absolute', inset: 0,
        display: 'flex', alignItems: 'start', justifyContent: 'center',
        padding: isMobile ? '24px 20px' : '24px',
      }}>
        <div style={{ textAlign: 'center', width: '100%', maxWidth: isMobile ? 320 : 550 }}>
          {SectionHeading
            ? <SectionHeading level={ isMobile ? 'lg' : 'lg' } align="center">{t.texts.newsletterHeading || 'Join Our Newsletter!'}</SectionHeading>
            : <h2 style={{ ...FB.h(isMobile ? 28 : 44), textAlign: 'center' }}>{t.texts.newsletterHeading || 'Join Our Newsletter!'}</h2>
          }
          <p style={{ fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: isMobile ? 14 : 20, color: 'var(--ea-ink, #1E526E)', lineHeight: 1.6, marginTop: 12 }}>
            {t.texts.newsletterDesc || 'Stay updated on upcoming training sessions, leagues, and tournaments for pickleball in your area.'}
          </p>
          <form onSubmit={handleSubmit}>
            {/* Input + Subscribe joined into one pill (container clips the square button corners). */}
            <div style={{
              display: 'flex', marginTop: isMobile ? 16 : 24,
              border: '1px solid #E5E5E5', borderRadius: 8, overflow: 'hidden', background: '#fff',
            }}>
              <input
                type="email" placeholder="Your Email" value={email}
                onChange={(e) => setEmail(e.target.value)}
                style={{ flex: 1, minWidth: 0, padding: '12px 16px', border: 'none', outline: 'none', fontFamily: 'var(--font-body, sans-serif)', fontSize: isMobile ? 14 : 16, background: 'transparent' }}
              />
              <button
                type="submit"
                disabled={sending}
                style={bodyButtonStyle('primary', isMobile, {
                  alignSelf: 'stretch',
                  minHeight: 'auto',
                  minWidth: isMobile ? 120 : 180,
                  borderRadius: 0,
                  opacity: sending ? 0.7 : 1,
                  cursor: sending ? 'default' : 'pointer',
                })}
              >
                {sending ? 'Subscribing…' : (t.texts.newsletterSubscribe || 'Subscribe')}
              </button>
            </div>
            {/* Honeypot — hidden from real users; bots that fill it are silently dropped. */}
            <div aria-hidden="true" style={{ position: 'absolute', left: '-9999px', width: 1, height: 1, overflow: 'hidden' }}>
              <label htmlFor="nl-website">Website</label>
              <input id="nl-website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
            </div>
            {error && (
              <p role="alert" style={{ marginTop: 12, marginBottom: 0, fontFamily: 'var(--font-body, sans-serif)', fontSize: 14, color: 'var(--ea-error, #C0392B)' }}>
                {error}
              </p>
            )}
          </form>
        </div>
      </div>
      {confirmOpen && (
        <NewsletterModal DS={DS} t={t} location={generalNewsletterLocation(t)} startSubmitted onClose={() => setConfirmOpen(false)} />
      )}
    </section>
  );
}

export default function HomePage() {
  const DS = useDSComponents();
  const { isMobile, isTablet } = useViewport();
  const t = getThemeData();
  return (
    <Layout>
      <HeroSection      DS={DS} isMobile={isMobile} t={t} />
      <HeroImageSection         isMobile={isMobile} t={t} />
      {/* Customizer toggle (EA Options → "Show photo carousel"): carousel when on
          (default), otherwise the Free Trial registration form. */}
      {t.options.useCarousel === false
        ? <FreeTrialSection   DS={DS} isMobile={isMobile} t={t} />
        : <NewProgramsSection DS={DS} isMobile={isMobile} t={t} />}
      <ProgramsSection  DS={DS} isMobile={isMobile} t={t} />
      <SpotlightSection DS={DS} isMobile={isMobile} isTablet={isTablet} t={t} />
      <CommunitySection DS={DS} isMobile={isMobile} t={t} />
      <NewsletterSection DS={DS} isMobile={isMobile} t={t} />
    </Layout>
  );
}
