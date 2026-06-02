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
    offsetHeight: 20,
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

      const markup = this.markup || '';
      const spanMatch = markup.match(/<g class="wp-flame-span"([^>]*)>/);
      if (!spanMatch) {
        return [];
      }

      return [{
        addEventListener(eventName, callback) {
          if (eventName === 'mouseenter') {
            callback({
              currentTarget: {
                getAttribute(name) {
                  const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                  const match = spanMatch[1].match(new RegExp(`${escaped}="([^"]*)"`));
                  return match ? match[1] : '';
                },
              },
            });
          }
        },
      }];
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
};

const context = {
  console,
  window: {
    innerWidth: 1024,
    innerHeight: 768,
    addEventListener() {},
    wpFlameTrace: {
      total_ms: 25,
      spans: [{
        id: 'span-1',
        parent_id: null,
        name: 'Plugin Span',
        type: 'plugin',
        source: 'test',
        start_ms: 0,
        duration_ms: 25,
      }],
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
  RegExp,
};

context.window.document = context.document;

vm.runInNewContext(script, context, {
  filename: 'assets/js/flame-graph.js',
});

assert.ok(capturedSvg.includes('Plugin Span'));
