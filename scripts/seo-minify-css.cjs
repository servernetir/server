// Whitespace/comments only: preserve rule order, declarations and URLs.
// Usage: node scripts/seo-minify-css.cjs input.css output.min.css
const fs = require('node:fs');
const CleanCSS = require('../tools/seo-css/node_modules/clean-css');
const input = fs.readFileSync(process.argv[2], 'utf8');
const result = new CleanCSS({level: {1: {all: false, specialComments: 0}}, rebase: false, inline: ['none']}).minify(input);
if (result.errors.length || result.warnings.length) {
  throw new Error(JSON.stringify({errors: result.errors, warnings: result.warnings}));
}
fs.writeFileSync(process.argv[3], result.styles);
console.log(JSON.stringify({sourceBytes: Buffer.byteLength(input), minBytes: Buffer.byteLength(result.styles)}));
