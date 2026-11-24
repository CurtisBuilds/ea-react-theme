// Free-trial session timing — "has this session already started?" in Toronto time.
//
// The clock is the SERVER's (the Date header of a same-origin request), shown as
// America/Toronto wall time, so a visitor's wrong device clock or time zone can't
// bring back a past session. Until the server time arrives, the device clock is
// used (still read in Toronto time). The server re-checks every booking anyway
// (ea_trial_session_started() in inc/ea-trial-sessions.php).
import { useEffect, useState } from 'react';

const TZ = 'America/Toronto';
let offsetMs = 0;
let synced = false;
const listeners = new Set();

function syncServerClock() {
  if (synced || typeof window === 'undefined' || typeof fetch !== 'function') return;
  synced = true;
  const url = (window.eaReactData && window.eaReactData.apiUrl) || '/wp-json/';
  fetch(url, { method: 'HEAD', cache: 'no-store', credentials: 'same-origin' })
    .then((r) => {
      const server = Date.parse(r.headers.get('Date') || '');
      if (!Number.isNaN(server)) {
        offsetMs = server - Date.now();
        listeners.forEach((fn) => fn());
      }
    })
    .catch(() => {});
}

/** Toronto wall-clock "now" as a sortable key: "YYYY-MM-DD HH:MM". */
export function torontoNowKey() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(new Date(Date.now() + offsetMs));
  const get = (type) => (parts.find((p) => p.type === type) || {}).value || '00';
  const hour = get('hour') === '24' ? '00' : get('hour');
  return `${get('year')}-${get('month')}-${get('day')} ${hour}:${get('minute')}`;
}

/** Minutes after midnight of a session's start ("11:30 - 1:30 PM" → 690), or null. */
export function sessionStartMinutes(timeText) {
  const text = String(timeText || '');
  const start = text.match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i);
  if (!start) return null;
  const all = text.match(/(am|pm)/gi);
  const toMin = (h, m, mer) => ((Number(h) % 12) + (/pm/i.test(mer || '') ? 12 : 0)) * 60 + Number(m || 0);
  const lastMer = all ? all[all.length - 1] : '';
  let mins = toMin(start[1], start[2], start[3] || lastMer);
  const end = text.slice(start.index + start[0].length).match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i);
  if (!start[3] && end) {
    const endMins = toMin(end[1], end[2], end[3] || lastMer);
    if (mins > endMins) mins -= 12 * 60; // "11:30 - 1:30 PM" starts at 11:30 AM
  }
  return mins >= 0 && mins < 24 * 60 ? mins : null;
}

/** True once the session on `date` (local Date for the day) at `timeText` has started, Toronto time. */
export function sessionHasStarted(date, timeText) {
  if (!(date instanceof Date) || Number.isNaN(date.getTime())) return false;
  const ymd = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  const mins = sessionStartMinutes(timeText);
  // Unknown start time: treat it as started at the end of its day (the old date-only rule).
  const hhmm = mins === null ? '23:59' : `${String(Math.floor(mins / 60)).padStart(2, '0')}:${String(mins % 60).padStart(2, '0')}`;
  return `${ymd} ${hhmm}` <= torontoNowKey();
}

/** Re-render once the server clock is known and every minute, so a session drops off when it starts. */
export function useTrialClock() {
  const [, setTick] = useState(0);
  useEffect(() => {
    const bump = () => setTick((n) => n + 1);
    listeners.add(bump);
    syncServerClock();
    const timer = setInterval(bump, 60 * 1000);
    return () => { listeners.delete(bump); clearInterval(timer); };
  }, []);
}
