const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const script = fs.readFileSync(path.resolve(__dirname, '../../assets/js/flame-graph.js'), 'utf8');
function element(id) {
  return { id, children: [], firstChild: null, offsetWidth: 1000, style: {}, value: '', textContent: '', appendChild(child) { this.children.push(child); this.firstChild = this.children[0] || null; if (child && child.markup) this.markup = child.markup; return child; }, removeChild(child) { this.children = this.children.filter((item) => item !== child); this.firstChild = this.children[0] || null; return child; }, addEventListener() {}, querySelectorAll() { return []; } };
}
const elements = { 'wp-flame-graph': element('graph'), 'wp-flame-breadcrumbs': element('crumbs') };
const spans = [];
for (let i = 0; i < 6000; i++) spans.push({ id: `s-${i}`, parent_id: null, name: `Span ${i}`, type: 'php', source: 'fixture', start_ms: i / 10, duration_ms: 1 });
let svg = '';
const context = {
  window: { innerWidth: 1200, innerHeight: 800, addEventListener() {}, wpFlameTrace: { total_ms: 600, spans } },
  document: { getElementById(id) { return elements[id] || null; }, createElement(id) { return element(id); }, createTextNode(text) { return { textContent: text }; }, addEventListener() {}, removeEventListener() {}, importNode(node) { return node; } },
  DOMParser: function DOMParser() { this.parseFromString = (markup) => { svg = markup; return { documentElement: { markup } }; }; },
  setTimeout, clearTimeout, isFinite, Number, String, Math, Object, Array,
};
context.window.document = context.document;
const started = Date.now();
vm.runInNewContext(script, context, { filename: 'assets/js/flame-graph.js' });
const elapsed = Date.now() - started;

assert.strictEqual((svg.match(/class="wp-flame-span"/g) || []).length, 5000);
assert.ok(svg.includes('Span 4999'));
assert.ok(!svg.includes('Span 5000'));
assert.ok(elapsed < 2000, `large fixture render took ${elapsed}ms`);
if (process.env.WP_FLAME_BENCHMARK === '1') {
  process.stdout.write(JSON.stringify({ input_spans: 6000, rendered_spans: 5000, elapsed_ms: elapsed }) + '\n');
}
