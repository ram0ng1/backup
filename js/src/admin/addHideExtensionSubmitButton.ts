import { override } from 'flarum/common/extend';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';

const EXT_ID = 'ramon-backup';

// Cada ação do painel tem o próprio botão (criar, importar, gerar chave):
// não há configuração em lote para o botão de salvar padrão enviar.
export default function addHideExtensionSubmitButton(): void {
  override(ExtensionPage.prototype, 'submitButton', function (this: any, original: () => unknown) {
    if (this.extension && this.extension.id === EXT_ID) return null;
    return original();
  });
}
