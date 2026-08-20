import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import 'dayjs/locale/id';

dayjs.extend(relativeTime);
dayjs.locale('id');

/** Gmail list rule: time for today, "12 Agu" for this year, otherwise the year. */
export function listDate(value) {
  if (!value) return '';
  const date = dayjs(value);
  const now = dayjs();
  if (date.isSame(now, 'day')) return date.format('HH.mm');
  if (date.isSame(now, 'year')) return date.format('D MMM');
  return date.format('DD/MM/YY');
}

export function fullDate(value) {
  if (!value) return '';
  return dayjs(value).format('ddd, D MMM YYYY [pukul] HH.mm');
}

export function relative(value) {
  if (!value) return '';
  return dayjs(value).fromNow();
}

export function initials(nameOrEmail = '') {
  const source = String(nameOrEmail).trim();
  if (!source) return '?';
  const parts = source.replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean);
  if (parts.length === 0) return source[0].toUpperCase();
  if (parts.length === 1) return parts[0][0].toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

const AVATAR_COLORS = [
  '#1a73e8', '#d93025', '#188038', '#e37400', '#673ab7',
  '#0b8043', '#c5221f', '#3949ab', '#00897b', '#8e24aa',
];

/** Stable colour per address, so the same sender always looks the same. */
export function colorFor(seed = '') {
  let hash = 0;
  for (let i = 0; i < seed.length; i += 1) hash = (hash * 31 + seed.charCodeAt(i)) >>> 0;
  return AVATAR_COLORS[hash % AVATAR_COLORS.length];
}

export function formatBytes(bytes = 0) {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  const value = bytes / 1024 ** index;
  return `${value >= 10 || index === 0 ? Math.round(value) : value.toFixed(1)} ${units[index]}`;
}

export function displayName(person) {
  if (!person) return '';
  if (typeof person === 'string') return person.replace(/@.*/, '');
  return person.name || person.email || '';
}

/** "Sinta, aku, Budi 3" style sender summary for a conversation row. */
export function senderSummary(senders = [], count = 1, me = '') {
  const names = senders.map((sender) => (sender === me ? 'aku' : String(sender).replace(/@.*/, '')));
  const unique = [...new Set(names)];
  const label = unique.length > 3 ? `${unique[0]}, .., ${unique[unique.length - 1]}` : unique.join(', ');
  return count > 1 ? `${label} ${count}` : label;
}

export function quotaPercent(used = 0, quota = 1) {
  return Math.min(100, Math.round((used / Math.max(quota, 1)) * 100));
}
