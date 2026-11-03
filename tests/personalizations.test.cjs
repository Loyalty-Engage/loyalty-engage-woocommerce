const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets/js/personalizations.js'), 'utf8');

function createRuntime(rule, context) {
  const events = {};
  const slot = {
    id: 'lew-personalization-' + rule.key,
    dataset: { lewPersonalization: rule.key },
    clears: 0,
    replaceChildren() { this.clears += 1; }
  };
  const window = {
    lewPersonalizationRules: [rule],
    lewWooCommerce: context,
    _lecloud: { personalization: [] },
    addEventListener(name, callback) { events[name] = callback; }
  };
  const document = {
    readyState: 'complete',
    querySelectorAll() { return [slot]; }
  };
  vm.runInNewContext(source, { window, document });
  return { window, events, slot };
}

test('sends configured personalization with customer and cart value once per value', () => {
  const runtime = createRuntime({
    key: 'pdp-punten', public_id: 'public-123', personalized: 'yes', use_cart_value: 'yes'
  }, { identifier: 'customer@example.com', cartValue: 25 });

  assert.deepEqual(JSON.parse(JSON.stringify(runtime.window._lecloud.personalization[0])), {
    id: 'public-123',
    target: '#lew-personalization-pdp-punten',
    identifier: 'customer@example.com',
    cartValue: 25
  });

  runtime.events['lew:commerce-context-updated']({ detail: { identifier: 'customer@example.com', cartValue: 25 } });
  assert.equal(runtime.window._lecloud.personalization.length, 1);

  runtime.events['lew:commerce-context-updated']({ detail: { identifier: 'customer@example.com', cartValue: 42 } });
  assert.equal(runtime.window._lecloud.personalization.length, 2);
  assert.equal(runtime.window._lecloud.personalization[1].cartValue, 42);
  assert.equal(runtime.slot.clears, 1);
});

test('does not send customer data or cart value unless enabled', () => {
  const runtime = createRuntime({
    key: 'banner', public_id: 'public-456', personalized: 'no', use_cart_value: 'no'
  }, { identifier: 'customer@example.com', cartValue: 30 });

  assert.deepEqual(JSON.parse(JSON.stringify(runtime.window._lecloud.personalization[0])), {
    id: 'public-456', target: '#lew-personalization-banner'
  });
});
