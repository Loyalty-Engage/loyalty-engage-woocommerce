(function () {
  'use strict';

  var el = document.getElementById('lew-loyalty-page');
  if (!el) return;

  var apiBase = el.dataset.apiBase || '';
  var customerId = el.dataset.customerId || '';
  var customerEmail = el.dataset.customerEmail || '';
  var availableCoins = parseInt(el.dataset.coins || '0', 10) || 0;
  var customerPoints = parseInt(el.dataset.points || '0', 10) || 0;
  var currentTier = el.dataset.currentTier || '';
  var nextTier = el.dataset.nextTier || '';
  var pointsToNextTier = parseInt(el.dataset.pointsToNextTier || '0', 10) || 0;
  var nonce = window.lewRestNonce || '';
  var messageEl = document.getElementById('lew-message');
  var pointsInput = document.getElementById('lew-points-input');
  var pointsPreview = document.getElementById('lew-points-preview');
  var pointsRedeemButton = document.getElementById('lew-points-redeem-button');
  var pointsRemoveButton = document.getElementById('lew-points-remove-button');

  function showMessage(message, success) {
    if (!messageEl) return;
    messageEl.textContent = message || '';
    messageEl.className = 'lew-message' + (message ? ' is-visible' : '') + (success ? ' is-success' : ' is-error');
  }

  function request(path, options) {
    var merged = Object.assign({
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce
      }
    }, options || {});

    merged.headers = Object.assign({
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce
    }, (options && options.headers) || {});

    return fetch(apiBase + path, merged).then(function (res) {
      return res.json().then(function (data) {
        return { ok: res.ok, status: res.status, data: data };
      }).catch(function () {
        return { ok: res.ok, status: res.status, data: {} };
      });
    });
  }

  function formatMoney(amount) {
    var numeric = Number(amount || 0);
    return '€' + numeric.toFixed(2).replace('.', ',');
  }

  function buildVariantSelect(reward) {
    var variants = reward.shopify && reward.shopify.variants ? reward.shopify.variants : [];
    if (!reward.shopify || !reward.shopify.hasMultipleVariants || variants.length <= 1) return '';

    return '<select class="lew-card__select">' +
      '<option value="">Kies een optie...</option>' +
      variants.map(function (variant) {
        var disabled = variant.available ? '' : ' disabled';
        var label = variant.title || 'Variant';
        return '<option value="' + String(variant.id || '') + '"' + disabled + '>' + label + (variant.available ? '' : ' (uitverkocht)') + '</option>';
      }).join('') +
      '</select>';
  }

  function getDefaultVariantId(reward) {
    if (reward.shopify && reward.shopify.variantId) {
      return String(reward.shopify.variantId);
    }

    var variants = reward.shopify && reward.shopify.variants ? reward.shopify.variants : [];
    if (variants.length > 0 && variants[0].id) {
      return String(variants[0].id);
    }

    return '';
  }

  function normalizeTier(value) {
    return String(value || '').trim().toLowerCase();
  }

  function collectLevels(rewards) {
    var levelsByName = {};

    function addLevel(name, requiredPoints) {
      var cleanName = String(name || '').trim();
      if (!cleanName) return;

      var key = normalizeTier(cleanName);
      var points = parseInt(requiredPoints || '0', 10) || 0;
      if (!levelsByName[key]) {
        levelsByName[key] = { name: cleanName, requiredPoints: points };
      } else if (points > 0 && (levelsByName[key].requiredPoints === 0 || points < levelsByName[key].requiredPoints)) {
        levelsByName[key].requiredPoints = points;
      }
    }

    rewards.forEach(function (reward) {
      var loyalty = reward.loyalty || {};
      addLevel(loyalty.requiredTier, loyalty.requiredPoints);
    });

    addLevel(currentTier, customerPoints);
    addLevel(nextTier, pointsToNextTier > 0 ? customerPoints + pointsToNextTier : 0);

    var levels = Object.keys(levelsByName).map(function (key) {
      return levelsByName[key];
    });

    levels.sort(function (left, right) {
      if (left.requiredPoints === right.requiredPoints) {
        if (normalizeTier(left.name) === normalizeTier(currentTier)) return -1;
        if (normalizeTier(right.name) === normalizeTier(currentTier)) return 1;
        return left.name.localeCompare(right.name);
      }
      if (left.requiredPoints === 0) return -1;
      if (right.requiredPoints === 0) return 1;
      return left.requiredPoints - right.requiredPoints;
    });

    return levels;
  }

  function hasReachedTier(requiredTier, requiredPoints, levels) {
    if (!requiredTier) return true;
    if (normalizeTier(requiredTier) === normalizeTier(currentTier)) return true;

    var currentIndex = levels.findIndex(function (level) {
      return normalizeTier(level.name) === normalizeTier(currentTier);
    });
    var requiredIndex = levels.findIndex(function (level) {
      return normalizeTier(level.name) === normalizeTier(requiredTier);
    });

    if (currentIndex >= 0 && requiredIndex >= 0) {
      return currentIndex >= requiredIndex;
    }

    return requiredPoints > 0 && customerPoints >= requiredPoints;
  }

  function renderLevels(levels) {
    var section = document.getElementById('lew-levels-section');
    var container = document.getElementById('lew-levels');
    if (!section || !container || levels.length === 0) return;

    var currentIndex = levels.findIndex(function (level) {
      return normalizeTier(level.name) === normalizeTier(currentTier);
    });

    container.innerHTML = '';
    levels.forEach(function (level, index) {
      var isCurrent = normalizeTier(level.name) === normalizeTier(currentTier);
      var isReached = isCurrent || (level.requiredPoints > 0 && customerPoints >= level.requiredPoints) || (currentIndex >= 0 && index <= currentIndex);
      var badge = document.createElement('div');
      badge.className = 'lew-level' + (isReached ? ' is-reached' : ' is-locked') + (isCurrent ? ' is-current' : '');
      badge.innerHTML =
        '<span class="lew-level__icon" aria-hidden="true">' + (isReached ? '&#10003;' : '&#128274;') + '</span>' +
        '<span class="lew-level__content"><strong>' + level.name + '</strong>' +
        '<small>' + (isCurrent ? 'Huidig level' : (isReached ? 'Behaald' : (level.requiredPoints > 0 ? 'Vanaf ' + level.requiredPoints + ' punten' : 'Nog niet behaald'))) + '</small></span>';
      container.appendChild(badge);
    });

    section.hidden = false;
  }

  function renderCard(container, reward, type, levels) {
    var title = (reward.shopify && reward.shopify.title) || (reward.loyalty && reward.loyalty.title) || 'Reward';
    var coins = parseInt((reward.loyalty && reward.loyalty.coinPrice) || 0, 10) || 0;
    var image = (reward.loyalty && reward.loyalty.imageUrl) || (reward.shopify && reward.shopify.image) || '';
    var sku = (reward.loyalty && reward.loyalty.sku) || '';
    var requiredTier = (reward.loyalty && reward.loyalty.requiredTier) || '';
    var requiredPoints = parseInt((reward.loyalty && reward.loyalty.requiredPoints) || 0, 10) || 0;
    var canAfford = availableCoins >= coins;
    var tierReached = hasReachedTier(requiredTier, requiredPoints, levels);
    var isAvailable = canAfford && tierReached;
    var buttonText = type === 'discount' ? 'Cadeaubon claimen' : 'Cadeau kiezen';
    var lockedText = !tierReached
      ? 'Beschikbaar vanaf ' + requiredTier
      : 'Nog ' + Math.max(0, coins - availableCoins) + ' coins nodig';

    var card = document.createElement('div');
    card.className = 'lew-card' + (isAvailable ? '' : ' is-locked');
    card.innerHTML =
      (image ? '<img src="' + image + '" alt="' + title + '">' : '') +
      '<span class="lew-card__type">' + (type === 'discount' ? 'Cadeaubon' : 'Fysiek cadeau') + '</span>' +
      '<h3>' + title + '</h3>' +
      '<p class="lew-card__meta">' + coins + ' coins' + (requiredTier ? ' • ' + requiredTier : '') + '</p>' +
      buildVariantSelect(reward) +
      '<button ' + (isAvailable ? '' : 'disabled') + '>' + (isAvailable ? buttonText : lockedText) + '</button>';

    var btn = card.querySelector('button');
    btn.addEventListener('click', function () {
      btn.disabled = true;
      btn.textContent = 'Verwerken...';
      showMessage('', true);

      var path = '';
      var body = {};
      if (type === 'discount') {
        path = '/discount/' + encodeURIComponent(sku) + '/' + encodeURIComponent(customerId || customerEmail);
      } else {
        var select = card.querySelector('.lew-card__select');
        var variantId = select ? select.value : getDefaultVariantId(reward);
        if (!variantId) {
          btn.disabled = false;
          btn.textContent = buttonText;
          showMessage('Kies eerst een variant.', false);
          return;
        }
        path = '/physical-redeem/' + encodeURIComponent(sku) + '/' + encodeURIComponent(customerId || customerEmail);
        body.variantId = variantId;
      }

      request(path, {
        method: 'POST',
        body: JSON.stringify(body)
      }).then(function (result) {
        if (!result.ok || !result.data || result.data.success === false) {
          btn.disabled = false;
          btn.textContent = buttonText;
          showMessage((result.data && result.data.message) || 'Er ging iets mis.', false);
          return;
        }

        if (type === 'discount' && result.data.deliveryMode === 'email_gift_card') {
          btn.textContent = 'Per e-mail verzonden';
          showMessage(result.data.message || 'Je cadeaukaart wordt per e-mail verzonden.', true);
          return;
        }

        if (type === 'discount' && result.data.discountCode) {
          btn.textContent = result.data.discountCode;
          showMessage('Kortingscode opgehaald en opgeslagen.', true);
          return;
        }

        if (type === 'physical') {
          btn.textContent = 'Toegevoegd';
          showMessage('Reward toegevoegd aan de winkelmand. Je wordt doorgestuurd.', true);
          setTimeout(function () {
            window.location.href = result.data.checkoutUrl || result.data.cartUrl || '/cart';
          }, 900);
          return;
        }

        btn.textContent = 'Gelukt';
        showMessage('Actie voltooid.', true);
      }).catch(function () {
        btn.disabled = false;
        btn.textContent = buttonText;
        showMessage('Netwerkfout bij communiceren met Loyalty Engage.', false);
      });
    });

    container.appendChild(card);
  }

  function previewPointsRedemption() {
    if (!pointsInput || !pointsPreview) return;

    var points = parseInt(pointsInput.value || '0', 10) || 0;
    if (points <= 0) {
      pointsPreview.textContent = '-';
      return;
    }

    request('/redeem-points/' + encodeURIComponent(customerId || customerEmail) + '/preview', {
      method: 'POST',
      body: JSON.stringify({ points: points })
    }).then(function (result) {
      if (!result.ok || !result.data || result.data.success === false) {
        pointsPreview.textContent = '-';
        return;
      }

      pointsPreview.textContent = formatMoney(result.data.discountAmount || 0);
    }).catch(function () {
      pointsPreview.textContent = '-';
    });
  }

  if (pointsInput) {
    pointsInput.addEventListener('input', previewPointsRedemption);
    previewPointsRedemption();
  }

  if (pointsRedeemButton) {
    pointsRedeemButton.addEventListener('click', function () {
      var points = parseInt((pointsInput && pointsInput.value) || '0', 10) || 0;
      pointsRedeemButton.disabled = true;
      pointsRedeemButton.textContent = 'Verwerken...';

      request('/redeem-points/' + encodeURIComponent(customerId || customerEmail), {
        method: 'POST',
        body: JSON.stringify({ points: points })
      }).then(function (result) {
        if (!result.ok || !result.data || result.data.success === false) {
          pointsRedeemButton.disabled = false;
          pointsRedeemButton.textContent = 'Punten inwisselen';
          showMessage((result.data && result.data.message) || 'Punten konden niet worden ingewisseld.', false);
          return;
        }

        showMessage((result.data && result.data.message) || 'Punten succesvol ingewisseld.', true);
        window.location.href = '/cart';
      }).catch(function () {
        pointsRedeemButton.disabled = false;
        pointsRedeemButton.textContent = 'Punten inwisselen';
        showMessage('Netwerkfout bij punten inwisselen.', false);
      });
    });
  }

  if (pointsRemoveButton) {
    pointsRemoveButton.addEventListener('click', function () {
      pointsRemoveButton.disabled = true;
      pointsRemoveButton.textContent = 'Verwerken...';

      request('/redeem-points/' + encodeURIComponent(customerId || customerEmail), {
        method: 'DELETE'
      }).then(function (result) {
        if (!result.ok || !result.data || result.data.success === false) {
          pointsRemoveButton.disabled = false;
          pointsRemoveButton.textContent = 'Puntenkorting verwijderen';
          showMessage((result.data && result.data.message) || 'Puntenkorting kon niet worden verwijderd.', false);
          return;
        }

        showMessage((result.data && result.data.message) || 'Puntenkorting verwijderd.', true);
        window.location.href = '/cart';
      }).catch(function () {
        pointsRemoveButton.disabled = false;
        pointsRemoveButton.textContent = 'Puntenkorting verwijderen';
        showMessage('Netwerkfout bij verwijderen van puntenkorting.', false);
      });
    });
  }

  request('/products?customerId=' + encodeURIComponent(customerId) + '&customerEmail=' + encodeURIComponent(customerEmail), {
    method: 'GET',
    headers: { 'X-WP-Nonce': nonce }
  }).then(function (result) {
    var rewardsGrid = document.getElementById('lew-rewards-grid');
    if (!rewardsGrid) return;

    rewardsGrid.innerHTML = '';

    if (!result.ok || !result.data || !Array.isArray(result.data.matchedProducts)) {
      showMessage((result.data && result.data.message) || 'Kon loyalty producten niet laden.', false);
      rewardsGrid.innerHTML = '<p>Geen rewards beschikbaar.</p>';
      return;
    }

    var levels = collectLevels(result.data.matchedProducts);
    var rewardCount = 0;
    renderLevels(levels);

    result.data.matchedProducts.forEach(function (reward) {
      if (reward.loyalty && reward.loyalty.product_type === 'discount_code') {
        renderCard(rewardsGrid, reward, 'discount', levels);
        rewardCount += 1;
      } else if (reward.loyalty && reward.loyalty.product_type === 'physical') {
        renderCard(rewardsGrid, reward, 'physical', levels);
        rewardCount += 1;
      }
    });

    if (rewardCount === 0) {
      rewardsGrid.innerHTML = '<p>Geen rewards beschikbaar.</p>';
    }
  }).catch(function () {
    showMessage('Kon loyalty producten niet laden.', false);
  });
})();
