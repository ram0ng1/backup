import app from 'flarum/admin/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

import { apiUrl, fmtBytes } from '../utils/api';

export interface ImportModalAttrs extends IInternalModalAttrs {
  onComplete: () => void;
}

interface InspectResult {
  job_id: string;
  is_encrypted: boolean;
  meta: {
    format_version?: number;
    created_at?: string;
    flarum_version?: string;
    php_version?: string;
    contents?: string[];
    source_url?: string;
  };
  size: number;
}

interface ImportProgress {
  phase: 'inspect' | 'extract' | 'restore' | 'rewrite' | 'finalize' | 'done' | 'error';
  message: string;
  progress: {
    total_bytes: number;
    processed_bytes: number;
    extracted_entries: number;
    restored_statements: number;
    percent: number;
  };
}

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-backup.admin.import_modal.${key}`, params ?? {});

/**
 * Three-stage modal:
 *   1. upload   — operator picks a `.flarum` file. We POST to /imports
 *                  and the server validates the header without
 *                  decrypting.
 *   2. configure — depending on the inspect result we ask for the
 *                  private key (only when the archive is encrypted)
 *                  AND a confirm-replace checkbox in all cases. The
 *                  user agreed in setup that we'd ask at this point.
 *   3. progress — chunked-tick polling drives a progress bar.
 */
export default class ImportModal extends Modal<ImportModalAttrs> {
  protected stage: 'upload' | 'configure' | 'progress' = 'upload';

  protected file: File | null = null;
  protected uploading = false;
  protected uploadError: string | null = null;

  protected inspect: InspectResult | null = null;
  protected privateKey = '';
  protected confirmReplace = false;
  protected starting = false;

  protected status: ImportProgress | null = null;
  protected polling = false;

  className() {
    return 'BackupImportModal Modal--medium';
  }

  title() {
    return trans('title');
  }

  content() {
    if (this.stage === 'upload') return this.uploadContent();
    if (this.stage === 'configure') return this.configureContent();
    return this.progressContent();
  }

  // ────────────────────────────────────────── upload stage

  uploadContent() {
    return (
      <div className="Modal-body">
        <div className="Alert Alert--warning">
          <strong>{trans('warning_title')}</strong>
          <p>{trans('warning_body')}</p>
        </div>

        <label className="BackupImport-fileLabel">
          <input
            type="file"
            accept=".flarum"
            onchange={(e: Event) => {
              const f = (e.target as HTMLInputElement).files?.[0] || null;
              this.file = f;
            }}
          />
          {this.file ? (
            <span>
              {this.file.name} <span className="helpText">({fmtBytes(this.file.size)})</span>
            </span>
          ) : (
            <span className="helpText">{trans('choose_file')}</span>
          )}
        </label>

        {this.uploadError && <div className="Alert Alert--error">{this.uploadError}</div>}

        <div className="Form-group">
          <Button
            className="Button Button--primary"
            loading={this.uploading}
            disabled={this.uploading || !this.file}
            onclick={() => this.upload()}
          >
            {trans('upload_button')}
          </Button>
        </div>
      </div>
    );
  }

  async upload() {
    if (!this.file) return;
    this.uploading = true;
    this.uploadError = null;

    const fd = new FormData();
    fd.append('archive', this.file);

    try {
      // app.request supports passing the body directly as FormData.
      const res = await app.request<InspectResult>({
        method: 'POST',
        url: `${apiUrl()}/backup/imports`,
        serialize: (raw: unknown) => raw,
        body: fd,
      });
      this.inspect = res;
      this.stage = 'configure';
    } catch (e: any) {
      this.uploadError = e?.response?.errors?.[0]?.detail || (trans('upload_failed') as string);
    } finally {
      this.uploading = false;
      m.redraw();
    }
  }

  // ────────────────────────────────────────── configure stage

  configureContent() {
    const i = this.inspect!;
    return (
      <div className="Modal-body">
        <h4>{trans('inspect_title')}</h4>
        <dl className="BackupImport-meta">
          {i.meta.created_at && (
            <>
              <dt>{trans('meta_when')}</dt>
              <dd>{i.meta.created_at}</dd>
            </>
          )}
          {i.meta.flarum_version && (
            <>
              <dt>{trans('meta_flarum')}</dt>
              <dd>{i.meta.flarum_version}</dd>
            </>
          )}
          {i.meta.contents && (
            <>
              <dt>{trans('meta_contents')}</dt>
              <dd>{i.meta.contents.join(', ')}</dd>
            </>
          )}
          {i.meta.source_url && (
            <>
              <dt>{trans('meta_source_url')}</dt>
              <dd>
                <code>{i.meta.source_url}</code>
              </dd>
            </>
          )}
          <dt>{trans('meta_size')}</dt>
          <dd>{fmtBytes(i.size)}</dd>
        </dl>

        <div className="Alert Alert--info BackupImport-urlNote">
          <i className="icon fas fa-info-circle" /> {trans('url_rewrite_note')}
        </div>

        {i.is_encrypted && (
          <fieldset className="BackupImport-fieldset">
            <legend>{trans('key_title')}</legend>
            <p className="helpText">{trans('key_help')}</p>
            <textarea
              className="FormControl BackupImport-keyInput"
              rows={3}
              placeholder="base64 private key"
              value={this.privateKey}
              oninput={(e: Event) => {
                this.privateKey = (e.target as HTMLTextAreaElement).value;
              }}
            />
            <p className="helpText BackupImport-keyHint">{trans('key_hint_local')}</p>
          </fieldset>
        )}

        <div className="Alert Alert--error BackupImport-confirmAlert">
          <strong>{trans('confirm_title')}</strong>
          <p>{trans('confirm_body')}</p>
          <label className="BackupImport-confirm">
            <input
              type="checkbox"
              checked={this.confirmReplace}
              onchange={(e: Event) => {
                this.confirmReplace = (e.target as HTMLInputElement).checked;
              }}
            />{' '}
            <span>{trans('confirm_check')}</span>
          </label>
        </div>

        <div className="Form-group">
          <Button
            className="Button Button--primary"
            loading={this.starting}
            disabled={this.starting || !this.confirmReplace}
            onclick={() => this.startRestore()}
          >
            {trans('start_button')}
          </Button>
        </div>
      </div>
    );
  }

  async startRestore() {
    if (!this.inspect) return;
    this.starting = true;

    try {
      const res = await app.request<{ phase: string; message: string }>({
        method: 'POST',
        url: `${apiUrl()}/backup/imports/${this.inspect.job_id}/start`,
        body: {
          private_key: this.privateKey.trim() || null,
          confirm_replace: this.confirmReplace,
        },
      });
      this.stage = 'progress';
      this.status = {
        phase: res.phase as ImportProgress['phase'],
        message: res.message,
        progress: {
          total_bytes: this.inspect.size,
          processed_bytes: 0,
          extracted_entries: 0,
          restored_statements: 0,
          percent: 0,
        },
      };
      m.redraw();
      this.pump();
    } catch (e: any) {
      const msg = e?.response?.errors?.[0]?.detail || (trans('start_failed') as string);
      app.alerts.show({ type: 'error' }, msg);
    } finally {
      this.starting = false;
    }
  }

  // ────────────────────────────────────────── progress stage

  progressContent() {
    const s = this.status;
    if (!s) return <LoadingIndicator />;

    const isDone = s.phase === 'done';
    const isError = s.phase === 'error';
    const pct = Math.max(0, Math.min(100, s.progress?.percent || 0));

    return (
      <div className="Modal-body BackupImport-progress">
        <div className={`BackupImport-status BackupImport-status--${s.phase}`}>
          <strong>{trans('phase_' + s.phase)}</strong>
          <p>{s.message}</p>
        </div>

        {!isError && (
          <div className="BackupImport-bar">
            <div className="BackupImport-bar-fill" style={{ width: `${isDone ? 100 : pct}%` }} />
          </div>
        )}

        <div className="Form-group BackupImport-progress-actions">
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

  async pump() {
    if (this.polling || !this.inspect) return;
    this.polling = true;

    try {
      while (this.inspect && this.status && this.status.phase !== 'done' && this.status.phase !== 'error') {
        const res = await app.request<ImportProgress>({
          method: 'POST',
          url: `${apiUrl()}/backup/imports/${this.inspect.job_id}/tick`,
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
    if (!this.inspect) return;
    try {
      await app.request({
        method: 'DELETE',
        url: `${apiUrl()}/backup/imports/${this.inspect.job_id}`,
      });
    } catch {
      /* best effort */
    }
    this.close();
  }

  close() {
    this.hide();
  }
}
