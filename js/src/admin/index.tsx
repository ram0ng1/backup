import app from "flarum/admin/app";
import { override } from "flarum/common/extend";
import ExtensionPage from "flarum/admin/components/ExtensionPage";
import BackupPanel from "./components/BackupPanel";

const EXT_ID = "ramon-backup";

// Hide the default "Save changes" submit button on our settings page —
// every action here happens through dedicated buttons (export now, key
// rotation, etc.), there's no batch settings save to perform.
override(
  ExtensionPage.prototype,
  "submitButton",
  function (this: any, original: () => unknown) {
    if (this.extension && this.extension.id === EXT_ID) return null;
    return original();
  },
);

app.initializers.add(EXT_ID, () => {
  app.registry
    .for(EXT_ID)
    .registerSetting(() => <BackupPanel />, 100, "ramon-backup.panel")
    .registerPermission(
      {
        icon: "fas fa-file-archive",
        label: app.translator.trans(
          "ramon-backup.admin.permissions.manage_label",
        ),
        permission: "backup.manage",
      },
      "moderate",
    );
});
