import app from 'flarum/admin/app';
import type { FlarumRequestOptions } from 'flarum/common/Application';

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

/** Best-effort extraction of a human-readable detail from a RequestError or thrown object. */
export function errorDetail(raw: any, fallback?: string): string {
  const detail = raw?.response?.errors?.[0]?.detail ?? raw?.detail ?? (typeof raw?.message === 'string' ? raw.message : undefined);
  if (detail) return String(detail);
  if (fallback) return fallback;
  return String(app.translator.trans('ramon-backup.admin.errors.generic'));
}

export interface ApiRequestOptions<T> extends FlarumRequestOptions<T> {
  /** When false, suppress the auto toast — caller will surface the error itself. */
  surface?: boolean;
  /** Fallback message used by surface and re-thrown to callers as `.detail`. */
  fallbackMessage?: string;
}

/**
 * Wrapper around `app.request` that:
 *   - logs every failure to console.error with action + URL;
 *   - extracts the JSON:API error detail when present;
 *   - shows a Flarum alert (unless `surface: false`);
 *   - rethrows so the caller can still branch on the failure.
 *
 * O `errorHandler` vazio desliga o alerta padrão do Flarum: quem mostra
 * o erro é este wrapper (ou o chamador, com `surface: false`), senão
 * cada falha aparecia duas vezes. O log leva só status e detalhe, nunca
 * o objeto do erro: ele carrega o corpo da requisição, e no import o
 * corpo tem a chave privada.
 */
export async function apiRequest<T>(opts: ApiRequestOptions<T>): Promise<T> {
  const { surface, fallbackMessage, ...requestOptions } = opts;

  try {
    return (await app.request<T>({ ...requestOptions, errorHandler: () => {} })) as T;
  } catch (raw: any) {
    const detail = errorDetail(raw, fallbackMessage);
    console.error('[backup] api error', opts.method, opts.url, raw?.status, detail);
    if (surface !== false) {
      app.alerts.show({ type: 'error' }, detail);
    }
    // Só grava o detalhe quando ele é específico: o genérico em `.detail`
    // esconderia o fallback que o chamador passa para errorDetail().
    if (fallbackMessage && raw && typeof raw === 'object' && !raw.detail) {
      try {
        raw.detail = detail;
      } catch {
        /* read-only, ignore */
      }
    }
    throw raw;
  }
}
