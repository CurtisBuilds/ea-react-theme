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


// Split the editor content into sections at each <h2>, so each gets its own band:
//  - reviews (has <blockquote>) → light-blue band, one white card per review
//  - faq (has <h3>)             → grey band, one white card per question
//  - info (anything else)       → white band; "Bring:" / "Parking:" lines become callouts
function buildSections(html) {
  if (!html || typeof document === 'undefined') return [];
  const box = document.createElement('div');
  box.innerHTML = html;
  const sections = [];
  let cur = null;
  Array.from(box.childNodes).forEach((n) => {
    if (n.nodeType === 1 && n.tagName === 'H2') { cur = { nodes: [n] }; sections.push(cur); return; }
    if (n.nodeType === 3 && !n.textContent.trim()) return;
    if (n.nodeType === 8) return;
    if (!cur) { cur = { nodes: [] }; sections.push(cur); }
    cur.nodes.push(n);
  });
  return sections.map(({ nodes }) => {
    const heading = nodes[0] && nodes[0].tagName === 'H2' ? nodes.shift() : null;
    const has = (tag) => nodes.some((n) => n.nodeType === 1 && (n.tagName === tag || (n.querySelector && n.querySelector(tag.toLowerCase()))));
    const kind = has('BLOCKQUOTE') ? 'reviews' : has('H3') ? 'faq' : 'info';
    let body = '';
    if (kind === 'faq') {
      let open = false;
      nodes.forEach((n) => {
        if (n.nodeType === 1 && n.tagName === 'H3') { if (open) body += '</div>'; body += '<div class="ea-ft-card ea-ft-qa">'; open = true; }
        body += n.outerHTML || '';
      });
      if (open) body += '</div>';
    } else if (kind === 'reviews') {
      const quotes = nodes.filter((n) => n.nodeType === 1 && n.tagName === 'BLOCKQUOTE');
      const rest = nodes.filter((n) => !(n.nodeType === 1 && n.tagName === 'BLOCKQUOTE'));
      body = rest.map((n) => n.outerHTML || '').join('')
        + '<div class="ea-ft-quotes">' + quotes.map((q) => '<figure class="ea-ft-card ea-ft-quote"><div class="ea-ft-stars" aria-label="5 stars">★★★★★</div>' + q.innerHTML + '</figure>').join('') + '</div>';
    } else {
      body = nodes.map((n) => {
        if (n.nodeType === 1 && n.tagName === 'P' && n.firstElementChild && n.firstElementChild.tagName === 'STRONG' && n.innerHTML.trim().startsWith('<strong>')) {
          return '<p class="ea-ft-callout">' + n.innerHTML + '</p>';
        }
        return n.outerHTML || '';
      }).join('');
    }
    return { kind, heading: heading ? heading.textContent : '', body };
  });
}

const FT_CSS = `
.ea-ft-band{width:100%;box-sizing:border-box}
.ea-ft-band--info{background:var(--ea-white,#fff)}
.ea-ft-band--reviews{background:#EAF8FD}
.ea-ft-band--faq{background:var(--ea-mist,#F2F2F2)}
.ea-ft-inner{max-width:720px;margin:0 auto;box-sizing:border-box}
.ea-ft-inner h2{margin:0 0 20px}
.ea-ft-inner p{margin:0 0 12px;line-height:1.55}
.ea-ft-inner p:last-child{margin-bottom:0}
.ea-ft-callout{background:var(--ea-mist,#F2F2F2);border-left:4px solid var(--ea-blue,#0092DB);border-radius:8px;padding:12px 16px}
.ea-ft-card{background:#fff;border:1px solid var(--border-card,#E5E5E5);border-radius:12px;padding:18px 20px;box-sizing:border-box}
.ea-ft-quotes{display:grid;grid-template-columns:1fr;gap:14px}
@media (min-width:768px){.ea-ft-quotes{grid-template-columns:1fr 1fr;gap:18px}}
.ea-ft-quote{margin:0;display:flex;flex-direction:column;box-shadow:0 2px 10px rgba(16,65,79,.06)}
.ea-ft-quote p{margin:0 0 12px;font-size:16px}
.ea-ft-quote cite{margin-top:auto;font-style:normal;font-weight:600;font-size:14px;color:var(--ea-slate,#47636B)}
.ea-ft-stars{color:#F5B400;letter-spacing:2px;font-size:16px;margin-bottom:8px}
.ea-ft-qa{margin-bottom:12px}
.ea-ft-qa:last-child{margin-bottom:0}
.ea-ft-qa h3{margin:0 0 6px}
`;

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
  const [sections] = useState(() => buildSections(html));
  const rows = useProgramsFeed();
  const choices = buildTrialSessionChoices(rows || [], ['bad']);
  const [picked, setPicked] = useState(null);
  // Map: the picked session's venue, else the next upcoming trial's venue.
  const shown = (picked && picked.venue) ? picked : (choices[0] || null);
  const venue = shown ? shown.venue : '';
  const row = shown && (rows || []).find((r) => String(r.ProgramID || r.ListingCode || '') === String(shown.sessionId || '').split('@')[0]);
  const city = row ? row.City : '';

  return (
    <Layout overrides={{ minimal: true }}>
      <FreeTrialSection DS={DS} isMobile={isMobile} t={t} variant="landing" title={title} onSessionPick={setPicked} />
      {sections.length > 0 && (
        <div className="ea-free-trial-content" style={{ marginTop: isMobile ? 24 : 40 }}>
          <style>{FT_CSS}</style>
          {sections.map((sec, i) => (
            <section key={i} className={`ea-ft-band ea-ft-band--${sec.kind}`} style={{ padding: isMobile ? '32px 20px' : '56px 24px' }}>
              <div className="ea-ft-inner ea-blank-content__body">
                {sec.heading && <h2>{sec.heading}</h2>}
                <div dangerouslySetInnerHTML={{ __html: sec.body }} />
              </div>
            </section>
          ))}
        </div>
      )}
      <VenueMap venue={venue} city={city} isMobile={isMobile} />
    </Layout>
  );
}
