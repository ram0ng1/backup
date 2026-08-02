import app from "flarum/admin/app";
import Modal, { IInternalModalAttrs } from "flarum/common/components/Modal";
import Button from "flarum/common/components/Button";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";

import { apiRequest, apiUrl, errorDetail, fmtBytes } from "../utils/api";

/** Abort the upload XHR if no progress event fires for this long. */
const UPLOAD_IDLE_TIMEOUT_MS = 60_000;

/**
 * Fallback chunk size if /backup/imports init doesn't return one.
 * Server-recommended is 4 MB (see UploadImportController::RECOMMENDED_CHUNK_BYTES).
 */
const FALLBACK_CHUNK_BYTES = 4 * 1024 * 1024;

/**
 * How many times to retry a single failed chunk before giving up on
 * the whole upload. Each retry uses the same offset so it overwrites
 * (idempotent). Two retries cover a transient hiccup without
 * spinning forever on a real outage.
 */
const CHUNK_RETRY_LIMIT = 2;

export interface ImportModalAttrs extends IInternalModalAttrs {
  onComplete: () => void;
}

interface ArchiveExtensionEntry {
  id: string;
  name?: string;
  title?: string;
  version?: string;
  location?: "workbench" | "vendor" | "unknown";
  relative?: string;
  files?: number;
}

interface ArchiveManifest {
  asset_count?: number;
  storage_count?: number;
  extension_count?: number;
  // Older archives shipped extensions as a flat string[] of dirnames.
  // New archives ship rich descriptors.
  extensions?: string[] | ArchiveExtensionEntry[];
  has_composer?: boolean;
  // Archives from before the project-reconcile work don't carry the
  // site's root extend.php, so the toggle for it stays hidden.
  has_root_extend?: boolean;
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
    manifest?: ArchiveManifest;
  };
  size: number;
}

interface ImportProgress {
  phase:
    | "inspect"
    | "extract"
    | "reconcile"
    | "restore"
    | "rewrite"
    | "verify"
    | "finalize"
    | "done"
    | "error";
  message: string;
  progress: {
    total_bytes: number;
    processed_bytes: number;
    extracted_entries: number;
    skipped_entries?: number;
    unresolved_entries?: number;
    unwritable_entries?: number;
    restored_statements: number;
    percent: number;
  };
  // A restore that lost entries still ends in `done`. These two are
  // what stop the completed screen from painting clean success over
  // an incomplete restore.
  warnings?: string[];
  incomplete?: boolean;
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
  protected stage: "upload" | "configure" | "progress" = "upload";

  protected file: File | null = null;
  protected uploading = false;
  protected uploadProgress = 0;
  protected uploadIndeterminate = false;
  protected uploadError: string | null = null;

  protected inspect: InspectResult | null = null;
  protected privateKey = "";
  protected confirmReplace = false;
  protected starting = false;

  // Section toggles. Default to "everything that's actually inside the
  // archive" — the user explicitly opts OUT of pieces they don't want
  // to overwrite. Initialised when the inspect result arrives.
  protected sectionDb = false;
  protected sectionAssets = false;
  protected sectionStorage = false;
  protected sectionExtensions = false;
  // Per-extension toggles, keyed by directory name from the manifest.
  protected extensionsByName: Record<string, boolean> = {};

  // Opt-in: overwriting the site's root extend.php replaces live config
  // of THIS server, so it never happens unless asked for.
  protected sectionRootExtend = false;
  // Opt-out: losing this server's SMTP / queue / integration settings to
  // the source forum's is the defect this whole layer exists to stop.
  protected preserveSettings = true;

  protected status: ImportProgress | null = null;
  protected polling = false;

  className() {
    return "BackupImportModal Modal--medium";
  }

  title() {
    if (this.stage === "progress" && this.status?.phase === "done") {
      return this.sectionDb ? trans("logout_title") : trans("done_title");
    }
    return trans("title");
  }

  content() {
    if (this.stage === "upload") return this.uploadContent();
    if (this.stage === "configure") return this.configureContent();
    return this.progressContent();
  }

  // ────────────────────────────────────────── upload stage

  uploadContent() {
    return (
      <div className="Modal-body">
        <div className="Alert Alert--warning">
          <strong>{trans("warning_title")}</strong>
          <p>{trans("warning_body")}</p>
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
              {this.file.name}{" "}
              <span className="helpText">({fmtBytes(this.file.size)})</span>
            </span>
          ) : (
            <span className="helpText">{trans("choose_file")}</span>
          )}
        </label>

        {this.uploadError && (
          <div className="Alert Alert--error">{this.uploadError}</div>
        )}

        {this.uploading && (
          <div className="BackupImport-uploadProgress">
            <div className="BackupImport-bar">
              <div
                className={
                  "BackupImport-bar-fill" +
                  (this.uploadIndeterminate
                    ? " BackupImport-bar-fill--indeterminate"
                    : "")
                }
                style={
                  this.uploadIndeterminate
                    ? undefined
                    : { width: `${Math.max(2, this.uploadProgress)}%` }
                }
              />
            </div>
            <div className="BackupImport-uploadStatus helpText">
              {this.uploadIndeterminate
                ? trans("inspecting_archive")
                : trans("uploading_pct", { pct: this.uploadProgress })}
            </div>
          </div>
        )}

        <div className="Form-group">
          <Button
            className="Button Button--primary"
            loading={this.uploading}
            disabled={this.uploading || !this.file}
            onclick={() => this.upload()}
          >
            {trans("upload_button")}
          </Button>
        </div>
      </div>
    );
  }

  async upload() {
    if (!this.file) return;
    this.uploading = true;
    this.uploadProgress = 0;
    this.uploadIndeterminate = false;
    this.uploadError = null;

    try {
      // Use a raw XHR so we can show upload progress — Flarum's
      // app.request (mithril's m.request) does not expose
      // `xhr.upload.onprogress`. Once 100% has been sent the server
      // still has to read the header + validate the archive, so we
      // flip to an indeterminate "Inspecting…" state until the
      // response comes back.
      const res = await this.uploadWithProgress(this.file, (pct) => {
        this.uploadProgress = pct;
        if (pct >= 100) this.uploadIndeterminate = true;
        m.redraw();
      });
      this.inspect = res;

      // Seed section toggles from what the archive actually contains.
      // Anything missing from the archive can't be ticked anyway, so
      // there's no value in defaulting it to true.
      const contents = res.meta.contents || [];
      this.sectionDb = contents.includes("db");
      this.sectionAssets = contents.includes("assets");
      this.sectionStorage = contents.includes("storage");
      this.sectionExtensions = contents.includes("extensions");

      // Normalise to {id} regardless of which manifest version the
      // archive was packed with (string[] vs ArchiveExtensionEntry[]).
      const exts = res.meta.manifest?.extensions || [];
      this.extensionsByName = {};
      for (const e of exts) {
        const id = typeof e === "string" ? e : e.id;
        if (id) this.extensionsByName[id] = true;
      }

      this.stage = "configure";
    } catch (e: any) {
      console.error("[backup] archive upload failed", e);
      this.uploadError = errorDetail(e, String(trans("upload_failed")));
    } finally {
      this.uploading = false;
      m.redraw();
    }
  }

  /**
   * Chunked upload + inspect.
   *
   * Single multipart POSTs of multi-GB archives reliably hit server
   * caps (`upload_max_filesize`, `post_max_size`, nginx
   * `client_max_body_size`, `memory_limit` during multipart parsing)
   * and surface as 500s. Instead we do three small requests:
   *
   *   1. POST /backup/imports                — init, gets job_id + chunk_size
   *   2. POST /backup/imports/{id}/chunk*    — append each slice (loop)
   *   3. POST /backup/imports/{id}/inspect   — finalise, return meta
   *
   * Progress is computed from `bytesSent / file.size` across all chunk
   * requests so the bar advances smoothly through the entire file
   * even though each individual request only carries a few MB.
   */
  private async uploadWithProgress(
    file: File,
    onProgress: (pct: number) => void,
  ): Promise<InspectResult> {
    // ─── 1. init ──────────────────────────────────────────────────
    const init = await apiRequest<{ job_id: string; chunk_size: number }>({
      method: "POST",
      url: `${apiUrl()}/backup/imports`,
      body: { filename: file.name, size: file.size },
      surface: false,
    });

    const jobId = init.job_id;
    const chunkSize =
      init.chunk_size > 0 ? init.chunk_size : FALLBACK_CHUNK_BYTES;

    // ─── 2. chunk loop ────────────────────────────────────────────
    let offset = 0;
    while (offset < file.size) {
      const end = Math.min(offset + chunkSize, file.size);
      const slice = file.slice(offset, end);

      let attempt = 0;
      // eslint-disable-next-line no-constant-condition
      while (true) {
        try {
          await this.sendChunk(jobId, offset, slice);
          break;
        } catch (e) {
          attempt++;
          if (attempt > CHUNK_RETRY_LIMIT) throw e;
          // Back off briefly before retrying — gives a transient
          // hiccup a moment to clear without spamming the server.
          await new Promise((r) => setTimeout(r, 750 * attempt));
        }
      }

      offset = end;
      const pct = Math.min(99, Math.round((offset / file.size) * 100));
      onProgress(pct);
    }

    // ─── 3. inspect ───────────────────────────────────────────────
    onProgress(100);
    return apiRequest<InspectResult>({
      method: "POST",
      url: `${apiUrl()}/backup/imports/${jobId}/inspect`,
      surface: false,
    });
  }

  /**
   * Single-chunk upload as a raw octet-stream POST. We use XHR
   * (rather than fetch) so the per-chunk idle timeout works the
   * same way it did for the old monolithic upload, and so we can
   * pull a detailed error message off any non-2xx response body.
   */
  private sendChunk(jobId: string, offset: number, slice: Blob): Promise<void> {
    return new Promise<void>((resolve, reject) => {
      const xhr = new XMLHttpRequest();

      let lastProgress = Date.now();
      const idleTimer = setInterval(() => {
        if (Date.now() - lastProgress > UPLOAD_IDLE_TIMEOUT_MS) {
          clearInterval(idleTimer);
          xhr.abort();
        }
      }, 5_000);
      const stopIdleTimer = () => clearInterval(idleTimer);

      xhr.upload.addEventListener("progress", () => {
        lastProgress = Date.now();
      });

      xhr.addEventListener("load", () => {
        stopIdleTimer();
        if (xhr.status >= 200 && xhr.status < 300) {
          resolve();
        } else {
          let detail: string | undefined;
          try {
            detail = JSON.parse(xhr.responseText)?.errors?.[0]?.detail;
          } catch {
            /* non-JSON body */
          }
          reject({ detail: detail || `${xhr.status} ${xhr.statusText}` });
        }
      });
      xhr.addEventListener("error", () => {
        stopIdleTimer();
        reject({ detail: String(trans("upload_failed")) });
      });
      xhr.addEventListener("abort", () => {
        stopIdleTimer();
        reject({ detail: String(trans("upload_idle_timeout")) });
      });

      xhr.open("POST", `${apiUrl()}/backup/imports/${jobId}/chunk`, true);
      xhr.withCredentials = true;
      xhr.setRequestHeader("Content-Type", "application/octet-stream");
      xhr.setRequestHeader("X-Chunk-Offset", String(offset));
      const csrf = (app as any).session?.csrfToken;
      if (csrf) xhr.setRequestHeader("X-CSRF-Token", csrf);
      xhr.send(slice);
    });
  }

  // ────────────────────────────────────────── configure stage

  configureContent() {
    const i = this.inspect!;
    return (
      <div className="Modal-body">
        <h4>{trans("inspect_title")}</h4>
        <dl className="BackupImport-meta">
          {i.meta.created_at && (
            <>
              <dt>{trans("meta_when")}</dt>
              <dd>{i.meta.created_at}</dd>
            </>
          )}
          {i.meta.flarum_version && (
            <>
              <dt>{trans("meta_flarum")}</dt>
              <dd>{i.meta.flarum_version}</dd>
            </>
          )}
          {i.meta.contents && (
            <>
              <dt>{trans("meta_contents")}</dt>
              <dd>{i.meta.contents.join(", ")}</dd>
            </>
          )}
          {i.meta.source_url && (
            <>
              <dt>{trans("meta_source_url")}</dt>
              <dd>
                <code>{i.meta.source_url}</code>
              </dd>
            </>
          )}
          <dt>{trans("meta_size")}</dt>
          <dd>{fmtBytes(i.size)}</dd>
        </dl>

        <div className="Alert Alert--info BackupImport-urlNote">
          <i className="icon fas fa-info-circle" /> {trans("url_rewrite_note")}
        </div>

        {this.selectionFieldset(i)}

        {i.is_encrypted && (
          <fieldset className="BackupImport-fieldset">
            <legend>{trans("key_title")}</legend>
            <p className="helpText">{trans("key_help")}</p>
            <textarea
              className="FormControl BackupImport-keyInput"
              rows={3}
              placeholder="base64 private key"
              value={this.privateKey}
              oninput={(e: Event) => {
                this.privateKey = (e.target as HTMLTextAreaElement).value;
              }}
            />
            <p className="helpText BackupImport-keyHint">
              {trans("key_hint_local")}
            </p>
          </fieldset>
        )}

        <div className="Alert Alert--error BackupImport-confirmAlert">
          <strong>{trans("confirm_title")}</strong>
          <p>{trans("confirm_body")}</p>
          <label className="BackupImport-confirm">
            <input
              type="checkbox"
              checked={this.confirmReplace}
              onchange={(e: Event) => {
                this.confirmReplace = (e.target as HTMLInputElement).checked;
              }}
            />{" "}
            <span>{trans("confirm_check")}</span>
          </label>
        </div>

        <div className="Form-group">
          <Button
            className="Button Button--primary"
            loading={this.starting}
            disabled={this.starting || !this.confirmReplace}
            onclick={() => this.startRestore()}
          >
            {trans("start_button")}
          </Button>
        </div>
      </div>
    );
  }

  selectionFieldset(i: InspectResult) {
    const contents = i.meta.contents || [];
    const manifest = i.meta.manifest || {};
    const hasDb = contents.includes("db");
    const hasAssets = contents.includes("assets");
    const hasStorage = contents.includes("storage");
    const hasExtensions = contents.includes("extensions");
    const rawExtList = manifest.extensions || [];
    // Normalise to a uniform shape so the renderer can stay simple,
    // regardless of which manifest version the archive used.
    const extList: ArchiveExtensionEntry[] = (
      rawExtList as Array<string | ArchiveExtensionEntry>
    ).map((e) =>
      typeof e === "string" ? { id: e, location: "workbench" as const } : e,
    );

    return (
      <fieldset className="BackupImport-fieldset">
        <legend>{trans("selection_title")}</legend>
        <p className="helpText">{trans("selection_help")}</p>

        {hasDb &&
          this.sectionRow("db", this.sectionDb, (v) => (this.sectionDb = v))}
        {hasAssets &&
          this.sectionRow(
            "assets",
            this.sectionAssets,
            (v) => (this.sectionAssets = v),
            manifest.asset_count,
          )}
        {hasStorage &&
          this.sectionRow(
            "storage",
            this.sectionStorage,
            (v) => (this.sectionStorage = v),
            manifest.storage_count,
          )}
        {hasExtensions && (
          <>
            {this.sectionRow(
              "extensions",
              this.sectionExtensions,
              (v) => {
                this.sectionExtensions = v;
                // Cascade: turning the section off / on flips every
                // child to match. The user can then untick individuals.
                for (const ext of extList) this.extensionsByName[ext.id] = v;
              },
              manifest.extension_count,
            )}

            {this.sectionExtensions && manifest.has_composer && (
              <div className="BackupImport-composerNote helpText">
                <i className="icon fas fa-cube" />{" "}
                {trans("extensions_composer_note")}
              </div>
            )}

            {this.sectionExtensions && extList.length > 0 && (
              <div className="BackupImport-extList">
                {extList.map((ext) => (
                  <label className="BackupImport-extRow" key={ext.id}>
                    <input
                      type="checkbox"
                      checked={!!this.extensionsByName[ext.id]}
                      onchange={(e: Event) => {
                        this.extensionsByName[ext.id] = (
                          e.target as HTMLInputElement
                        ).checked;
                      }}
                    />{" "}
                    <span className="BackupImport-extTitle">
                      {ext.title || ext.id}
                    </span>{" "}
                    {ext.name && ext.name !== ext.id && (
                      <code className="BackupImport-extName">{ext.name}</code>
                    )}
                    {ext.location && (
                      <span
                        className={`BackupImport-extTag BackupImport-extTag--${ext.location}`}
                      >
                        {trans("extensions_tag_" + ext.location)}
                      </span>
                    )}
                  </label>
                ))}
              </div>
            )}
          </>
        )}

        {(hasDb || manifest.has_root_extend) && (
          <div className="BackupImport-serverState">
            <h4>{trans("server_state_title")}</h4>
            <p className="helpText">{trans("server_state_help")}</p>

            {hasDb &&
              this.sectionRow(
                "preserve_settings",
                this.preserveSettings,
                (v) => {
                  this.preserveSettings = v;
                },
              )}

            {manifest.has_root_extend &&
              this.sectionRow("root_extend", this.sectionRootExtend, (v) => {
                this.sectionRootExtend = v;
              })}

            {this.sectionRootExtend && (
              <div className="BackupImport-composerNote helpText">
                <i className="icon fas fa-triangle-exclamation" />{" "}
                {trans("root_extend_warning")}
              </div>
            )}
          </div>
        )}
      </fieldset>
    );
  }

  sectionRow(
    key:
      | "db"
      | "assets"
      | "storage"
      | "extensions"
      | "preserve_settings"
      | "root_extend",
    checked: boolean,
    set: (v: boolean) => void,
    count?: number,
  ) {
    return (
      <label className="BackupImport-sectionRow">
        <input
          type="checkbox"
          checked={checked}
          onchange={(e: Event) => set((e.target as HTMLInputElement).checked)}
        />{" "}
        <span className="BackupImport-sectionLabel">
          {trans("section_" + key)}
        </span>
        {count !== undefined && count > 0 && (
          <span className="BackupImport-sectionCount helpText">
            {" "}
            ({trans("section_count", { count })})
          </span>
        )}
      </label>
    );
  }

  buildSelection() {
    // The backend treats `extensions: true` as "all" and an array as
    // a whitelist of directory names. When every box is checked, send
    // `true` so the user's intent isn't lost if a new extension shows
    // up between inspect and apply (it shouldn't, but be defensive).
    const extEntries = Object.entries(this.extensionsByName);
    const allChecked = extEntries.length > 0 && extEntries.every(([, v]) => v);
    const extensionsField: boolean | string[] = !this.sectionExtensions
      ? false
      : allChecked
        ? true
        : extEntries.filter(([, v]) => v).map(([k]) => k);

    return {
      db: this.sectionDb,
      assets: this.sectionAssets,
      storage: this.sectionStorage,
      extensions: extensionsField,
      root_extend: this.sectionRootExtend,
      preserve_settings: this.preserveSettings,
    };
  }

  async startRestore() {
    if (!this.inspect) return;
    this.starting = true;

    try {
      const res = await apiRequest<{ phase: string; message: string }>({
        method: "POST",
        url: `${apiUrl()}/backup/imports/${this.inspect.job_id}/start`,
        surface: false,
        body: {
          private_key: this.privateKey.trim() || null,
          confirm_replace: this.confirmReplace,
          selection: this.buildSelection(),
        },
      });
      this.stage = "progress";
      this.status = {
        phase: res.phase as ImportProgress["phase"],
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
    } catch (e) {
      app.alerts.show(
        { type: "error" },
        errorDetail(e, String(trans("start_failed"))),
      );
    } finally {
      this.starting = false;
    }
  }

  // ────────────────────────────────────────── progress stage

  progressContent() {
    const s = this.status;
    if (!s) return <LoadingIndicator />;

    // Once the server reports phase=done we hand the screen over to a
    // dedicated completion view: the user has finished waiting and
    // now needs to know what to do next (which differs depending on
    // whether the DB was actually replaced).
    if (s.phase === "done") return this.completedContent();

    const isError = s.phase === "error";
    const pct = Math.max(0, Math.min(100, s.progress?.percent || 0));

    return (
      <div className="Modal-body BackupImport-progress">
        <div className={`BackupImport-status BackupImport-status--${s.phase}`}>
          <strong>{trans("phase_" + s.phase)}</strong>
          <p>{s.message}</p>
        </div>

        {!isError && (
          <div className="BackupImport-bar">
            <div
              className="BackupImport-bar-fill"
              style={{ width: `${pct}%` }}
            />
          </div>
        )}

        <div className="Form-group BackupImport-progress-actions">
          {!isError && (
            <Button className="Button" onclick={() => this.cancel()}>
              {trans("cancel_button")}
            </Button>
          )}
          {isError && (
            <Button
              className="Button Button--primary"
              onclick={() => this.close()}
            >
              {trans("close_button")}
            </Button>
          )}
        </div>
      </div>
    );
  }

  /**
   * Replaces the progress UI as soon as `phase === 'done'`. Two
   * shapes:
   *
   *   - DB restored — the admin's session was just wiped together
   *     with the rest of the `users` / `sessions` tables. We make
   *     this very clear and offer a single primary action: reload.
   *     Anything else (refreshing the list, dismissing) would race
   *     against an invalidated cookie and surface a confusing 401.
   *
   *   - Files only — the session is fine, just close.
   */
  /**
   * Warning block for a restore that finished but lost entries, or that
   * carries a stack advisory. Rendered on the completed screen in both
   * shapes — an incomplete restore must never present as clean success,
   * which is the failure mode this whole path exists to remove.
   */
  completedWarnings() {
    const warnings = this.status?.warnings ?? [];
    if (!warnings.length) return null;

    return (
      <div className="Alert Alert--error BackupImport-completedWarnings">
        <strong>{trans("incomplete_title")}</strong>
        <ul>
          {warnings.map((w) => (
            <li>{w}</li>
          ))}
        </ul>
      </div>
    );
  }

  completedContent() {
    if (this.sectionDb) {
      return (
        <div className="Modal-body BackupImport-completed BackupImport-completed--logout">
          {this.completedWarnings()}
          <div className="BackupImport-completedIcon">
            <i className="fas fa-right-from-bracket" />
          </div>
          <h3 className="BackupImport-completedTitle">
            {trans("logout_title")}
          </h3>
          <p className="BackupImport-completedBody">{trans("logout_body")}</p>

          <ol className="BackupImport-completedSteps">
            <li>{trans("logout_step_reload")}</li>
            <li>{trans("logout_step_login")}</li>
          </ol>

          <Button
            className="Button Button--primary BackupImport-completedAction"
            icon="fas fa-rotate"
            onclick={() => window.location.reload()}
          >
            {trans("logout_button")}
          </Button>
        </div>
      );
    }

    const incomplete = !!this.status?.incomplete;

    return (
      <div className="Modal-body BackupImport-completed">
        {this.completedWarnings()}
        <div
          className={`BackupImport-completedIcon BackupImport-completedIcon--${
            incomplete ? "warning" : "success"
          }`}
        >
          <i
            className={
              incomplete ? "fas fa-triangle-exclamation" : "fas fa-circle-check"
            }
          />
        </div>
        <h3 className="BackupImport-completedTitle">
          {trans(incomplete ? "incomplete_title" : "done_title")}
        </h3>
        <p className="BackupImport-completedBody">
          {trans(incomplete ? "incomplete_body" : "done_body")}
        </p>
        <Button className="Button Button--primary" onclick={() => this.close()}>
          {trans("close_button")}
        </Button>
      </div>
    );
  }

  async pump() {
    if (this.polling || !this.inspect) return;
    this.polling = true;

    try {
      while (
        this.inspect &&
        this.status &&
        this.status.phase !== "done" &&
        this.status.phase !== "error"
      ) {
        try {
          const res = await apiRequest<ImportProgress>({
            method: "POST",
            url: `${apiUrl()}/backup/imports/${this.inspect.job_id}/tick`,
            surface: false,
            // The server never stores the decryption key on disk; we
            // re-send it each tick so the inspect/extract phases can
            // decrypt. Harmless (ignored) for unencrypted archives and
            // once extraction is done.
            body: { private_key: this.privateKey.trim() || null },
          });
          this.status = res;
          m.redraw();
        } catch (e: any) {
          // Restore is more dangerous to leave hanging than export —
          // the server may still be mid-write. Surface a synthetic
          // error phase, and explicitly tell the user to verify
          // server state before retrying.
          console.error("[backup] import tick failed", e);
          const detail = errorDetail(e, String(trans("phase_error_network")));
          this.status = {
            ...this.status!,
            phase: "error",
            message: detail,
          };
          m.redraw();
          break;
        }
      }
      if (this.status?.phase === "done") {
        // Don't refresh the parent panel — when the backup includes
        // the database, restoring it has just replaced the sessions
        // table this admin is authenticated against. Any further API
        // call from this stale session would fail (401 / CSRF) and
        // surface as a confusing "Oops!" toast. The user clicks
        // Reload below and gets a clean session.
        app.alerts.show({ type: "success" }, trans("completed"));
        if (!this.sectionDb) this.attrs.onComplete();
      }
    } finally {
      this.polling = false;
    }
  }

  async cancel() {
    if (!this.inspect) return;
    try {
      await apiRequest({
        method: "DELETE",
        url: `${apiUrl()}/backup/imports/${this.inspect.job_id}`,
        surface: false,
      });
    } catch (e) {
      console.error("[backup] import cancel failed", e);
      app.alerts.show({ type: "warning" }, trans("cancel_failed_warn"));
    }
    this.close();
  }

  close() {
    this.hide();
  }
}
