// Runs the chat template's renderMarkdown against a minimal fake DOM.
const fs = require('fs');
const tpl = fs.readFileSync(process.argv[2], 'utf8');
const src = tpl.split('/* markdown:start */')[1].split('/* markdown:end */')[0];

class N {
  constructor(tag) { this.tag = tag; this.children = []; this.attrs = {}; }
  appendChild(c) { if (c.tag === '#frag') { this.children.push(...c.children); } else { this.children.push(c); } return c; }
  set textContent(t) { this.children = [new T(String(t))]; }
  get lastChild() { return this.children[this.children.length - 1]; }
  set href(v) { this.attrs.href = v; } set target(v) { this.attrs.target = v; } set rel(v) { this.attrs.rel = v; }
}
class T { constructor(t) { this.tag = '#text'; this.text = t; } }
const document = {
  createElement: (t) => new N(t),
  createTextNode: (t) => new T(t),
  createDocumentFragment: () => new N('#frag'),
};
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const ser = (n) => n.tag === '#text' ? esc(n.text)
  : n.tag === '#frag' ? n.children.map(ser).join('')
  : `<${n.tag}${Object.entries(n.attrs).map(([k, v]) => ` ${k}="${v}"`).join('')}>${n.children.map(ser).join('')}</${n.tag}>`;

const renderMarkdown = new Function('document', src + '; return renderMarkdown;')(document);
const cases = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
let fail = 0;
for (const [name, input, expected] of cases) {
  const got = ser(renderMarkdown(input));
  const ok = got === expected;
  if (!ok) fail++;
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : `\n     got:      ${got}\n     expected: ${expected}`}`);
}
process.exit(fail ? 1 : 0);
