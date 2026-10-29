(function () {
  'use strict';

  var config = window.lewWooCommerce || {};

  function normalize(value) {
    return String(value || '').trim().toLowerCase();
  }

  function number(value) {
    var parsed = parseInt(value || '0', 10);
    return Number.isNaN(parsed) ? 0 : parsed;
  }

  function amount(value) {
    var parsed = parseFloat(value || '0');
    return Number.isNaN(parsed) ? 0 : Math.max(0, parsed);
  }

  function publishCommerceContext(source) {
    window.lewWooCommerce = config;
    window.dispatchEvent(new CustomEvent('lew:commerce-context-updated', {
      detail: Object.assign({ source: source || 'unknown' }, config)
    }));
  }

  function setProductPrice(price, variationId) {
    if (config.pageType !== 'product') return;

    var product = Object.assign({}, config.product || {});
    product.price = amount(price);
    product.variationId = number(variationId);
    config.product = product;
    config.cartValue = product.price;
    publishCommerceContext('product-variation');
  }

  function refreshCartContext() {
    if (config.pageType !== 'cart' || !config.restBase) return;

    var url = config.restBase.replace(/\/$/, '') + '/context?pageType=cart';
    fetch(url, {
      credentials: 'same-origin',
      headers: config.nonce ? { 'X-WP-Nonce': config.nonce } : {}
    }).then(function (response) {
      if (!response.ok) throw new Error('Context request failed');
      return response.json();
    }).then(function (data) {
      if (!data || !data.success || !data.context) return;
      config.cartValue = amount(data.context.cartValue);
      config.cartValueBasis = data.context.cartValueBasis || config.cartValueBasis;
      config.currency = data.context.currency || config.currency;
      config.cart = data.context.cart || config.cart;
      publishCommerceContext('cart-updated');
    }).catch(function () {
      // Keep the last valid server value when cart context cannot be refreshed.
    });
  }

  function getLevelElements() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-lew-level]'));
  }

  function hasReachedTier(requiredTier, requiredPoints, levels) {
    if (!requiredTier) return true;
    if (normalize(requiredTier) === normalize(config.currentTier)) return true;

    var currentIndex = levels.findIndex(function (level) {
      return normalize(level.dataset.lewLevel) === normalize(config.currentTier);
    });
    var requiredIndex = levels.findIndex(function (level) {
      return normalize(level.dataset.lewLevel) === normalize(requiredTier);
    });
    if (currentIndex >= 0 && requiredIndex >= 0) {
      return currentIndex >= requiredIndex;
    }

    return requiredPoints > 0 && number(config.points) >= requiredPoints;
  }

  function setText(element, text) {
    if (element.textContent !== text) element.textContent = text;
  }

  function refreshLoyaltyUi() {
    var levels = getLevelElements();
    var currentIndex = levels.findIndex(function (level) {
      return normalize(level.dataset.lewLevel) === normalize(config.currentTier);
    });

    levels.forEach(function (level, index) {
      var requiredPoints = number(level.dataset.lewRequiredPoints);
      var isCurrent = normalize(level.dataset.lewLevel) === normalize(config.currentTier);
      var isReached = isCurrent || (requiredPoints > 0 && number(config.points) >= requiredPoints) || (currentIndex >= 0 && index <= currentIndex);
      var status = level.querySelector('[data-lew-level-status]');

      level.classList.toggle('is-current', isCurrent);
      level.classList.toggle('is-reached', isReached);
      level.classList.toggle('is-locked', !isReached);
      if (status) {
        setText(status, isCurrent ? 'Huidig level' : (isReached ? 'Behaald' : (requiredPoints > 0 ? 'Vanaf ' + requiredPoints + ' punten' : 'Nog niet behaald')));
      }
    });

    document.querySelectorAll('[data-lew-reward]').forEach(function (card) {
      var button = card.querySelector('[data-lew-action]');
      if (!button) return;

      var hasCoinRequirement = card.hasAttribute('data-lew-coin-price');
      var coinPrice = number(card.dataset.lewCoinPrice);
      var requiredTier = card.dataset.lewRequiredTier || '';
      var requiredPoints = number(card.dataset.lewRequiredPoints);
      var canAfford = !hasCoinRequirement || number(config.availableCoins) >= coinPrice;
      var tierReached = hasReachedTier(requiredTier, requiredPoints, levels);
      var isAvailable = canAfford && tierReached;
      var reason = !tierReached
        ? 'Beschikbaar vanaf ' + requiredTier
        : 'Nog ' + Math.max(0, coinPrice - number(config.availableCoins)) + ' coins nodig';
      var reasonElement = card.querySelector('[data-lew-lock-reason]');

      if (!button.dataset.lewAvailableLabel) {
        button.dataset.lewAvailableLabel = button.textContent.trim();
      }
      if (!config.loggedIn) {
        card.classList.remove('is-locked');
        button.disabled = false;
        button.setAttribute('aria-disabled', 'false');
        if (reasonElement) reasonElement.hidden = true;
        return;
      }
      card.classList.toggle('is-locked', !isAvailable);
      button.disabled = !isAvailable;
      button.setAttribute('aria-disabled', isAvailable ? 'false' : 'true');

      if (!isAvailable) {
        if (!reasonElement) {
          reasonElement = document.createElement('small');
          reasonElement.setAttribute('data-lew-lock-reason', '');
          reasonElement.className = 'lew-lock-reason';
          button.insertAdjacentElement('beforebegin', reasonElement);
        }
        setText(reasonElement, reason);
        reasonElement.hidden = false;
      } else {
        if (reasonElement) reasonElement.hidden = true;
        setText(button, button.dataset.lewAvailableLabel);
      }
    });
  }

  config.refreshLoyaltyUi = refreshLoyaltyUi;
  refreshLoyaltyUi();

  var wooEventsBound = false;
  function bindWooEvents() {
    if (wooEventsBound || !window.jQuery) return;
    wooEventsBound = true;
    window.jQuery(document)
      .on('found_variation', 'form.variations_form', function (event, variation) {
        if (!variation) return;
        setProductPrice(variation.lew_award_price === undefined ? variation.display_price : variation.lew_award_price, variation.variation_id);
      })
      .on('reset_data hide_variation', 'form.variations_form', function () {
        setProductPrice(config.product ? config.product.minPrice : 0, 0);
      })
      .on('updated_wc_div added_to_cart removed_from_cart', function () {
        refreshCartContext();
      });
  }

  bindWooEvents();
  window.addEventListener('load', bindWooEvents);

  document.addEventListener('wc-blocks_added_to_cart', refreshCartContext);
  document.addEventListener('wc-blocks_removed_from_cart', refreshCartContext);

  if (document.body && window.MutationObserver) {
    var refreshScheduled = false;
    new MutationObserver(function (mutations) {
      var hasLoyaltyMarkup = mutations.some(function (mutation) {
        return Array.prototype.some.call(mutation.addedNodes, function (node) {
          if (node.nodeType !== 1) return false;
          return node.matches('[data-lew-reward], [data-lew-level], [data-lew-personalization-shop]') ||
            Boolean(node.querySelector('[data-lew-reward], [data-lew-level], [data-lew-personalization-shop]'));
        });
      });
      if (!hasLoyaltyMarkup) return;
      if (refreshScheduled) return;
      refreshScheduled = true;
      window.setTimeout(function () {
        refreshScheduled = false;
        refreshLoyaltyUi();
      }, 0);
    }).observe(document.body, { childList: true, subtree: true });
  }

  window.dispatchEvent(new CustomEvent('lew:woocommerce-ready', {
    detail: config
  }));
})();
