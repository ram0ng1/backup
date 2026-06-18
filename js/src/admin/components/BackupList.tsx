import app from "flarum/admin/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import humanTime from "flarum/common/helpers/humanTime";
import type Mithril from "mithril";

import { apiUrl, fmtBytes } from "../utils/api";

export interface BackupRow {
  id: number;
  filename: string;
  size_bytes: number;
  encrypted: boolean;
  contents: string[];
  flarum_version: string | null;
  php_version: string | null;
  /**
   * Engine the SQL dump targets. NULL = same as source (a regular
   * backup of this install). Anything else (mysql, mariadb, postgres,
   * sqlite) means the dump was retargeted at export time and is
   * expected to be restored onto that engine.
   */
  target_dialect: string | null;
  created_at: string | null;
  created_by: number | null;
}

const DIALECT_LABEL: Record<string, string> = {
  mysql: "MySQL",
  mariadb: "MariaDB",
  postgres: "PostgreSQL",
  sqlite: "SQLite",
};

export interface BackupListAttrs extends ComponentAttrs {
  backups: BackupRow[];
  onDelete: (id: number) => void;
  onRefresh: () => void;
}

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-backup.admin.list.${key}`, params ?? {});

export default class BackupList extends Component<BackupListAttrs> {
  view(vnode: Mithril.Vnode<BackupListAttrs, this>) {
    const { backups, onDelete } = vnode.attrs;

    if (!backups.length) {
      return <p className="BackupList-empty helpText">{trans("empty")}</p>;
    }

    return (
      <table className="BackupList Table">
        <thead>
          <tr>
            <th>{trans("col_when")}</th>
            <th>{trans("col_size")}</th>
            <th>{trans("col_contents")}</th>
            <th>{trans("col_status")}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {backups.map((b) => (
            <tr key={b.id} className="BackupList-row">
              <td>
                <div className="BackupList-when">
                  {b.created_at ? humanTime(new Date(b.created_at)) : "—"}
                </div>
                <div className="BackupList-filename">{b.filename}</div>
                {b.target_dialect && (
                  // Only shown when the admin retargeted the dump at
                  // export time — same-engine backups have a NULL
                  // target_dialect and don't need the visual noise.
                  <div
                    className={`BackupList-target BackupList-target--${b.target_dialect}`}
                    title={String(
                      trans("target_tooltip", {
                        engine:
                          DIALECT_LABEL[b.target_dialect] || b.target_dialect,
                      }),
                    )}
                  >
                    <i className="icon fas fa-arrow-right-arrow-left" />{" "}
                    {trans("target_for", {
                      engine:
                        DIALECT_LABEL[b.target_dialect] || b.target_dialect,
                    })}
                  </div>
                )}
              </td>
              <td>{fmtBytes(b.size_bytes)}</td>
              <td>
                {b.contents.map((c) => (
                  <span className={`BackupList-tag BackupList-tag--${c}`}>
                    {trans("content_" + c)}
                  </span>
                ))}
              </td>
              <td>
                {b.encrypted ? (
                  <span className="BackupList-encryption BackupList-encryption--on">
                    <i className="icon fas fa-lock" /> {trans("encrypted")}
                  </span>
                ) : (
                  <span className="BackupList-encryption BackupList-encryption--off">
                    <i className="icon fas fa-lock-open" /> {trans("plain")}
                  </span>
                )}
              </td>
              <td className="BackupList-actions">
                <a
                  className="Button Button--icon"
                  href={`${apiUrl()}/backup/backups/${b.id}/download`}
                  target="_blank"
                  title={String(trans("download_title"))}
                >
                  <i className="icon fas fa-download" />
                </a>
                <Button
                  className="Button Button--icon Button--danger"
                  icon="fas fa-trash"
                  title={trans("delete_title")}
                  onclick={() => onDelete(b.id)}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    );
  }
}
