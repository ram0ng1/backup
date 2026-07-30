import app from "flarum/admin/app";
import Modal, { IInternalModalAttrs } from "flarum/common/components/Modal";
import Button from "flarum/common/components/Button";
import type Mithril from "mithril";

export interface ConfirmModalAttrs extends IInternalModalAttrs {
  title: Mithril.Children;
  body: Mithril.Children;
  confirmLabel?: Mithril.Children;
  cancelLabel?: Mithril.Children;
  danger?: boolean;
  onConfirm: () => void;
  onCancel?: () => void;
}

/**
 * Dropin replacement for `window.confirm()`. Native confirm breaks the
 * Flarum dark-mode chrome and is silently auto-rejected by some
 * locked-down corporate browsers — this modal stays inside the SPA.
 *
 * Usage:
 *   const ok = await confirmAsync({ title, body, danger: true });
 *   if (!ok) return;
 */
export default class ConfirmModal extends Modal<ConfirmModalAttrs> {
  protected resolved = false;

  className() {
    return "BackupConfirmModal Modal--small";
  }

  title() {
    return this.attrs.title;
  }

  content() {
    const confirmLabel =
      this.attrs.confirmLabel ??
      app.translator.trans("ramon-backup.admin.errors.confirm_default");
    const cancelLabel =
      this.attrs.cancelLabel ??
      app.translator.trans("ramon-backup.admin.errors.cancel_default");

    return (
      <div className="Modal-body">
        <div className="BackupConfirmModal-body">{this.attrs.body}</div>
        <div className="Form-group BackupConfirmModal-actions">
          <Button
            className={
              "Button " +
              (this.attrs.danger ? "Button--danger" : "Button--primary")
            }
            onclick={() => this.decide(true)}
          >
            {confirmLabel}
          </Button>
          <Button className="Button" onclick={() => this.decide(false)}>
            {cancelLabel}
          </Button>
        </div>
      </div>
    );
  }

  decide(confirmed: boolean) {
    if (this.resolved) return;
    this.resolved = true;
    (confirmed ? this.attrs.onConfirm : this.attrs.onCancel)?.();
    this.hide();
  }

  // Esc key / backdrop click / X button all funnel through Mithril's
  // remove lifecycle. If the user dismissed without picking a button,
  // treat that as a cancel so the awaiting Promise actually resolves.
  onbeforeremove(vnode: Mithril.VnodeDOM<ConfirmModalAttrs, this>) {
    if (!this.resolved) {
      this.resolved = true;
      this.attrs.onCancel?.();
    }
    return super.onbeforeremove(vnode);
  }
}

/** Promise wrapper so callers can `await confirmAsync(...)`. */
export function confirmAsync(
  attrs: Omit<
    ConfirmModalAttrs,
    "onConfirm" | "onCancel" | keyof IInternalModalAttrs
  >,
): Promise<boolean> {
  return new Promise<boolean>((resolve) => {
    let settled = false;
    const settle = (v: boolean) => {
      if (settled) return;
      settled = true;
      resolve(v);
    };
    app.modal.show(ConfirmModal, {
      ...attrs,
      onConfirm: () => settle(true),
      onCancel: () => settle(false),
    });
  });
}
