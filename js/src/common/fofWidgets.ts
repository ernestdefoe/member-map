import { extend } from 'flarum/common/extend';

/*
 * fof/forum-widgets-core, shared by the forum (which renders the widget) and
 * the admin (whose layout editor places it). Resolved at RUNTIME, never a
 * build-time import, so the bundle is the same with or without it.
 *
 * 🚨 OPT-IN. FoF shows any registered widget that no saved layout mentions,
 * so a widget registered only on the forum side would appear on every forum
 * with FoF installed. It shows only once the admin has placed it and saved;
 * until then it waits in the editor's list of available widgets.
 */

export function fof(path: string): any {
  const mod = (globalThis as any).flarum?.reg?.get?.('fof-forum-widgets-core', path);
  return mod?.default ?? mod;
}

export function hasFofWidgets(): boolean {
  const extensions = (globalThis as any).flarum?.extensions;
  return !!extensions && 'fof-forum-widgets-core' in extensions;
}

/** Whether the saved layout places this widget in a section. */
export function placed(app: any, extension: string, key: string): boolean {
  return (app.widgets?.config?.instances || []).some((i: any) => i?.id === `${extension}:${key}`);
}

/** Offer the widget to FoF's layout editor (it lists only widgets registered on the admin side too), unplaced. */
export function registerEditorWidgets(
  app: any,
  extension: string,
  widgets: { key: string; icon: string; title: () => any; placement?: string }[]
): void {
  const Widgets = fof('common/extend/Widgets');
  const WidgetEditor = fof('admin/components/WidgetEditor');
  if (!Widgets || !WidgetEditor) return;

  extend(WidgetEditor.prototype, 'oninit', function (this: any) {
    const manager = app.widgets;
    if (!manager) return;

    if (!manager.widgets.some((w: any) => w.extension === extension)) {
      const add = new Widgets();
      widgets.forEach((w, position) =>
        add.add({
          key: w.key,
          component: { component: () => null },
          title: w.title,
          icon: w.icon,
          isDisabled: false,
          isUnique: true,
          placement: w.placement || 'end',
          position,
        })
      );
      add.extend(app, extension);
    }

    const config = this.config || {};
    const disabled = config.disabled || [];
    const unplaced = widgets
      .map((w) => `${extension}:${w.key}`)
      .filter((id) => !disabled.includes(id) && !(config.instances || []).some((i: any) => i?.id === id));
    config.disabled = [...disabled, ...unplaced];
  });
}
