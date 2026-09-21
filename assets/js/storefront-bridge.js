(function () {
  'use strict';

  window.dispatchEvent(new CustomEvent('lew:woocommerce-ready', {
    detail: window.lewWooCommerce || {}
  }));
})();
