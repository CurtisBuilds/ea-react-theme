import { useEffect, useMemo, useRef, useState } from 'react';
import { Layout, useDSComponents, useViewport, getThemeData, FB } from '../lib/shared.jsx';
import { CITY_COORDS } from '../data/venueCoords.js';

const PROGRAMS_DATA_URL = 'https://curtisbuilds.github.io/ea-programs-json/data/programs.json';

const DEFAULT_MARKERS = [
  'Newmarket, ON|44.0592|-79.4613',
  'Aurora, ON|44.0065|-79.4504',
  'Georgina, ON|44.2963|-79.4360',
  'Barrie, ON|44.3894|-79.6903',
  'Bradford, ON|44.1113|-79.5614',
  'Cambridge, ON|43.3436|-80.3063',
  'East Gwillimbury, ON|44.1279|-79.4518',
  'Essa, ON|44.3138|-79.8846',
  'Innisfil, ON|44.3001|-79.6117',
  'King City, ON|43.9285|-79.5269',
  'Oro-Medonte, ON|44.5590|-79.6545',
  'Richmond Hill, ON|43.8687|-79.4352',
  'Vaughan, ON|43.8563|-79.5085',
];

const LEAFLET_CSS_URL = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
const LEAFLET_JS_URL = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';

function loadLeaflet() {
  if (window.L) return Promise.resolve(window.L);
  if (window.__eaLeafletLoading) return window.__eaLeafletLoading;
  window.__eaLeafletLoading = new Promise((resolve, reject) => {
    if (!document.querySelector(`link[href="${LEAFLET_CSS_URL}"]`)) {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = LEAFLET_CSS_URL;
      document.head.appendChild(link);
    }
    const script = document.createElement('script');
    script.src = LEAFLET_JS_URL;
    script.async = true;
    script.onload = () => resolve(window.L);
    script.onerror = () => reject(new Error('Failed to load Leaflet'));
    document.body.appendChild(script);
  });
  return window.__eaLeafletLoading;
}

const DEFAULT_PROGRAMS = [
  'Basketball|Youth lessons, house leagues, rep pathways, camps, and community events.|https://elevationathletics.ca/home|',
  'Pickleball|Adult and youth lessons, beginner clinics, leagues, and social play.|https://eapickleball.com|',
  'Badminton|Introductory lessons, junior leagues, camps, and seasonal programs.|https://eabadminton.com|',
  'Seasonal Sport Programs|Flexible pilot programs built around your community space, schedule, and demand.||',
];

const DEFAULT_STEPS = [
  'Plan|We align with your recreation team on facility access, age groups, registration goals, and seasonal timing.',
  'Promote|We help position the program clearly for families with simple registration paths and polished program assets.',
  'Deliver|Our team manages coaching, programming, communication, and on-site delivery so the experience feels organized from day one.',
];

function pick(value, fallback) {
  return value === undefined || value === null || value === '' ? fallback : value;
}

const norm = (value) => String(value || '').trim().toLowerCase();

function titleCaseCity(city) {
  return String(city || '').trim().replace(/\b\w/g, (char) => char.toUpperCase());
}

function splitRows(value, fallbackRows) {
  const raw = pick(value, fallbackRows.join('\n'));
  return String(raw)
    .split('\n')
    .map((row) => row.trim())
    .filter(Boolean);
}

function normalizeEditableUrl(value) {
  const url = String(value || '').trim();
  if (!url) return '';
  if (/^(https?:|mailto:|tel:|#|\/)/i.test(url)) return url;
  return url.includes('.') ? `https://${url}` : url;
}

function parseMarkers(value) {
  return splitRows(value, DEFAULT_MARKERS).map((row) => {
    const [label, lat, lng] = row.split('|').map((part) => part.trim());
    const latitude = Number(lat);
    const longitude = Number(lng);
    return {
      label,
      lat: Number.isFinite(latitude) ? latitude : null,
      lng: Number.isFinite(longitude) ? longitude : null,
    };
  }).filter((item) => item.label && item.lat !== null && item.lng !== null);
}

function buildTownshipMarkers(rows, fallbackMarkers) {
  if (!Array.isArray(rows) || !rows.length) return fallbackMarkers;

  const towns = new Map();
  rows.forEach((row) => {
    const category = String(row.Category || row.category || '').trim().toUpperCase();
    if (category !== 'TS') return;

    const city = String(row.City || row.city || '').trim();
    if (!city) return;

    const key = norm(city);
    if (!towns.has(key)) {
      towns.set(key, {
        label: `${titleCaseCity(city)}, ON`,
        key,
      });
    }
  });

  return [...towns.values()]
    .map((town) => {
      const coords = CITY_COORDS[town.key];
      if (!coords) return null;
      return { ...town, lat: coords.lat, lng: coords.lng };
    })
    .filter(Boolean)
    .sort((a, b) => a.label.localeCompare(b.label));
}

function parsePrograms(value) {
  return splitRows(value, DEFAULT_PROGRAMS).map((row) => {
    const [title, description, url, image] = row.split('|').map((part) => part.trim());
    return {
      title,
      description,
      url: normalizeEditableUrl(url),
      image: normalizeEditableUrl(image),
    };
  }).filter((item) => item.title);
}

function parseSteps(value) {
  return splitRows(value, DEFAULT_STEPS).map((row) => {
    const [title, description] = row.split('|').map((part) => part.trim());
    return { title, description };
  }).filter((item) => item.title);
}

function MapPanel({ markers, isMobile }) {
  const mapRef = useRef(null);
  const mapInstance = useRef(null);

  useEffect(() => {
    let cancelled = false;

    loadLeaflet().then((L) => {
      if (cancelled || !mapRef.current) return;

      if (mapInstance.current) {
        mapInstance.current.remove();
        mapInstance.current = null;
      }

      const map = L.map(mapRef.current, {
        scrollWheelZoom: false,
        attributionControl: false,
        zoomControl: true,
      });
      mapInstance.current = map;

      L.control.attribution({ prefix: '' })
        .addAttribution('&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>')
        .addTo(map);

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
      }).addTo(map);

      const icon = L.divIcon({
        className: 'ea-partner-town-pin',
        html: '<span></span>',
        iconSize: [24, 24],
        iconAnchor: [12, 12],
      });

      const bounds = [];
      markers.forEach((marker) => {
        const pin = L.marker([marker.lat, marker.lng], { icon }).addTo(map);
        pin.bindTooltip(marker.label, {
          direction: 'top',
          offset: [0, -10],
          opacity: 0.96,
          className: 'ea-partner-town-tooltip',
        });
        bounds.push([marker.lat, marker.lng]);
      });

      if (bounds.length) {
        map.fitBounds(L.latLngBounds(bounds), {
          padding: isMobile ? [28, 28] : [44, 44],
          maxZoom: 10,
        });
      } else {
        map.setView([44.1, -79.55], 8);
      }

      setTimeout(() => map.invalidateSize(false), 80);
    }).catch(() => {
      // The visible fallback below remains in place if Leaflet cannot load.
    });

    return () => {
      cancelled = true;
      if (mapInstance.current) {
        mapInstance.current.remove();
        mapInstance.current = null;
      }
    };
  }, [markers, isMobile]);

  return (
    <div className="ea-partnership-map" style={{
      background: '#EAF9FF',
      borderRadius: 10,
      minHeight: isMobile ? 320 : 500,
      position: 'relative',
      zIndex: 0,
      overflow: 'hidden',
      boxShadow: '0 10px 28px rgba(16,65,79,.08)',
      border: '1px solid #D8EEF6',
    }}>
      <div ref={mapRef} style={{ position: 'absolute', inset: 0, zIndex: 0 }} />
      <div aria-hidden="true" style={{
        position: 'absolute',
        inset: 0,
        zIndex: 1,
        background: 'linear-gradient(180deg, rgba(255,255,255,.10), rgba(255,255,255,0))',
        pointerEvents: 'none',
      }} />
    </div>
  );
}

function ProgramCard({ item, isMobile }) {
  const content = (
    <article style={{
      border: '1px solid #E5E5E5',
      borderRadius: 8,
      overflow: 'hidden',
      background: '#fff',
      height: '100%',
      boxShadow: '0 2px 12px rgba(16,65,79,.05)',
    }}>
      {item.image ? (
        <img src={item.image} alt="" style={{ width: '100%', aspectRatio: '16/9', objectFit: 'cover', display: 'block' }} />
      ) : (
        <div aria-hidden="true" style={{ aspectRatio: '16/9', background: '#BDEEFF' }} />
      )}
      <div style={{ padding: isMobile ? 18 : 22 }}>
        <h3 style={{ ...FB.h(isMobile ? 27 : 32), marginBottom: 10 }}>{item.title}</h3>
        <p style={{
          margin: 0,
          fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
          fontSize: 15,
          lineHeight: 1.45,
          color: '#1E526E',
        }}>{item.description}</p>
      </div>
    </article>
  );

  return item.url
    ? <a href={item.url} style={{ color: 'inherit', textDecoration: 'none' }}>{content}</a>
    : content;
}

export default function CommunityPartnershipsPage() {
  const DS = useDSComponents();
  const { isMobile, isTablet } = useViewport();
  const t = getThemeData();
  const p = t.partnerships || {};
  const Button = DS.Button;
  const [programRows, setProgramRows] = useState(null);
  const fallbackMarkers = useMemo(() => parseMarkers(p.mapMarkers), [p.mapMarkers]);
  const markers = useMemo(() => buildTownshipMarkers(programRows, fallbackMarkers), [programRows, fallbackMarkers]);
  const programs = parsePrograms(p.programRows);
  const steps = parseSteps(p.processRows);
  const ctaUrl = pick(p.ctaUrl, 'mailto:municipal@elevationathletics.ca');
  const ctaLabel = pick(p.ctaLabel, 'Partner With Us');
  const sectionPad = isMobile ? '46px 20px' : '78px 40px';

  useEffect(() => {
    let cancelled = false;
    fetch(`${PROGRAMS_DATA_URL}?v=${Date.now()}`)
      .then((res) => {
        if (!res.ok) throw new Error('Programs feed failed');
        return res.json();
      })
      .then((data) => {
        if (!cancelled) setProgramRows(Array.isArray(data) ? data : []);
      })
      .catch(() => {
        if (!cancelled) setProgramRows([]);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const ctaButton = Button ? (
    <Button as="a" href={ctaUrl} variant="primary">{ctaLabel}</Button>
  ) : (
    <a href={ctaUrl} style={{ ...FB.btn('primary'), textDecoration: 'none' }}>{ctaLabel}</a>
  );

  return (
    <Layout>
      <main style={{ background: '#fff' }}>
        <style>{`
          .ea-partnership-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(16,65,79,.08);
          }
          .ea-partnership-map {
            isolation: isolate;
            z-index: 0;
          }
          .ea-partnership-map .leaflet-pane,
          .ea-partnership-map .leaflet-top,
          .ea-partnership-map .leaflet-bottom {
            z-index: 1;
          }
          .ea-partnership-map .leaflet-control {
            z-index: 2;
          }
          .ea-partnership-map .leaflet-tooltip-pane {
            z-index: 3;
          }
          .ea-partner-town-pin span {
            display: block;
            width: 22px;
            height: 22px;
            border-radius: 999px;
            background: #0092DB;
            border: 5px solid #fff;
            box-shadow: 0 3px 12px rgba(16,65,79,.28);
          }
          .ea-partner-town-tooltip {
            border: none;
            border-radius: 7px;
            box-shadow: 0 4px 14px rgba(16,65,79,.16);
            color: #10414F;
            font-family: var(--font-body, "Inclusive Sans", sans-serif);
            font-weight: 700;
          }
        `}</style>

        <section style={{
          maxWidth: 1280,
          margin: '0 auto',
          padding: isMobile ? '44px 20px 34px' : '82px 40px 62px',
          display: 'grid',
          gridTemplateColumns: isTablet ? '1fr' : '0.95fr 1.05fr',
          gap: isMobile ? 34 : 72,
          alignItems: 'center',
        }}>
          <div>
            <h1 style={{
              ...FB.h(isMobile ? 52 : 84),
              maxWidth: 650,
            }}>
              {pick(p.heroHeading, 'Community Partnership Programs')}
            </h1>
            <p style={{
              margin: isMobile ? '22px 0 0' : '30px 0 0',
              maxWidth: 620,
              fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
              fontSize: isMobile ? 20 : 28,
              lineHeight: 1.38,
              color: '#1E526E',
            }}>
              {pick(p.heroLead, 'Bring inclusive, high-quality sport programming to your community with a team that can help plan, promote, and deliver it.')}
            </p>
            <p style={{
              margin: '28px 0 0',
              maxWidth: 620,
              fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
              fontSize: isMobile ? 16 : 20,
              lineHeight: 1.5,
              color: '#1E526E',
              whiteSpace: 'pre-line',
            }}>
              {pick(p.heroBody, 'Elevation Athletics partners with township and municipal recreation teams to offer accessible basketball, pickleball, badminton, and seasonal sport programs for families across Canada.')}
            </p>
            <div style={{ marginTop: 34 }}>{ctaButton}</div>
          </div>
          <MapPanel markers={markers} isMobile={isMobile} />
        </section>

        <section style={{ maxWidth: 1180, margin: '0 auto', padding: sectionPad }}>
          <div style={{ textAlign: 'center', maxWidth: 760, margin: '0 auto 34px' }}>
            <h2 style={FB.h(isMobile ? 42 : 58)}>
              {pick(p.programsHeading, 'Programs We Can Bring to Your Community')}
            </h2>
            <p style={{
              margin: '14px 0 0',
              fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
              color: '#1E526E',
              fontSize: isMobile ? 16 : 19,
              lineHeight: 1.45,
            }}>
              {pick(p.programsIntro, 'Choose the sports and formats that match your community. Each card can be edited, linked, or removed from the Customizer.')}
            </p>
          </div>
          <div style={{
            display: 'grid',
            gridTemplateColumns: isMobile ? '1fr' : 'repeat(4, minmax(0, 1fr))',
            gap: 18,
          }}>
            {programs.map((item) => (
              <div className="ea-partnership-card" key={item.title} style={{ transition: 'transform .18s ease, box-shadow .18s ease' }}>
                <ProgramCard item={item} isMobile={isMobile} />
              </div>
            ))}
          </div>
        </section>

        <section style={{ background: '#F3FBFE' }}>
          <div style={{ maxWidth: 1180, margin: '0 auto', padding: sectionPad }}>
            <div style={{ textAlign: 'center', maxWidth: 720, margin: '0 auto 34px' }}>
              <h2 style={FB.h(isMobile ? 42 : 58)}>
                {pick(p.processHeading, 'How We Do It')}
              </h2>
              <p style={{
                margin: '14px 0 0',
                fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
                color: '#1E526E',
                fontSize: isMobile ? 16 : 19,
                lineHeight: 1.45,
              }}>
                {pick(p.processIntro, 'We make it easier for municipal teams to add quality sport programming without building everything from scratch.')}
              </p>
            </div>
            <div style={{
              display: 'grid',
              gridTemplateColumns: isMobile ? '1fr' : 'repeat(3, 1fr)',
              gap: 18,
            }}>
              {steps.map((step, index) => (
                <article key={step.title} style={{ ...FB.card, padding: isMobile ? 22 : 28 }}>
                  <span style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    width: 34,
                    height: 34,
                    borderRadius: 999,
                    background: '#0092DB',
                    color: '#fff',
                    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
                    fontWeight: 800,
                    marginBottom: 18,
                  }}>{index + 1}</span>
                  <h3 style={{ ...FB.h(isMobile ? 30 : 36), marginBottom: 12 }}>{step.title}</h3>
                  <p style={{
                    margin: 0,
                    fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
                    color: '#1E526E',
                    fontSize: 16,
                    lineHeight: 1.45,
                  }}>{step.description}</p>
                </article>
              ))}
            </div>
          </div>
        </section>

        <section style={{ maxWidth: 880, margin: '0 auto', padding: isMobile ? '52px 20px 64px' : '86px 40px 96px', textAlign: 'center' }}>
          <h2 style={FB.h(isMobile ? 42 : 58)}>
            {pick(p.finalHeading, 'Bring Sports to Your Community')}
          </h2>
          <p style={{
            margin: '16px auto 28px',
            maxWidth: 700,
            fontFamily: 'var(--font-body, "Inclusive Sans", sans-serif)',
            color: '#1E526E',
            fontSize: isMobile ? 17 : 21,
            lineHeight: 1.45,
          }}>
            {pick(p.finalBody, 'If you are part of a township or municipal recreation department, reach out and we can talk through what a partnership could look like.')}
          </p>
          {ctaButton}
        </section>
      </main>
    </Layout>
  );
}
