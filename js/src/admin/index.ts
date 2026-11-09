import app from 'flarum/admin/app';
import { registerEditorWidgets } from '../common/fofWidgets';

export { default as extend } from './extend';

app.initializers.add('ernestdefoe/member-map', () => {
  registerEditorWidgets(app, 'ernestdefoe-member-map', [
    { key: 'whereWeAre', icon: 'fas fa-earth-americas', title: () => app.translator.trans('ernestdefoe-member-map.admin.widget') },
  ]);
});
