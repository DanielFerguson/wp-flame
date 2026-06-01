const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const repoDir = path.resolve(__dirname, '../..');
const script = fs.readFileSync(path.join(repoDir, 'assets/js/admin-bar.js'), 'utf8');

let clickHandler = null;
let cookieValue = '';
let reloaded = false;

const context = {
  window: {
    wpFlameAdminBar: {
      forceTraceNonce: 'nonce value+/=',
    },
  },
  document: {
    getElementById(id) {
      if (id !== 'wp-admin-bar-wp-flame-trace') {
        return null;
      }

      return {
        addEventListener(eventName, handler) {
          if (eventName === 'click') {
            clickHandler = handler;
          }
        },
      };
    },
  },
  location: {
    protocol: 'https:',
    reload() {
      reloaded = true;
    },
  },
  encodeURIComponent,
};

Object.defineProperty(context.document, 'cookie', {
  get() {
    return cookieValue;
  },
  set(value) {
    cookieValue = value;
  },
});

vm.runInNewContext(script, context, {
  filename: 'assets/js/admin-bar.js',
});

assert.strictEqual(typeof clickHandler, 'function');

clickHandler({
  preventDefault() {},
});

assert.ok(cookieValue.startsWith('wp_flame_force_trace=nonce%20value%2B%2F%3D'));
assert.ok(cookieValue.includes(';path=/'));
assert.ok(cookieValue.includes(';Max-Age=300'));
assert.ok(cookieValue.includes(';SameSite=Strict'));
assert.ok(cookieValue.includes(';Secure'));
assert.strictEqual(reloaded, true);
