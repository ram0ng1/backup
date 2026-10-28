import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

import BackupPanel from './components/BackupPanel';

export default [
  new Extend.Admin()
    .customSetting(() => <BackupPanel />, 100)
    .permission(
      () => ({
        icon: 'fas fa-file-archive',
        label: app.translator.trans('ramon-backup.admin.permissions.manage_label'),
        permission: 'backup.manage',
      }),
      'moderate'
    ),
];
