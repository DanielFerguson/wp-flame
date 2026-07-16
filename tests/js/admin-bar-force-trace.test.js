const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const repoDir = path.resolve(__dirname, '../..');
const script = fs.readFileSync(path.join(repoDir, 'assets/js/admin-bar.js'), 'utf8');

const clickHandlers = {};
const cookieValues = [];
let reloaded = false;

const context = {
  window: {
    wpFlameAdminBar: {
      forceTraceNonce: 'nonce value+/=',
    },
  },
  document: {
    getElementById(id) {
      if (!['wp-admin-bar-wp-flame-trace', 'wp-admin-bar-wp-flame-trace-deep'].includes(id)) {
        return null;
      }

      return {
        addEventListener(eventName, handler) {
          if (eventName === 'click') {
            clickHandlers[id] = handler;
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
    return cookieValues[cookieValues.length - 1] || '';
  },
  set(value) {
    cookieValues.push(value);
  },
});

vm.runInNewContext(script, context, {
  filename: 'assets/js/admin-bar.js',
});

assert.strictEqual(typeof clickHandlers['wp-admin-bar-wp-flame-trace'], 'function');
assert.strictEqual(typeof clickHandlers['wp-admin-bar-wp-flame-trace-deep'], 'function');

clickHandlers['wp-admin-bar-wp-flame-trace']({
  preventDefault() {},
});

assert.ok(cookieValues[0].startsWith('wp_flame_force_trace=nonce%20value%2B%2F%3D'));
assert.ok(cookieValues[1].startsWith('wp_flame_force_mode=standard'));
assert.ok(cookieValues[0].includes(';path=/'));
assert.ok(cookieValues[0].includes(';Max-Age=300'));
assert.ok(cookieValues[0].includes(';SameSite=Strict'));
assert.ok(cookieValues[0].includes(';Secure'));
assert.strictEqual(reloaded, true);

clickHandlers['wp-admin-bar-wp-flame-trace-deep']({
  preventDefault() {},
});
assert.ok(cookieValues[3].startsWith('wp_flame_force_mode=deep'));
