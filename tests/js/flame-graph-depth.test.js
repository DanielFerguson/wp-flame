const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const repoDir = path.resolve(__dirname, '../..');
const script = fs.readFileSync(path.join(repoDir, 'assets/js/flame-graph.js'), 'utf8');

function makeElement(id) {
  const element = {
    id,
    children: [],
    firstChild: null,
    offsetWidth: 800,
    style: {},
    className: '',
    textContent: '',
    appendChild(child) {
      this.children.push(child);
      this.firstChild = this.children[0] || null;
      if (child && typeof child.markup === 'string') {
        this.markup = child.markup;
      }
      return child;
    },
    removeChild(child) {
      this.children = this.children.filter((item) => item !== child);
      this.firstChild = this.children[0] || null;
      return child;
    },
    querySelectorAll(selector) {
      if (selector !== '.wp-flame-span') {
        return [];
      }

      const matches = (this.markup || '').match(/class="wp-flame-span"/g) || [];
      return matches.map(() => ({
        addEventListener() {},
      }));
    },
    addEventListener() {},
    getAttribute() {
      return '';
    },
  };

  return element;
}

const spans = [];
for (let i = 0; i < 300; i++) {
  spans.push({
    id: `span-${i}`,
    parent_id: i === 0 ? null : `span-${i - 1}`,
    name: `Span ${i}`,
    type: 'plugin',
    source: 'test',
    start_ms: i,
    duration_ms: 300 - i,
  });
}

let capturedSvg = '';
const elements = {
  'wp-flame-graph': makeElement('wp-flame-graph'),
  'wp-flame-breadcrumbs': makeElement('wp-flame-breadcrumbs'),
  'wp-flame-tooltip': makeElement('wp-flame-tooltip'),
};

const context = {
  console,
  window: {
    innerWidth: 1024,
    innerHeight: 768,
    addEventListener() {},
    wpFlameTrace: {
      total_ms: 300,
      spans,
    },
  },
  document: {
    getElementById(id) {
      return elements[id] || null;
    },
    createElement(id) {
      return makeElement(id);
    },
    createTextNode(text) {
      return { textContent: text };
    },
    addEventListener() {},
    removeEventListener() {},
    importNode(node) {
      return node;
    },
  },
  DOMParser: function DOMParser() {
    this.parseFromString = function parseFromString(markup) {
      capturedSvg = markup;
      return {
        documentElement: {
          markup,
        },
      };
    };
  },
  setTimeout,
  clearTimeout,
  isFinite,
  Number,
  String,
  Math,
  Object,
  Array,
};

context.window.document = context.document;

vm.runInNewContext(script, context, {
  filename: 'assets/js/flame-graph.js',
});

const renderedSpans = (capturedSvg.match(/class="wp-flame-span"/g) || []).length;
assert.strictEqual(renderedSpans, 201);
assert.ok(capturedSvg.includes('Span 200'));
assert.ok(!capturedSvg.includes('Span 201'));
assert.ok(!capturedSvg.includes('NaN'));
