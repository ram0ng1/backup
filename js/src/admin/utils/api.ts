import app from 'flarum/admin/app';

/** Trim trailing slashes off the configured API URL. */
export function apiUrl(): string {
  return (app.forum.attribute<string>('apiUrl') || '/api').replace(/\/+$/, '');
}

export function fmtBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let i = 0;
  let n = bytes;
  while (n >= 1024 && i < units.length - 1) {
    n /= 1024;
    i++;
  }
  return n.toFixed(n >= 100 || i === 0 ? 0 : 1) + ' ' + units[i];
}
