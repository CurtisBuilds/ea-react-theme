import { useEffect, useState } from 'react';

export const CITIES_DATA_URL = 'https://curtisbuilds.github.io/ea-programs-json/data/cities.json';

export const norm = (value) => String(value || '').trim().toLowerCase();

export function slugify(value) {
  return norm(value).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}

export function urlPathSegments(rawUrl) {
  const raw = String(rawUrl || '').trim();
  if (!raw) return [];
  const normalizedRaw = /^[a-z][a-z0-9+.-]*:\/\//i.test(raw) || raw.startsWith('/')
    ? raw
    : `https://${raw}`;
  try {
    return new URL(normalizedRaw, typeof window !== 'undefined' ? window.location.origin : 'https://elevationathletics.ca')
      .pathname
      .split('/')
      .filter(Boolean)
      .map(slugify);
  } catch (_err) {
    return raw.split('/').filter(Boolean).map(slugify);
  }
}

export function cityRecordPageSegments(city) {
  return urlPathSegments(city?.PageURL || city?.pageUrl || city?.URL);
}

export function cityRecordSlug(city) {
  return slugify(city?.City || city?.city);
}

export function cityRecordMatchesSlug(city, slug) {
  const target = slugify(slug);
  if (!target) return false;
  if (cityRecordSlug(city) === target) return true;
  return cityRecordPageSegments(city).some((segment) => segment === target || segment.startsWith(`${target}-`));
}

export function useCitiesFeed() {
  const [cities, setCities] = useState([]);

  useEffect(() => {
    let alive = true;
    fetch(CITIES_DATA_URL)
      .then((response) => (response.ok ? response.json() : Promise.reject(new Error('bad response'))))
      .then((data) => {
        if (!alive) return;
        setCities(Array.isArray(data) ? data : data.cities || data.data || []);
      })
      .catch(() => {
        if (alive) setCities([]);
      });
    return () => { alive = false; };
  }, []);

  return cities;
}

export function findCityRecord(cities, slug = '', cityName = '', province = '') {
  const targetSlug = slugify(slug);
  const cityNameKey = slugify(cityName);
  const provinceKey = norm(province);

  return cities.find((city) => targetSlug && cityRecordPageSegments(city).some((segment) => segment === targetSlug))
    || cities.find((city) => targetSlug && cityRecordSlug(city) === targetSlug)
    || cities.find((city) => cityNameKey && cityRecordSlug(city) === cityNameKey && (!provinceKey || norm(city.Province) === provinceKey))
    || cities.find((city) => cityNameKey && cityRecordSlug(city) === cityNameKey)
    || cities.find((city) => targetSlug && cityRecordMatchesSlug(city, targetSlug))
    || null;
}

export function localCityHref(city, t, fallbackSlug = '') {
  const pageSlug = cityRecordPageSegments(city)[0] || slugify(fallbackSlug);
  return `${t.siteUrl || ''}/${pageSlug}/`;
}

export function programCityPageSlug(program) {
  return slugify(
    program?.pageIdentifyer
    || program?.pageIdentifier
    || program?.PageIdentifier
    || program?.['Page Identifier']
  ) || urlPathSegments(program?.URL || program?.url)[0] || '';
}

function normalizeProgramUrl(rawUrl, t) {
  const raw = String(rawUrl || '').trim();
  if (!raw) return '';
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(raw) || raw.startsWith('/')) return raw;
  return `https://${raw}`;
}

export function summaryCityHref(summary, cities, t) {
  const targetSlug = slugify(summary?.slug || summary?.city);
  const candidates = (summary?.programs || [])
    .map((program) => normalizeProgramUrl(program?.URL || program?.url, t))
    .filter(Boolean);
  const cleanMatch = candidates.find((href) => {
    const segments = urlPathSegments(href);
    return segments.length === 1 && segments[0] === targetSlug;
  });
  if (cleanMatch) return cleanMatch;

  const citySlugMatch = candidates.find((href) => urlPathSegments(href).some((segment) => segment === targetSlug));
  if (citySlugMatch) return citySlugMatch;

  const city = findCityRecord(cities, summary?.slug, summary?.city, summary?.province);
  const pageSlug = cityRecordSlug(city) || summary?.slug || slugify(summary?.city);
  return `${t.siteUrl || ''}/${pageSlug}/`;
}

export function nearbyCityRecords(city, cities) {
  const ids = String(city?.NearbyActive || '')
    .split(',')
    .map((value) => Number(String(value).trim()))
    .filter((value) => Number.isFinite(value));

  if (!ids.length) return [];

  const byId = new Map(cities.map((item) => [Number(item.CityID), item]));
  return ids.map((id) => byId.get(id)).filter(Boolean);
}
