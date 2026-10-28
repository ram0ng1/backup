import app from 'flarum/admin/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';

const trans = (key: string) => app.translator.trans(`ramon-backup.admin.errors.${key}`);

export interface BoundaryAttrs extends ComponentAttrs {
  fallback?: (err: unknown, retry: () => void) => Mithril.Children;
  onError?: (err: unknown) => void;
}

/**
 * Mithril doesn't have React's Error Boundary — but a tiny vnode
 * wrapper that try/catches `children` rendering covers the same
 * 90% case: a single throw inside a render path won't blow up the
 * whole admin SPA.
 *
 * Limitation: only catches synchronous exceptions during render. Async
 * Promise rejections still need their own try/catch — this is not a
 * substitute for handling failures in event handlers or API calls.
 */
export class ErrorBoundary extends Component<BoundaryAttrs> {
  protected failed = false;
  protected lastError: unknown = null;

  view(vnode: Mithril.Vnode<BoundaryAttrs, this>) {
    if (this.failed) {
      const retry = () => {
        this.failed = false;
        this.lastError = null;
        m.redraw();
      };
      if (vnode.attrs.fallback) return vnode.attrs.fallback(this.lastError, retry);
      return (
        <div className="Alert Alert--error BackupErrorBoundary">
          <strong>{trans('boundary_title')}</strong>
          <p>{trans('boundary_body')}</p>
          <button type="button" className="Button" onclick={retry}>
            {trans('boundary_retry')}
          </button>
        </div>
      );
    }
    try {
      return vnode.children as Mithril.Children;
    } catch (err) {
      this.failed = true;
      this.lastError = err;
      vnode.attrs.onError?.(err);
      console.error('[backup] render boundary caught', err);
      return null;
    }
  }
}
