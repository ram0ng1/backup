import app from "flarum/admin/app";
import Modal, { IInternalModalAttrs } from "flarum/common/components/Modal";
import Button from "flarum/common/components/Button";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import type Mithril from "mithril";

import { apiRequest, apiUrl, errorDetail, fmtBytes } from "../utils/api";

export interface ExportModalAttrs extends IInternalModalAttrs {
  onComplete: () => void;
}

interface ExtensionEntry {
  id: string;
  name: string;
  title: string;
  version: string;
  location: "workbench" | "vendor" | "unknown";
  path: string;
  relative: string;
  enabled: boolean;
}

interface ExportProgress {
  phase: "scan" | "db_dump" | "bundle" | "finalize" | "done" | "error";
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
  /**
   * Per-column notes from the introspector about lossy translations:
   * unsupported types coerced to TEXT/BLOB, generated columns whose
   * expressions can't be replicated cross-engine, etc. Backend always
   * returns an array (possibly empty); the UI shows them only when
   * non-empty.
   */
  warnings?: string[];
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
  protected stage: "form" | "progress" = "form";

  protected includeDb = true;
  protected includeAssets = true;
  protected includeStorage = false;
  protected includeExtensions = false;

  // Per-extension selection. Loaded lazily when the user ticks
  // "Extensions" for the first time so we don't fire an extra request
  // for admins who never use the feature.
  protected extensionsLoading = false;
  protected extensionsLoaded = false;
  protected extensions: ExtensionEntry[] = [];
  protected extensionSelected: Record<string, boolean> = {};

  protected encryptionEnabled = false;
  protected encryptionUseExternal = false;
  protected externalPublicKey = "";

  // Target engine the dump should be generated for. Empty string =
  // "same as source" (the most common case — backing up to restore
  // onto the same install / a clone of it). The non-empty values
  // make this a cross-engine migration: e.g. dump from MySQL,
  // restore onto Postgres.
  protected targetDialect: "" | "mysql" | "mariadb" | "postgres" | "sqlite" =
    "";

  protected starting = false;
  protected jobId: string | null = null;
  protected status: ExportProgress | null = null;
  protected polling = false;

  className() {
    return "BackupExportModal Modal--medium";
  }

  title() {
    return trans("title");
  }

  content() {
    if (this.stage === "form") return this.formContent();
    return this.progressContent();
  }

  // ────────────────────────────────────────── form

  formContent() {
    return (
      <div className="Modal-body">
        <p className="helpText">{trans("intro")}</p>

        <fieldset className="BackupExport-fieldset">
          <legend>{trans("contents_title")}</legend>

          {this.checkbox(
            "db",
            () => this.includeDb,
            (v) => (this.includeDb = v),
          )}
          {this.checkbox(
            "assets",
            () => this.includeAssets,
            (v) => (this.includeAssets = v),
          )}
          {this.checkbox(
            "storage",
            () => this.includeStorage,
            (v) => (this.includeStorage = v),
          )}
          {this.checkbox(
            "extensions",
            () => this.includeExtensions,
            (v) => {
              this.includeExtensions = v;
              // Lazy-load the extension inventory the first time
              // someone ticks the box. The list comes back fast (no
              // disk walking — just metadata from the ExtensionManager).
              if (v && !this.extensionsLoaded) this.loadExtensions();
            },
          )}

          {this.includeExtensions && this.extensionList()}
        </fieldset>

        {this.includeDb && (
          <fieldset className="BackupExport-fieldset">
            <legend>{trans("target_title")}</legend>
            <p className="helpText">{trans("target_help")}</p>
            <select
              className="FormControl BackupExport-targetSelect"
              value={this.targetDialect}
              onchange={(e: Event) => {
                this.targetDialect = (e.target as HTMLSelectElement)
                  .value as typeof this.targetDialect;
              }}
            >
              <option value="">{trans("target_same")}</option>
              <option value="mysql">{trans("target_mysql")}</option>
              <option value="mariadb">{trans("target_mariadb")}</option>
              <option value="postgres">{trans("target_postgres")}</option>
              <option value="sqlite">{trans("target_sqlite")}</option>
            </select>
          </fieldset>
        )}

        <fieldset className="BackupExport-fieldset">
          <legend>{trans("encryption_title")}</legend>

          <label className="BackupExport-checkbox">
            <input
              type="checkbox"
              checked={this.encryptionEnabled}
              onchange={(e: Event) => {
                this.encryptionEnabled = (e.target as HTMLInputElement).checked;
              }}
            />{" "}
            <span>{trans("encryption_enable")}</span>
          </label>
          <p className="helpText">{trans("encryption_help")}</p>

          {this.encryptionEnabled && (
            <>
              <label className="BackupExport-checkbox">
                <input
                  type="checkbox"
                  checked={this.encryptionUseExternal}
                  onchange={(e: Event) => {
                    this.encryptionUseExternal = (
                      e.target as HTMLInputElement
                    ).checked;
                  }}
                />{" "}
                <span>{trans("encryption_external")}</span>
              </label>
              {this.encryptionUseExternal && (
                <>
                  <p className="helpText">
                    {trans("encryption_external_help")}
                  </p>
                  <textarea
                    className="FormControl BackupExport-keyInput"
                    rows={3}
                    placeholder="base64 public key"
                    value={this.externalPublicKey}
                    oninput={(e: Event) => {
                      this.externalPublicKey = (
                        e.target as HTMLTextAreaElement
                      ).value;
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
            {trans("start_button")}
          </Button>
        </div>
      </div>
    );
  }

  extensionList(): Mithril.Children {
    if (this.extensionsLoading) {
      return (
        <div className="BackupExport-extLoading">
          <LoadingIndicator />
        </div>
      );
    }
    if (!this.extensions.length) {
      return (
        <p className="helpText BackupExport-extEmpty">
          {trans("extensions_none")}
        </p>
      );
    }

    const groups: Record<string, ExtensionEntry[]> = {
      workbench: [],
      vendor: [],
      unknown: [],
    };
    for (const ext of this.extensions) groups[ext.location]?.push(ext);

    return (
      <div className="BackupExport-extList">
        <div className="BackupExport-extActions">
          <button
            type="button"
            className="BackupExport-extLink"
            onclick={() => this.toggleAllExtensions(true)}
          >
            {trans("extensions_select_all")}
          </button>
          <span> · </span>
          <button
            type="button"
            className="BackupExport-extLink"
            onclick={() => this.toggleAllExtensions(false)}
          >
            {trans("extensions_select_none")}
          </button>
        </div>

        {/*
          Filter out empty groups before mapping — Mithril rejects
          mixed `[vnode-with-key, null, vnode-with-key]` arrays
          ("In fragments, vnodes must either all have keys or none").
        */}
        {(["workbench", "vendor", "unknown"] as const)
          .filter((loc) => groups[loc].length > 0)
          .map((loc) => (
            <div className="BackupExport-extGroup" key={loc}>
              <div className="BackupExport-extGroupHeader">
                {trans("extensions_group_" + loc)}{" "}
                <span className="helpText">({groups[loc].length})</span>
              </div>
              {groups[loc].map((ext) => (
                <label className="BackupExport-extRow" key={ext.id}>
                  <input
                    type="checkbox"
                    checked={!!this.extensionSelected[ext.id]}
                    onchange={(e: Event) => {
                      this.extensionSelected[ext.id] = (
                        e.target as HTMLInputElement
                      ).checked;
                    }}
                  />{" "}
                  <span className="BackupExport-extTitle">{ext.title}</span>{" "}
                  <code className="BackupExport-extName">
                    {ext.name || ext.id}
                  </code>
                  <span
                    className={`BackupExport-extTag BackupExport-extTag--${ext.location}`}
                  >
                    {trans("extensions_tag_" + ext.location)}
                  </span>
                </label>
              ))}
            </div>
          ))}
      </div>
    );
  }

  toggleAllExtensions(value: boolean) {
    for (const ext of this.extensions) this.extensionSelected[ext.id] = value;
  }

  async loadExtensions() {
    this.extensionsLoading = true;
    try {
      const res = await apiRequest<{ extensions: ExtensionEntry[] }>({
        method: "GET",
        url: `${apiUrl()}/backup/extensions`,
        surface: false,
      });
      this.extensions = res.extensions || [];
      this.extensionsLoaded = true;
      // Default: every extension ticked. The admin un-ticks the
      // ones they don't want.
      for (const ext of this.extensions) this.extensionSelected[ext.id] = true;
    } catch (e) {
      app.alerts.show(
        { type: "error" },
        errorDetail(e, String(trans("extensions_load_failed"))),
      );
    } finally {
      this.extensionsLoading = false;
      m.redraw();
    }
  }

  checkbox(
    key: "db" | "assets" | "storage" | "extensions",
    get: () => boolean,
    set: (v: boolean) => void,
  ) {
    return (
      <label className="BackupExport-checkbox">
        <input
          type="checkbox"
          checked={get()}
          onchange={(e: Event) => {
            set((e.target as HTMLInputElement).checked);
          }}
        />{" "}
        <span className="BackupExport-checkbox-label">
          {trans("content_" + key)}
        </span>
        <span className="BackupExport-checkbox-help helpText">
          {trans("content_" + key + "_help")}
        </span>
      </label>
    );
  }

  canStart(): boolean {
    if (
      !this.includeDb &&
      !this.includeAssets &&
      !this.includeStorage &&
      !this.includeExtensions
    ) {
      return false;
    }
    if (
      this.encryptionEnabled &&
      this.encryptionUseExternal &&
      !this.externalPublicKey.trim()
    ) {
      return false;
    }
    return true;
  }

  // ────────────────────────────────────────── progress

  progressContent() {
    const s = this.status;
    if (!s) return <LoadingIndicator />;

    const isDone = s.phase === "done";
    const isError = s.phase === "error";
    const pct = Math.max(0, Math.min(100, s.progress?.percent || 0));

    return (
      <div className="Modal-body BackupExport-progress">
        <div className={`BackupExport-status BackupExport-status--${s.phase}`}>
          <strong>{trans("phase_" + s.phase)}</strong>
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
              <span>
                {fmtBytes(s.progress.processed_bytes)} /{" "}
                {fmtBytes(s.progress.total_bytes || s.progress.processed_bytes)}
              </span>
              {s.progress.total_files > 0 && (
                <span>
                  {trans("files_count", {
                    done: s.progress.processed_files,
                    total: s.progress.total_files,
                  })}
                </span>
              )}
            </div>
          </>
        )}

        {/*
          Cross-engine translation notes. The backend always returns
          an array — show the block only when it has entries so a
          clean same-engine backup doesn't get a noisy "no warnings"
          panel. Surfaced as soon as they appear (not gated on
          phase=done) so the admin sees them while watching the
          progress bar, not after the modal closes.
        */}
        {(s.warnings?.length ?? 0) > 0 && (
          <div className="BackupExport-warnings" role="alert">
            <div className="BackupExport-warnings-title">
              <i className="icon fas fa-triangle-exclamation" />{" "}
              {trans("warnings_title", { count: s.warnings!.length })}
            </div>
            <p className="helpText">{trans("warnings_help")}</p>
            <ul className="BackupExport-warnings-list">
              {s.warnings!.map((w, idx) => (
                <li key={idx}>{w}</li>
              ))}
            </ul>
          </div>
        )}

        <div className="Form-group BackupExport-progress-actions">
          {!isDone && !isError && (
            <Button className="Button" onclick={() => this.cancel()}>
              {trans("cancel_button")}
            </Button>
          )}
          {(isDone || isError) && (
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

  // ────────────────────────────────────────── api calls

  async start() {
    this.starting = true;
    try {
      // The backend accepts `extensions` as bool OR string[]. When
      // every box is checked we still send the array (explicit),
      // unless the inventory hasn't even loaded yet — which means
      // the admin ticked the section but never opened the list and
      // implicitly wants "all".
      let extensionsField: boolean | string[] = false;
      if (this.includeExtensions) {
        if (!this.extensionsLoaded) {
          extensionsField = true;
        } else {
          const ids = Object.entries(this.extensionSelected)
            .filter(([, v]) => v)
            .map(([k]) => k);
          extensionsField = ids;
        }
      }

      const res = await apiRequest<{
        job_id: string;
        phase: string;
        message: string;
      }>({
        method: "POST",
        url: `${apiUrl()}/backup/exports`,
        body: {
          contents: {
            db: this.includeDb,
            assets: this.includeAssets,
            storage: this.includeStorage,
            extensions: extensionsField,
          },
          encryption: {
            enabled: this.encryptionEnabled,
            public_key: this.encryptionUseExternal
              ? this.externalPublicKey.trim()
              : null,
          },
          // Empty string = "same as source"; the backend treats null
          // and "" identically so this carries the user's choice
          // through unambiguously.
          target_dialect: this.targetDialect || null,
        },
        surface: false,
      });

      this.jobId = res.job_id;
      this.stage = "progress";
      this.status = {
        phase: res.phase as ExportProgress["phase"],
        message: res.message,
        progress: {
          total_bytes: 0,
          processed_bytes: 0,
          total_files: 0,
          processed_files: 0,
          percent: 0,
        },
      };
      this.starting = false;
      m.redraw();
      this.pump();
    } catch (e) {
      this.starting = false;
      app.alerts.show(
        { type: "error" },
        errorDetail(e, String(trans("start_failed"))),
      );
      m.redraw();
    }
  }

  async pump() {
    if (this.polling || !this.jobId) return;
    this.polling = true;
    try {
      // Sequential ticks — each /tick call performs ~4MB of work.
      while (
        this.jobId &&
        this.status &&
        this.status.phase !== "done" &&
        this.status.phase !== "error"
      ) {
        try {
          const res = await apiRequest<ExportProgress>({
            method: "POST",
            url: `${apiUrl()}/backup/exports/${this.jobId}/tick`,
            surface: false,
          });
          this.status = res;
          m.redraw();
        } catch (e) {
          // Convert tick failures into a synthetic error phase so the
          // existing UI shows the close button and a meaningful
          // message instead of freezing on the last %.
          const detail = errorDetail(e, String(trans("phase_error_network")));
          this.status = {
            ...(this.status as ExportProgress),
            phase: "error",
            message: detail,
          };
          m.redraw();
          break;
        }
      }
      if (this.status?.phase === "done") {
        app.alerts.show({ type: "success" }, trans("completed"));
        this.attrs.onComplete();
      }
    } finally {
      this.polling = false;
    }
  }

  async cancel() {
    if (!this.jobId) return;
    try {
      await apiRequest({
        method: "DELETE",
        url: `${apiUrl()}/backup/exports/${this.jobId}`,
        surface: false,
      });
    } catch (e) {
      // The job may still be holding a server-side lock — let the user
      // know so they understand if the next export complains.
      console.warn("[backup] export cancel failed", e);
      app.alerts.show({ type: "warning" }, trans("cancel_failed_warn"));
    }
    this.close();
  }

  close() {
    this.jobId = null;
    this.hide();
  }
}
