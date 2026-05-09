import app from 'flarum/admin/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

import { apiRequest, apiUrl, errorDetail } from '../utils/api';

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-backup.admin.encryption.${key}`, params ?? {});

interface EncryptionStatus {
  available: boolean;
  has_public_key: boolean;
  private_key_present: boolean;
  keys_match: boolean | null;
  healthy: boolean;
  requires_regeneration: boolean;
  public_key: string | null;
  config_key: string;
}

interface RevealAttrs extends IInternalModalAttrs {
  privateKey: string;
  configKey: string;
}

class KeypairRevealModal extends Modal<RevealAttrs> {
  protected copied = false;

  className() {
    return 'BackupRevealModal Modal--medium';
  }

  title() {
    return trans('reveal_modal.title');
  }

  content() {
    const { privateKey, configKey } = this.attrs;
    const snippet = `'${configKey}' => '${privateKey}',`;

    return (
      <div className="Modal-body">
        <p>{trans('reveal_modal.intro')}</p>

        <div className="Alert Alert--error">
          <strong>{trans('reveal_modal.warning_title')}</strong>
          <p>{trans('reveal_modal.warning_body')}</p>
        </div>

        <label className="BackupReveal-label">{trans('reveal_modal.snippet_label')}</label>
        <pre className="BackupReveal-snippet">
          <code>{snippet}</code>
        </pre>

        <div className="Form-group BackupReveal-actions">
          <Button className="Button" icon="fas fa-copy" onclick={() => this.copy(snippet)}>
            {this.copied ? trans('reveal_modal.copied') : trans('reveal_modal.copy_button')}
          </Button>
          <Button className="Button Button--primary" onclick={() => this.hide()}>
            {trans('reveal_modal.close')}
          </Button>
        </div>
      </div>
    );
  }

  copy(snippet: string) {
    if (!navigator.clipboard) {
      app.alerts.show({ type: 'error' }, trans('clipboard_unavailable'));
      return;
    }
    navigator.clipboard
      .writeText(snippet)
      .then(() => {
        this.copied = true;
        m.redraw();
        setTimeout(() => {
          this.copied = false;
          m.redraw();
        }, 2000);
      })
      .catch((err) => {
        console.error('[backup] clipboard writeText failed', err);
        app.alerts.show({ type: 'error' }, trans('clipboard_failed'));
      });
  }
}

interface RegenerateAttrs extends IInternalModalAttrs {
  onConfirm: () => Promise<unknown>;
}

class RegenerateConfirmModal extends Modal<RegenerateAttrs> {
  protected acknowledged = false;
  protected submitting = false;

  className() {
    return 'BackupRegenerateModal Modal--medium';
  }

  title() {
    return trans('regenerate_modal.title');
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Alert Alert--error">
          <p>{trans('regenerate_modal.warning')}</p>
        </div>

        <label className="BackupRegenerate-confirm">
          <input
            type="checkbox"
            checked={this.acknowledged}
            onchange={(e: Event) => {
              this.acknowledged = (e.target as HTMLInputElement).checked;
            }}
          />{' '}
          {trans('regenerate_modal.acknowledge')}
        </label>

        <div className="Form-group">
          <Button
            className="Button Button--primary"
            loading={this.submitting}
            disabled={!this.acknowledged || this.submitting}
            onclick={() => this.submit()}
          >
            {trans('regenerate_modal.submit')}
          </Button>
        </div>
      </div>
    );
  }

  async submit() {
    this.submitting = true;
    m.redraw();
    try {
      await this.attrs.onConfirm();
      this.hide();
    } catch {
      // Parent already showed an error toast — keep the modal open so
      // the user can retry without re-acknowledging the warning.
      this.submitting = false;
      m.redraw();
    }
  }
}

export default class EncryptionCard extends Component<ComponentAttrs> {
  protected status: EncryptionStatus | null = null;
  protected loadState: 'loading' | 'ok' | 'error' = 'loading';
  protected loadError: string | null = null;
  protected publicCopied = false;

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    this.refresh();
  }

  view() {
    return (
      <section className="BackupEncryptionCard">
        <header>
          <h3>{trans('section_title')}</h3>
          <p className="helpText">{trans('section_help')}</p>
        </header>

        {this.loadState === 'loading' && <LoadingIndicator />}
        {this.loadState === 'error' && (
          <div className="Alert Alert--error BackupEncryption-loadError">
            <p>{trans('status.load_failed')}</p>
            {this.loadError && (
              <p className="helpText">
                <code>{this.loadError}</code>
              </p>
            )}
            <Button className="Button" icon="fas fa-rotate" onclick={() => this.refresh()}>
              {trans('status.retry')}
            </Button>
          </div>
        )}
        {this.loadState === 'ok' && this.body()}
      </section>
    );
  }

  body() {
    if (!this.status) return <p className="helpText">{trans('status.unknown')}</p>;

    const s = this.status;
    if (!s.available) {
      return <div className="Alert Alert--error">{trans('status.libsodium_missing')}</div>;
    }

    return (
      <>
        <div className="BackupEncryption-statusRow">
          {this.statusBadge('public', s.has_public_key)}
          {this.statusBadge('private', s.private_key_present)}
        </div>

        {s.healthy && <div className="Alert Alert--success">{trans('status.healthy')}</div>}

        {!s.has_public_key && !s.private_key_present && (
          <div>
            <p className="helpText">{trans('status.not_setup')}</p>
            <Button className="Button Button--primary" icon="fas fa-key" onclick={() => this.generate(false)}>
              {trans('actions.generate')}
            </Button>
          </div>
        )}

        {s.has_public_key && s.private_key_present && s.keys_match === false && (
          <div className="Alert Alert--error">
            <strong>{trans('status.mismatch_title')}</strong>
            <p>{trans('status.mismatch_body')}</p>
            <p>
              <code>'{s.config_key}'</code>
            </p>
          </div>
        )}

        {s.has_public_key && !s.private_key_present && (
          <div className="Alert Alert--error">
            <strong>{trans('status.private_missing_title')}</strong>
            <p>{trans('status.private_missing_body')}</p>
            <p>
              <code>'{s.config_key}'</code>
            </p>
          </div>
        )}

        {s.has_public_key && this.publicKeyPanel(s.public_key || '', s.healthy)}
      </>
    );
  }

  publicKeyPanel(publicKey: string, healthy: boolean) {
    return (
      <div className="BackupEncryption-publicKey">
        <label>{trans('public_key.label')}</label>
        <div className="BackupEncryption-publicKeyRow">
          <pre>
            <code>{publicKey}</code>
          </pre>
          <Button
            className="Button Button--icon"
            icon="fas fa-copy"
            title={extractText(trans('public_key.copy_title'))}
            onclick={() => this.copyPublic(publicKey)}
          >
            {this.publicCopied ? extractText(trans('public_key.copied')) : ''}
          </Button>
        </div>
        <p className="helpText">{healthy ? trans('public_key.help_healthy') : trans('public_key.help_broken')}</p>
        <Button className="Button Button--danger" icon="fas fa-rotate" onclick={() => this.openRegenerate()}>
          {trans('public_key.remove_button')}
        </Button>
      </div>
    );
  }

  statusBadge(kind: 'public' | 'private', present: boolean) {
    return (
      <div className={`BackupEncryption-badge BackupEncryption-badge--${present ? 'ok' : 'missing'}`}>
        <i className={`icon fas fa-${present ? 'check' : 'times'}`} />
        <span>{trans(`status.${kind}_key_label`)}</span>
        <span className="BackupEncryption-badgeState">{trans(`status.${present ? 'present' : 'absent'}`)}</span>
      </div>
    );
  }

  copyPublic(publicKey: string) {
    if (!publicKey) return;
    if (!navigator.clipboard) {
      app.alerts.show({ type: 'error' }, trans('clipboard_unavailable'));
      return;
    }
    navigator.clipboard
      .writeText(publicKey)
      .then(() => {
        this.publicCopied = true;
        m.redraw();
        setTimeout(() => {
          this.publicCopied = false;
          m.redraw();
        }, 2000);
      })
      .catch((err) => {
        console.error('[backup] clipboard writeText failed', err);
        app.alerts.show({ type: 'error' }, trans('clipboard_failed'));
      });
  }

  refresh(): Promise<void> {
    this.loadState = 'loading';
    this.loadError = null;
    return apiRequest<EncryptionStatus>({
      method: 'GET',
      url: `${apiUrl()}/backup/encryption/status`,
      surface: false,
    })
      .then((res) => {
        this.status = res;
        this.loadState = 'ok';
      })
      .catch((e) => {
        this.status = null;
        this.loadState = 'error';
        this.loadError = errorDetail(e);
      })
      .then(() => {
        m.redraw();
      });
  }

  async generate(acknowledgeLoss: boolean) {
    try {
      const res = await apiRequest<{ public_key: string; private_key: string; config_key: string }>({
        method: 'POST',
        url: `${apiUrl()}/backup/encryption/generate-keypair`,
        body: { acknowledge_loss: acknowledgeLoss },
        surface: false,
      });
      await this.refresh();
      app.modal.show(KeypairRevealModal, {
        privateKey: res.private_key,
        configKey: res.config_key,
      });
    } catch (e) {
      app.alerts.show({ type: 'error' }, errorDetail(e, String(trans('actions.generate_failed'))));
      throw e;
    }
  }

  openRegenerate() {
    app.modal.show(RegenerateConfirmModal, {
      onConfirm: () => this.generate(true),
    });
  }
}
