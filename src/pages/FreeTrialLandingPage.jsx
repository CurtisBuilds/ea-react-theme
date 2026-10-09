/**
 * src/pages/FreeTrialLandingPage.jsx — /free-trial/ landing page (template-free-trial.php).
 *
 * Built for paid-ad traffic: logo-only header (no menu leading away), a short headline,
 * the SAME free-trial form as the home page (same endpoint + tracking), then the
 * WordPress-authored sections (first session, FAQ — edited in the page editor),
 * a map of the picked session's venue, and the normal footer.
 */
import { useState } from 'react';
import { Layout, useDSComponents, useViewport, getThemeData, FB } from '../lib/shared.jsx';
import { FreeTrialSection, buildTrialSessionChoices, useProgramsFeed } from './HomePage.jsx';

// Read once: React replaces #ea-react-root's children on first commit, so later
// re-renders (e.g. when the programs feed arrives) can no longer see the <template>.
let PAGE_DATA = null;
function pageData() {
  if (PAGE_DATA) return PAGE_DATA;
  if (typeof document === 'undefined') return { title: '', html: '' };
  const root = document.getElementById('ea-react-root');
  const tpl = document.getElementById('ea-free-trial-content');
  PAGE_DATA = { title: root ? root.dataset.title || '' : '', html: tpl ? tpl.innerHTML : '' };
  return PAGE_DATA;
}

function VenueMap({ venue, city, isMobile }) {
  if (!venue) return null;
  const q = encodeURIComponent([venue, city, 'ON'].filter(Boolean).join(', '));
  return (
    <section style={{ maxWidth: 720, margin: '0 auto', padding: isMobile ? '8px 20px 32px' : '16px 24px 48px', boxSizing: 'border-box' }}>
      <h2 style={{ ...FB.h(isMobile ? 22 : 28), fontWeight: 'var(--fw-regular, 400)', margin: '0 0 12px' }}>Where it happens</h2>
      <p style={{ margin: '0 0 12px', fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)', fontSize: 16, color: 'var(--ea-ink, #1E526E)' }}>
        {venue}{' · '}
        <a href={`https://www.google.com/maps/search/?api=1&query=${q}`} target="_blank" rel="noopener noreferrer" style={{ color: 'inherit' }}>Open in Google Maps</a>
      </p>
      <div style={{ position: 'relative', width: '100%', aspectRatio: isMobile ? '4 / 3' : '16 / 9', borderRadius: 12, overflow: 'hidden', border: '1px solid var(--border-card, #E5E5E5)' }}>
        <iframe
          title={`Map: ${venue}`}
          src={`https://www.google.com/maps?q=${q}&output=embed`}
          loading="lazy"
          referrerPolicy="no-referrer-when-downgrade"
          style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', border: 0 }}
        />
      </div>
    </section>
  );
}

export default function FreeTrialLandingPage() {
  const DS = useDSComponents();
  const { isMobile } = useViewport();
  const t = getThemeData();
  const { title, html } = pageData();
  const rows = useProgramsFeed();
  const choices = buildTrialSessionChoices(rows || [], ['bask']);
  const [picked, setPicked] = useState(null);
  // Map: the picked session's venue, else the next upcoming trial's venue.
  const shown = (picked && picked.venue) ? picked : (choices[0] || null);
  const venue = shown ? shown.venue : '';
  const row = shown && (rows || []).find((r) => String(r.ProgramID || r.ListingCode || '') === String(shown.sessionId || '').split('@')[0]);
  const city = row ? row.City : '';

  return (
    <Layout overrides={{ minimal: true }}>
      <FreeTrialSection DS={DS} isMobile={isMobile} t={t} variant="landing" title={title} onSessionPick={setPicked} />
      {html && (
        <section
          className="ea-blank-content__body ea-free-trial-content"
          style={{ maxWidth: 720, margin: '0 auto', padding: isMobile ? '24px 20px 8px' : '40px 24px 16px', boxSizing: 'border-box' }}
          dangerouslySetInnerHTML={{ __html: html }}
        />
      )}
      <VenueMap venue={venue} city={city} isMobile={isMobile} />
    </Layout>
  );
}
