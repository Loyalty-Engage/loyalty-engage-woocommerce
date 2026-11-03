(function () {
  'use strict';

  var rules = window.lewPersonalizationRules || [];
  var lastValues = {};

  function trigger(rule, slot, context) {
    if (!slot.id || !window._lecloud || !window._lecloud.personalization || typeof window._lecloud.personalization.push !== 'function') {
      return;
    }

    var event = {
      id: rule.public_id,
      target: '#' + slot.id
    };
    if (rule.personalized === 'yes' && context.identifier) {
      event.identifier = context.identifier;
    }
    if (rule.use_cart_value === 'yes' && Number.isFinite(Number(context.cartValue))) {
      event.cartValue = Number(context.cartValue);
    }

    var fingerprint = JSON.stringify(event);
    if (lastValues[slot.id] === fingerprint) return;
    if (lastValues[slot.id]) slot.replaceChildren();
    lastValues[slot.id] = fingerprint;
    window._lecloud.personalization.push(event);
  }

  function render(context) {
    rules.forEach(function (rule) {
      document.querySelectorAll('[data-lew-personalization]').forEach(function (slot) {
        if (slot.dataset.lewPersonalization === rule.key) {
          trigger(rule, slot, context);
        }
      });
    });
  }

  function start() {
    render(window.lewWooCommerce || {});
    window.addEventListener('lew:commerce-context-updated', function (event) {
      render(event.detail || window.lewWooCommerce || {});
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();
