// Install clean-css@5 and terser@5 in a temporary directory; pass its node_modules path.
const fs = require('fs');
const path = require('path');
const modules = process.argv[2];
if (!modules) throw new Error('Usage: node scripts/build-atlas-assets.cjs /path/to/node_modules');
const CleanCSS = require(path.resolve(modules, 'clean-css'));
const terser = require(path.resolve(modules, 'terser'));
const assets = path.resolve(__dirname, '../public/assets');
(async () => {
  const css = new CleanCSS({level:1,rebase:false}).minify(fs.readFileSync(path.join(assets,'css/atlas.css'),'utf8'));
  if (css.errors.length) throw new Error(css.errors.join('\n'));
  const js = await terser.minify(fs.readFileSync(path.join(assets,'js/atlas.js'),'utf8'),{compress:true,mangle:true,format:{comments:/ServerNet/}});
  if (!js.code || !css.styles) throw new Error('Empty assets');
  fs.writeFileSync(path.join(assets,'css/atlas.min.css'),css.styles);
  fs.writeFileSync(path.join(assets,'js/atlas.min.js'),js.code);
})();
