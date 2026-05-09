import app from 'flarum/admin/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

import EncryptionCard from './EncryptionCard';
import BackupList, { BackupRow } from './BackupList';
import ExportModal from './ExportModal';
import ImportModal from './ImportModal';
import { confirmAsync } from './ConfirmModal';
import { apiRequest, apiUrl, errorDetail } from '../utils/api';
import { ErrorBoundary } from '../utils/errorBoundary';

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-backup.admin.${key}`, params ?? {});

export default class BackupPanel extends Component<ComponentAttrs> {
  protected listState: 'loading' | 'ok' | 'error' = 'loading';
  protected listError: string | null = null;
  protected backups: BackupRow[] = [];

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    this.refresh();
  }

  view() {
    return (
      <div className="BackupPanel">
        <ErrorBoundary onError={(e: unknown) => console.error('[backup] panel render', e)}>
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
            {this.renderList()}
          </section>
        </ErrorBoundary>
      </div>
    );
  }

  renderList(): Mithril.Children {
    if (this.listState === 'loading') return <LoadingIndicator />;
    if (this.listState === 'error') {
      return (
        <div className="Alert Alert--error BackupPanel-listError">
          <p>{trans('list.load_failed')}</p>
          {this.listError && (
            <p className="helpText">
              <code>{this.listError}</code>
            </p>
          )}
          <Button className="Button" icon="fas fa-rotate" onclick={() => this.refresh()}>
            {trans('list.retry')}
          </Button>
        </div>
      );
    }
    return (
      <BackupList
        backups={this.backups}
        onDelete={(id: number) => this.delete(id)}
        onRefresh={() => this.refresh()}
      />
    );
  }

  refresh(): Promise<void> {
    this.listState = 'loading';
    this.listError = null;
    return apiRequest<{ backups: BackupRow[] }>({
      method: 'GET',
      url: `${apiUrl()}/backup/backups`,
      surface: false,
    })
      .then((res) => {
        this.backups = res.backups || [];
        this.listState = 'ok';
      })
      .catch((e) => {
        this.backups = [];
        this.listState = 'error';
        this.listError = errorDetail(e);
      })
      .then(() => {
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
    const ok = await confirmAsync({
      title: trans('list.confirm_delete_title'),
      body: trans('list.confirm_delete'),
      confirmLabel: trans('list.delete_title'),
      danger: true,
    });
    if (!ok) return;
    try {
      await apiRequest({
        method: 'DELETE',
        url: `${apiUrl()}/backup/backups/${id}`,
        surface: false,
        fallbackMessage: String(trans('list.delete_failed')),
      });
      app.alerts.show({ type: 'success' }, trans('list.deleted'));
      this.refresh();
    } catch (e) {
      app.alerts.show({ type: 'error' }, errorDetail(e, String(trans('list.delete_failed'))));
    }
  }
}
