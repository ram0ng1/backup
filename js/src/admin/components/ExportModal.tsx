import app from 'flarum/admin/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

import { apiUrl, fmtBytes } from '../utils/api';

export interface ExportModalAttrs extends IInternalModalAttrs {
  onComplete: () => void;
}

interface ExportProgress {
  phase: 'scan' | 'db_dump' | 'bundle' | 'finalize' | 'done' | 'error';
  message: string;
  progress: {
    total_bytes: number;
    processed_bytes: number;
    total_files: number;
    processed_files: number;
    percent: number;
  };
  result?: {
    backup_id: number;
    filename: string;
    size: number;
  };
}

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-backup.admin.export_modal.${key}`, params ?? {});

/**
 * Two-stage modal:
 *
 *   1. Form     — admin picks what to include and whether to encrypt.
 *   2. Progress — chunked-tick polling drives a progress bar until the
 *                 server reports phase=done (or phase=error).
 *
 * The "encryption to a foreign key" path lets the operator paste a
 * public key from another Flarum install — useful when preparing an
 * archive for transfer to a different server whose keypair is not the
 * one in *this* config.php.
 */
export default class ExportModal extends Modal<ExportModalAttrs> {
  protected stage: 'form' | 'progress' = 'form';

  protected includeDb = true;
  protected includeAssets = true;
  protected includeStorage = false;
  protected includeExtensions = false;

  protected encryptionEnabled = false;
  protected encryptionUseExternal = false;
  protected externalPublicKey = '';

  protected starting = false;
  protected jobId: string | null = null;
  protected status: ExportProgress | null = null;
  protected polling = false;

  className() {
    return 'BackupExportModal Modal--medium';
  }

  title() {
    return trans('title');
  }

  content() {
    if (this.stage === 'form') return this.formContent();
    return this.progressContent();
  }

  // ────────────────────────────────────────── form

  formContent() {
    return (
      <div className="Modal-body">
        <p className="helpText">{trans('intro')}</p>

        <fieldset className="BackupExport-fieldset">
          <legend>{trans('contents_title')}</legend>

          {this.checkbox('db', () => this.includeDb, (v) => (this.includeDb = v))}
          {this.checkbox('assets', () => this.includeAssets, (v) => (this.includeAssets = v))}
          {this.checkbox('storage', () => this.includeStorage, (v) => (this.includeStorage = v))}
          {this.checkbox('extensions', () => this.includeExtensions, (v) => (this.includeExtensions = v))}
        </fieldset>

        <fieldset className="BackupExport-fieldset">
          <legend>{trans('encryption_title')}</legend>

          <label className="BackupExport-checkbox">
            <input
              type="checkbox"
              checked={this.encryptionEnabled}
              onchange={(e: Event) => {
                this.encryptionEnabled = (e.target as HTMLInputElement).checked;
              }}
            />{' '}
            <span>{trans('encryption_enable')}</span>
          </label>
          <p className="helpText">{trans('encryption_help')}</p>

          {this.encryptionEnabled && (
            <>
              <label className="BackupExport-checkbox">
                <input
                  type="checkbox"
                  checked={this.encryptionUseExternal}
                  onchange={(e: Event) => {
                    this.encryptionUseExternal = (e.target as HTMLInputElement).checked;
                  }}
                />{' '}
                <span>{trans('encryption_external')}</span>
              </label>
              {this.encryptionUseExternal && (
                <>
                  <p className="helpText">{trans('encryption_external_help')}</p>
                  <textarea
                    className="FormControl BackupExport-keyInput"
                    rows={3}
                    placeholder="base64 public key"
                    value={this.externalPublicKey}
                    oninput={(e: Event) => {
                      this.externalPublicKey = (e.target as HTMLTextAreaElement).value;
                    }}
                  />
                </>
              )}
            </>
          )}
        </fieldset>

        <div className="Form-group">
          <Button
            className="Button Button--primary"
            loading={this.starting}
            disabled={this.starting || !this.canStart()}
            onclick={() => this.start()}
          >
            {trans('start_button')}
          </Button>
        </div>
      </div>
    );
  }

  checkbox(key: 'db' | 'assets' | 'storage' | 'extensions', get: () => boolean, set: (v: boolean) => void) {
    return (
      <label className="BackupExport-checkbox">
        <input
          type="checkbox"
          checked={get()}
          onchange={(e: Event) => {
            set((e.target as HTMLInputElement).checked);
          }}
        />{' '}
        <span className="BackupExport-checkbox-label">{trans('content_' + key)}</span>
        <span className="BackupExport-checkbox-help helpText">{trans('content_' + key + '_help')}</span>
      </label>
    );
  }

  canStart(): boolean {
    if (!this.includeDb && !this.includeAssets && !this.includeStorage && !this.includeExtensions) {
      return false;
    }
    if (this.encryptionEnabled && this.encryptionUseExternal && !this.externalPublicKey.trim()) {
      return false;
    }
    return true;
  }

  // ────────────────────────────────────────── progress

  progressContent() {
    const s = this.status;
    if (!s) return <LoadingIndicator />;

    const isDone = s.phase === 'done';
    const isError = s.phase === 'error';
    const pct = Math.max(0, Math.min(100, s.progress?.percent || 0));

    return (
      <div className="Modal-body BackupExport-progress">
        <div className={`BackupExport-status BackupExport-status--${s.phase}`}>
          <strong>{trans('phase_' + s.phase)}</strong>
          <p>{s.message}</p>
        </div>

        {!isError && (
          <>
            <div className="BackupExport-bar">
              <div
                className="BackupExport-bar-fill"
                style={{ width: `${isDone ? 100 : pct}%` }}
                role="progressbar"
                aria-valuenow={pct}
                aria-valuemin={0}
                aria-valuemax={100}
              />
            </div>
            <div className="BackupExport-stats">
              <span>{fmtBytes(s.progress.processed_bytes)} / {fmtBytes(s.progress.total_bytes || s.progress.processed_bytes)}</span>
              {s.progress.total_files > 0 && (
                <span>{trans('files_count', { done: s.progress.processed_files, total: s.progress.total_files })}</span>
              )}
            </div>
          </>
        )}

        <div className="Form-group BackupExport-progress-actions">
          {!isDone && !isError && (
            <Button className="Button" onclick={() => this.cancel()}>
              {trans('cancel_button')}
            </Button>
          )}
          {(isDone || isError) && (
            <Button className="Button Button--primary" onclick={() => this.close()}>
              {trans('close_button')}
            </Button>
          )}
        </div>
      </div>
    );
  }

  // ────────────────────────────────────────── api calls

  async start() {
    this.starting = true;
    try {
      const res = await app.request<{ job_id: string; phase: string; message: string }>({
        method: 'POST',
        url: `${apiUrl()}/backup/exports`,
        body: {
          contents: {
            db: this.includeDb,
            assets: this.includeAssets,
            storage: this.includeStorage,
            extensions: this.includeExtensions,
          },
          encryption: {
            enabled: this.encryptionEnabled,
            public_key: this.encryptionUseExternal ? this.externalPublicKey.trim() : null,
          },
        },
      });

      this.jobId = res.job_id;
      this.stage = 'progress';
      this.status = {
        phase: res.phase as ExportProgress['phase'],
        message: res.message,
        progress: { total_bytes: 0, processed_bytes: 0, total_files: 0, processed_files: 0, percent: 0 },
      };
      this.starting = false;
      m.redraw();
      this.pump();
    } catch (e) {
      this.starting = false;
      app.alerts.show({ type: 'error' }, trans('start_failed'));
      m.redraw();
    }
  }

  async pump() {
    if (this.polling || !this.jobId) return;
    this.polling = true;
    try {
      // Sequential ticks — each /tick call performs ~4MB of work.
      while (this.jobId && this.status && this.status.phase !== 'done' && this.status.phase !== 'error') {
        const res = await app.request<ExportProgress>({
          method: 'POST',
          url: `${apiUrl()}/backup/exports/${this.jobId}/tick`,
        });
        this.status = res;
        m.redraw();
      }
      if (this.status?.phase === 'done') {
        app.alerts.show({ type: 'success' }, trans('completed'));
        this.attrs.onComplete();
      }
    } finally {
      this.polling = false;
    }
  }

  async cancel() {
    if (!this.jobId) return;
    try {
      await app.request({
        method: 'DELETE',
        url: `${apiUrl()}/backup/exports/${this.jobId}`,
      });
    } catch {
      /* best effort */
    }
    this.close();
  }

  close() {
    this.jobId = null;
    this.hide();
  }
}
