const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

test('personalization levels respect the current title despite high points', () => {
  const levels = ['Starter', 'Bourgondier', 'Chef'].map((name, index) => ({
    dataset: { lewLevel: name, lewRequiredPoints: String(index * 100) },
    classes: {},
    classList: { toggle(key, value) { levels[index].classes[key] = value; } },
    querySelector() { return null; }
  }));
  const document = {
    querySelectorAll(selector) { return selector === '[data-lew-level]' ? levels : []; },
    addEventListener() {}
  };
  const window = {
    lewWooCommerce: { currentTier: 'Bourgondier', points: 99999 },
    addEventListener() {}, dispatchEvent() {}
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/js/storefront-bridge.js'), 'utf8'), {
    window, document, CustomEvent: class {}
  });
  assert.equal(levels[0].classes['is-reached'], true);
  assert.equal(levels[1].classes['is-current'], true);
  assert.equal(levels[2].classes['is-locked'], true);
});
