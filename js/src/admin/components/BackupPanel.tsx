import app from 'flarum/admin/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

import EncryptionCard from './EncryptionCard';
import BackupList, { BackupRow } from './BackupList';
import ExportModal from './ExportModal';
import ImportModal from './ImportModal';
import { apiUrl } from '../utils/api';

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-backup.admin.${key}`, params ?? {});

export default class BackupPanel extends Component<ComponentAttrs> {
  protected loading = true;
  protected backups: BackupRow[] = [];

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    this.refresh();
  }

  view() {
    return (
      <div className="BackupPanel">
        <section className="BackupPanel-actions">
          <h3>{trans('panel.actions_title')}</h3>
          <p className="helpText">{trans('panel.actions_help')}</p>

          <div className="BackupPanel-actionButtons">
            <Button className="Button Button--primary" icon="fas fa-download" onclick={() => this.openExport()}>
              {trans('panel.create_button')}
            </Button>
            <Button className="Button" icon="fas fa-upload" onclick={() => this.openImport()}>
              {trans('panel.import_button')}
            </Button>
          </div>
        </section>

        <EncryptionCard />

        <section className="BackupPanel-list">
          <h3>{trans('panel.list_title')}</h3>
          {this.loading ? (
            <LoadingIndicator />
          ) : (
            <BackupList
              backups={this.backups}
              onDelete={(id: number) => this.delete(id)}
              onRefresh={() => this.refresh()}
            />
          )}
        </section>
      </div>
    );
  }

  refresh(): Promise<void> {
    this.loading = true;
    return app
      .request<{ backups: BackupRow[] }>({ method: 'GET', url: `${apiUrl()}/backup/backups` })
      .then((res) => {
        this.backups = res.backups || [];
      })
      .catch(() => {
        this.backups = [];
      })
      .then(() => {
        this.loading = false;
        m.redraw();
      });
  }

  openExport() {
    app.modal.show(ExportModal, {
      onComplete: () => this.refresh(),
    });
  }

  openImport() {
    app.modal.show(ImportModal, {
      onComplete: () => this.refresh(),
    });
  }

  async delete(id: number) {
    if (!confirm(app.translator.trans('ramon-backup.admin.list.confirm_delete') as string)) return;
    try {
      await app.request({
        method: 'DELETE',
        url: `${apiUrl()}/backup/backups/${id}`,
      });
      app.alerts.show({ type: 'success' }, trans('list.deleted'));
      this.refresh();
    } catch {
      app.alerts.show({ type: 'error' }, trans('list.delete_failed'));
    }
  }
}
