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
      total_ms: 0,
      spans: [
        {
          id: '__proto__',
          parent_id: null,
          name: 'Prototype key',
          type: 'plugin',
          source: 'test',
          start_ms: -10,
          duration_ms: '1e9999',
        },
        {
          id: 'cycle-a',
          parent_id: 'cycle-b',
          name: 'Cycle A',
          type: 'plugin',
          source: 'test',
          start_ms: 0,
          duration_ms: 10,
        },
        {
          id: 'cycle-b',
          parent_id: 'cycle-a',
          name: 'Cycle B',
          type: 'plugin',
          source: 'test',
          start_ms: 0,
          duration_ms: 10,
        },
      ],
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

assert.strictEqual((capturedSvg.match(/class="wp-flame-span"/g) || []).length, 3);
assert.ok(capturedSvg.includes('data-id="__proto__"'));
assert.ok(!capturedSvg.includes('NaN'));
assert.ok(!capturedSvg.includes('Infinity'));
