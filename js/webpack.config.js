const config = require('flarum-webpack-config');
const webpack = require('webpack');

const NS = 'ernestdefoe-member-map';

/*
 * 🚨 Lazy chunks need ids no other extension can have: webpack's default
 * 3-digit ids can collide with another extension's chunk, so the wrong file
 * is fetched or the chunk counts as already loaded. Ids are prefixed with our
 * extension id (same as onair-plus).
 */
class NamespacedChunkIds {
  apply(compiler) {
    compiler.hooks.compilation.tap('NamespacedChunkIds', (compilation) => {
      compilation.hooks.chunkIds.tap('NamespacedChunkIds', (chunks) => {
        for (const chunk of chunks) {
          if (chunk.id === null) {
            chunk.id = `${NS}:${chunk.name || chunk.debugId}`;
            chunk.ids = [chunk.id];
          }
        }
      });
    });
  }
}

module.exports = (...args) => {
  let base = config();
  if (typeof base === 'function') base = base(...args);

  base.optimization = { ...base.optimization, chunkIds: false };
  // MapLibre computes a default worker URL from import.meta.url, which webpack would bake in as
  // this machine's file:// path (a stale bundle in CI, and a local path in the shipped file). The
  // engine always sets the worker URL itself (map/engine.ts prepare()), so the default is unused.
  base.plugins = [...(base.plugins || []), new NamespacedChunkIds(), new webpack.DefinePlugin({ 'import.meta.url': '""' })];

  return base;
};
