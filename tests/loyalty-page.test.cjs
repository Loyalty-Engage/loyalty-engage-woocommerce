const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets/js/loyalty-page.js'), 'utf8');

test('shows configured future levels and every catalog reward', async () => {
  const children = { levels: [], rewards: [] };
  const elements = {
    'lew-loyalty-page': { dataset: { apiBase: '/api', customerId: '1', coins: '10', points: '2000', currentTier: 'Brons' } },
    'lew-levels-section': { hidden: true },
    'lew-levels': { innerHTML: '', appendChild(node) { children.levels.push(node); } },
    'lew-rewards-grid': { innerHTML: '', appendChild(node) { children.rewards.push(node); } },
    'lew-message': { textContent: '', className: '' }
  };
  const document = {
    getElementById(id) { return elements[id] || null; },
    createElement() { return { className: '', innerHTML: '', querySelector() { return { addEventListener() {} }; } }; }
  };
  const window = {
    lewRestNonce: 'nonce',
    lewLoyaltyLevels: [
      { name: 'Brons', requiredPoints: 0 },
      { name: 'Zilver', requiredPoints: 500 },
      { name: 'Goud', requiredPoints: 1500 }
    ]
  };
  const fetch = () => Promise.resolve({
    ok: true,
    status: 200,
    json: () => Promise.resolve({ matchedProducts: [
      { loyalty: { title: 'Later cadeau', product_type: 'physical', coinPrice: 50, requiredLoyaltyTierName: 'Goud' }, storefront: { claimable: false } },
      { loyalty: { title: 'Verrassing', product_type: 'future_type', coinPrice: 100 } },
      { loyalty: { title: 'Cadeaubon', product_type: 'discount_code', coinPrice: 50, sku: 'GIFT-50' } }
    ] })
  });

  vm.runInNewContext(source, { window, document, fetch, setTimeout });
  await new Promise(setImmediate);

  assert.equal(elements['lew-levels-section'].hidden, false);
  assert.deepEqual(children.levels.map((level) => level.innerHTML.match(/<strong>(.*?)<\/strong>/)[1]), ['Brons', 'Zilver', 'Goud']);
  assert.match(children.levels[1].className, /is-locked/);
  assert.equal(children.rewards.length, 3);
  assert.match(children.rewards[0].innerHTML, /Later cadeau/);
  assert.match(children.rewards[0].innerHTML, /disabled/);
  assert.match(children.rewards[1].innerHTML, /Verrassing/);
  assert.match(children.rewards[1].innerHTML, /Binnenkort beschikbaar/);
  assert.match(children.rewards[2].innerHTML, /Nog 40 coins nodig/);
  assert.match(children.rewards[2].innerHTML, /disabled/);
});
