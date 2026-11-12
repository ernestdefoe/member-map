// Turns MapLibre's worker into `export default "<its code>"` for the engine chunk (see webpack.config.js).
module.exports = function (source) {
  const code = source.replace(/^\/\/# sourceMappingURL=.*$/gm, '');
  if (code.includes('sourceMappingURL')) throw new Error('worker-source: a sourceMappingURL comment is left in the worker');
  return 'export default ' + JSON.stringify(code) + ';';
};
