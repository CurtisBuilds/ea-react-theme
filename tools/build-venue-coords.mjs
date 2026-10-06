#!/usr/bin/env node
/**
 * tools/build-venue-coords.mjs
 *
 * Resolves the programs feed's `LocationLink` values (shortened
 * https://maps.app.goo.gl/... links) into real lat/lng pairs and writes
 * src/data/venueCoords.generated.js.
 *
 * The feed itself lives in another repo and carries no coordinates, so the
 * theme would otherwise be stuck pinning every program at a hardcoded city
 * centroid. Running this offline keeps the browser free of geocoding: no API
 * key, no CORS, nothing to configure on deploy.
 *
 * Usage:
 *   node tools/build-venue-coords.mjs             write the generated file
 *   node tools/build-venue-coords.mjs --dry-run   report only, write nothing
 *   node tools/build-venue-coords.mjs --check     exit 1 if the file is stale
 *
 * Re-run whenever the feed adds venues. Existing values are merged, never
 * clobbered, so one rate-limited run can't wipe good data. Hand-fixes belong
 * in src/data/venueCoords.js, which this script never touches.
 */
import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const PROGRAMS_DATA_URL = 'https://curtisbuilds.github.io/ea-programs-json/data/programs.json';

const __dirname = dirname(fileURLToPath(import.meta.url));
const OUT_PATH = join(__dirname, '..', 'src', 'data', 'venueCoords.generated.js');

// Deliberately a bare UA. Google serves a JS app shell (no coordinates in the
// HTML) to anything that looks like a real browser, and a plain 302 to simple
// clients. A full Chrome UA string here silently breaks every lookup.
const UA = 'Mozilla/5.0';
const DELAY_MS = 400;
const RETRIES = 2;
const MAX_HOPS = 5;

// The feed spans Ontario, BC and Alberta, so this gate is Canada-wide. It only
// exists to catch a redirect that landed somewhere absurd (a consent page, a
// generic /maps view) rather than to validate the region.
const BBOX = { minLat: 41, maxLat: 84, minLng: -142, maxLng: -52 };

const args = new Set(process.argv.slice(2));
const DRY_RUN = args.has('--dry-run');
const CHECK = args.has('--check');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const norm = (v) => String(v || '').trim().toLowerCase();

export function linkKey(link) {
  return String(link || '').trim().replace(/\/+$/, '').toLowerCase();
}

function extractCoords(text) {
  // !3d<lat>!4d<lng> is the authoritative place coordinate. The /@lat,lng form
  // is the map viewport centre, which is usually the same but drifts when the
  // link was made from a panned view, so it's only a fallback.
  let m = /!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/.exec(text);
  if (m) return { lat: Number(m[1]), lng: Number(m[2]), precision: 'place' };

  m = /@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/.exec(text);
  if (m) return { lat: Number(m[1]), lng: Number(m[2]), precision: 'viewport' };

  m = /[?&](?:q|ll|center)=(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/.exec(text);
  if (m) return { lat: Number(m[1]), lng: Number(m[2]), precision: 'query' };

  return null;
}

function extractPlaceName(text) {
  const m = /\/place\/([^/@?]+)/.exec(text);
  if (!m) return '';
  try {
    return decodeURIComponent(m[1].replace(/\+/g, ' ')).trim();
  } catch {
    return m[1].replace(/\+/g, ' ').trim();
  }
}

function inBbox(c) {
  return c
    && Number.isFinite(c.lat) && Number.isFinite(c.lng)
    && c.lat >= BBOX.minLat && c.lat <= BBOX.maxLat
    && c.lng >= BBOX.minLng && c.lng <= BBOX.maxLng;
}

async function resolveOne(link) {
  for (let attempt = 0; attempt <= RETRIES; attempt++) {
    try {
      // Walk the redirect chain by hand and inspect each Location header. The
      // coordinates live in the redirect target, not in any response body.
      let url = link;
      for (let hop = 0; hop < MAX_HOPS; hop++) {
        const res = await fetch(url, {
          redirect: 'manual',
          headers: { 'User-Agent': UA, 'Accept-Language': 'en-CA,en;q=0.9' },
        });

        const next = res.headers.get('location');
        const candidate = next || res.url || url;

        const coords = extractCoords(candidate);
        if (coords) {
          if (!inBbox(coords)) return { error: `coords out of range: ${coords.lat},${coords.lng}` };
          return { coords, place: extractPlaceName(candidate) };
        }

        if (!next) break;
        url = new URL(next, url).toString();
      }
      if (attempt === RETRIES) return { error: 'no coordinates in redirect chain' };
    } catch (err) {
      if (attempt === RETRIES) return { error: err.message };
    }
    await sleep(DELAY_MS * (attempt + 2));
  }
  return { error: 'unreachable' };
}

async function loadExisting() {
  try {
    const mod = await import(`${OUT_PATH}?t=${Date.now()}`);
    return {
      venues: mod.GENERATED_VENUE_COORDS || {},
      byName: mod.GENERATED_VENUE_COORDS_BY_NAME || {},
      cities: mod.GENERATED_CITY_COORDS || {},
    };
  } catch {
    return { venues: {}, byName: {}, cities: {} };
  }
}

function renderFile({ venues, byName, cities }) {
  const sortedKeys = Object.keys(venues).sort();
  const venueLines = sortedKeys.map((k) => {
    const v = venues[k];
    const names = JSON.stringify(v.names || []);
    return `  ${JSON.stringify(k)}: { lat: ${v.lat}, lng: ${v.lng}, place: ${JSON.stringify(v.place || '')}, names: ${names}, precision: ${JSON.stringify(v.precision)} },`;
  }).join('\n');

  const nameLines = Object.keys(byName).sort().map(
    (k) => `  ${JSON.stringify(k)}: ${JSON.stringify(byName[k])},`
  ).join('\n');

  const cityLines = Object.keys(cities).sort().map((k) => {
    const c = cities[k];
    return `  ${JSON.stringify(k)}: { lat: ${c.lat}, lng: ${c.lng}, from: ${c.from} },`;
  }).join('\n');

  return `/**
 * src/data/venueCoords.generated.js
 *
 * GENERATED FILE - DO NOT EDIT BY HAND.
 * Produced by tools/build-venue-coords.mjs from the live programs feed.
 * Hand-maintained overrides belong in ./venueCoords.js instead; this file is
 * overwritten wholesale on every run.
 */

export const VENUE_COORDS_GENERATED_AT = ${JSON.stringify(new Date().toISOString())};

/* Keyed by the normalized LocationLink (lowercased, no trailing slash). */
export const GENERATED_VENUE_COORDS = {
${venueLines}
};

/* LocationName -> the venue key above, so records with a null link still resolve. */
export const GENERATED_VENUE_COORDS_BY_NAME = {
${nameLines}
};

/* Centroid of each city's resolved venues. Keys are the feed's City strings
 * verbatim (lowercased), including compound ones like "newmarket/aurora". */
export const GENERATED_CITY_COORDS = {
${cityLines}
};
`;
}

async function main() {
  process.stderr.write(`Fetching ${PROGRAMS_DATA_URL}\n`);
  const res = await fetch(`${PROGRAMS_DATA_URL}?v=${Date.now()}`);
  if (!res.ok) throw new Error(`feed fetch failed: ${res.status}`);
  const rows = await res.json();
  if (!Array.isArray(rows)) throw new Error('feed is not an array');

  process.stderr.write(`Feed has ${rows.length} records\n`);

  // link key -> { url, names }. The key is lowercased for stable lookup, but
  // the original-case URL must be kept: goo.gl short ids are case-sensitive.
  const linkNames = new Map();
  const noLinkNames = new Map(); // LocationName -> City, for records with null links
  const cityMembers = new Map(); // city -> Set of link keys

  for (const r of rows) {
    const link = String(r.LocationLink || '').trim();
    const name = String(r.LocationName || '').trim();
    const city = norm(r.City);

    if (link) {
      const k = linkKey(link);
      if (!linkNames.has(k)) linkNames.set(k, { url: link, names: new Set() });
      if (name) linkNames.get(k).names.add(name);
      if (city) {
        if (!cityMembers.has(city)) cityMembers.set(city, new Set());
        cityMembers.get(city).add(k);
      }
    } else if (name) {
      noLinkNames.set(norm(name), { name, city: r.City || '' });
    }
  }

  process.stderr.write(`${linkNames.size} distinct location links to resolve\n\n`);

  const existing = await loadExisting();
  const venues = { ...existing.venues };
  const resolved = [];
  const failed = [];
  const lowConfidence = [];

  let i = 0;
  for (const [key, entry] of linkNames) {
    i++;
    const names = [...entry.names].sort((a, b) => b.length - a.length);
    const label = names[0] || key;
    process.stderr.write(`[${i}/${linkNames.size}] ${label} ... `);

    const out = await resolveOne(entry.url);
    if (out.error) {
      const kept = existing.venues[key];
      process.stderr.write(`FAILED (${out.error})${kept ? ' - keeping previous value' : ''}\n`);
      failed.push({ key, label, error: out.error, kept: Boolean(kept) });
    } else {
      venues[key] = {
        lat: out.coords.lat,
        lng: out.coords.lng,
        place: out.place || label,
        names,
        precision: out.coords.precision,
      };
      resolved.push(key);
      process.stderr.write(`${out.coords.lat}, ${out.coords.lng} (${out.coords.precision})\n`);
      if (out.coords.precision !== 'place') lowConfidence.push({ key, label, precision: out.coords.precision });
    }
    await sleep(DELAY_MS);
  }

  // LocationName -> venue key, so the 8 null-link records can still resolve
  // when a sibling record at the same venue does carry a link.
  const byName = {};
  for (const [key, v] of Object.entries(venues)) {
    for (const n of v.names || []) {
      const k = norm(n);
      if (k && !byName[k]) byName[k] = key;
    }
  }

  // City centroids from whatever resolved. This is what lifts coordinate
  // coverage past the old 5-city hardcoded table without hand-typing lat/lngs.
  const cities = {};
  for (const [city, keys] of cityMembers) {
    const pts = [...keys].map((k) => venues[k]).filter(Boolean);
    if (!pts.length) continue;
    const lat = pts.reduce((a, p) => a + p.lat, 0) / pts.length;
    const lng = pts.reduce((a, p) => a + p.lng, 0) / pts.length;
    cities[city] = { lat: Number(lat.toFixed(6)), lng: Number(lng.toFixed(6)), from: pts.length };
  }

  const contents = renderFile({ venues, byName, cities });

  // ---- report ----
  process.stderr.write('\n' + '='.repeat(60) + '\n');
  process.stderr.write(`Resolved:       ${resolved.length}/${linkNames.size}\n`);
  process.stderr.write(`City centroids: ${Object.keys(cities).length} of ${cityMembers.size} cities\n`);

  if (lowConfidence.length) {
    process.stderr.write(`\nLower confidence (viewport centre, not place coord) - worth eyeballing:\n`);
    lowConfidence.forEach((l) => process.stderr.write(`  - ${l.label} (${l.precision})\n`));
  }
  if (failed.length) {
    process.stderr.write(`\nUnresolved:\n`);
    failed.forEach((f) => process.stderr.write(`  - ${f.label}: ${f.error}${f.kept ? ' (previous value kept)' : ''}\n`));
  }

  const unmatchedNoLink = [...noLinkNames.values()].filter((n) => !byName[norm(n.name)]);
  if (unmatchedNoLink.length) {
    process.stderr.write(`\nNull LocationLink and no name match - add these to MANUAL_VENUE_COORDS_BY_NAME in src/data/venueCoords.js:\n`);
    unmatchedNoLink.forEach((n) => process.stderr.write(`  - "${n.name}" (${n.city})\n`));
  }

  const citiesWithNone = [...cityMembers.keys()].filter((c) => !cities[c]);
  if (citiesWithNone.length) {
    process.stderr.write(`\nCities with zero resolved venues:\n`);
    citiesWithNone.forEach((c) => process.stderr.write(`  - ${c}\n`));
  }
  process.stderr.write('='.repeat(60) + '\n');

  if (CHECK) {
    let current = '';
    try { current = await readFile(OUT_PATH, 'utf8'); } catch {}
    const strip = (s) => s.replace(/export const VENUE_COORDS_GENERATED_AT = .*;\n/, '');
    if (strip(current) === strip(contents)) {
      process.stderr.write('\n--check: up to date\n');
      return;
    }
    process.stderr.write('\n--check: venueCoords.generated.js is STALE, re-run without --check\n');
    process.exit(1);
  }

  if (DRY_RUN) {
    process.stderr.write('\n--dry-run: nothing written\n');
    return;
  }

  await writeFile(OUT_PATH, contents, 'utf8');
  process.stderr.write(`\nWrote ${OUT_PATH}\n`);
}

main().catch((err) => {
  process.stderr.write(`\nFATAL: ${err.stack || err.message}\n`);
  process.exit(1);
});
