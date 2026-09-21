# Loyalty Engage for WooCommerce

WooCommerce plugin project that mirrors the main Loyalty Engage Shopify app capabilities with a WooCommerce-native architecture.

## Included functionality

- order export to Loyalty Engage via outbox
- return export to Loyalty Engage via outbox
- customer registration sync
- loyalty rewards lookup from Loyalty Engage
- physical reward reservation and cart insertion
- WooCommerce coupon creation for claimed loyalty discount codes
- points redemption with coupon auto-apply and preview support
- loyalty tier based free shipping
- review export to Loyalty Engage via outbox
- customer loyalty metadata sync into WordPress user meta
- configurable loyalty overview block on the loyalty page
- loyalty shop personalization by purchased product tags
- retryable background processing with WooCommerce logging

## Project structure

```text
woocommerce-loyalty-engage/
├── loyalty-engage-woocommerce.php
├── includes/
│   ├── class-lew-activator.php
│   ├── class-lew-plugin.php
│   ├── class-lew-settings.php
│   ├── class-lew-loyalty-engage-client.php
│   ├── class-lew-logger.php
│   ├── class-lew-outbox.php
│   ├── class-lew-shipping.php
│   ├── class-lew-webhooks.php
│   ├── class-lew-rest-api.php
│   └── class-lew-storefront.php
└── assets/
    ├── css/loyalty-page.css
    └── js/loyalty-page.js
```

## How it works

### Orders and refunds

- WooCommerce order status `processing` and `completed` trigger purchase export
- WooCommerce refunds trigger return export
- approved WooCommerce product reviews trigger review export
- payloads are queued in custom outbox tables
- cron workers retry failed exports with exponential backoff

### Storefront rewards

- frontend page uses shortcode `[loyalty_engage_page]`
- rewards are fetched from Loyalty Engage
- rewards are matched against WooCommerce products by SKU, variation id, barcode fallback and title fallback
- physical rewards are reserved in Loyalty Engage and then added to the WooCommerce cart
- loyalty cart items are forced to zero price and carry loyalty metadata into the order
- loyalty account block can expose current tier, points, coins and next-tier data from synced customer meta

### Discount rewards

- discount reward claims call Loyalty Engage
- returned reward codes are stored locally
- a matching WooCommerce coupon is created or updated automatically
- order completion marks claimed Loyalty Engage discount codes as redeemed
- points redemption can preview discount, redeem points to a WooCommerce coupon and remove the active points coupon again

### Free shipping

- optional tier-based free shipping can be enabled in settings
- when a logged-in customer has a matching loyalty tier, all available shipping rates are set to free
- tier matching is based on synced loyalty customer meta and is case-insensitive

### Personalization

- disabled by default
- once enabled, selected WooCommerce product tags are saved on users after purchases
- reward products with matching tags are ranked first, or exclusively shown when `only_matching` is selected

## Security model

- customer-facing POST endpoints require a logged-in user and ownership of the customer resource
- Loyalty Engage callback endpoint `/customer-update` requires the configured webhook secret
- frontend authenticated requests use the WordPress REST nonce

## Settings

Admin page:

- Client ID
- Client Secret
- Webhook Secret
- Module enable toggle
- API base URL
- Logging toggle
- Order export toggle
- Purchase export statuses
- Return export toggle
- Review export toggle
- Customer sync toggle
- Max loyalty products per cart
- Minimum non-loyalty subtotal
- Minimum subtotal error messages
- Points redemption settings
- Free shipping tier settings
- Frontend loyalty meta visibility and labels
- Loyalty personalization toggle
- Personalization tags
- Personalization display mode

## REST endpoints

- `GET /wp-json/loyalty-engage/v1/products`
- `GET /wp-json/loyalty-engage/v1/loyalty-status?customer_ref=123`
- `POST /wp-json/loyalty-engage/v1/discount/{sku}/{customer_ref}`
- `POST /wp-json/loyalty-engage/v1/physical-redeem/{sku}/{customer_ref}`
- `POST /wp-json/loyalty-engage/v1/cart-remove/{sku}/{customer_ref}`
- `POST /wp-json/loyalty-engage/v1/customer-update`
- `POST /wp-json/loyalty-engage/v1/redeem-points/{customer_ref}`
- `DELETE /wp-json/loyalty-engage/v1/redeem-points/{customer_ref}`
- `GET /wp-json/loyalty-engage/v1/redeem-points/{customer_ref}/info`
- `POST /wp-json/loyalty-engage/v1/redeem-points/{customer_ref}/preview`

## Database tables

- `wp_lew_order_outbox`
- `wp_lew_refund_outbox`
- `wp_lew_review_outbox`
- `wp_lew_discount_codes`
- `wp_lew_physical_redemptions`

## Logging

Plugin logging goes through the WooCommerce logger under source:

```text
loyalty-engage-woocommerce
```

## Release checklist

1. Install plugin in a WooCommerce staging environment.
2. Configure Client ID, Client Secret and Webhook Secret.
3. Verify WP-Cron or replace it with a real server cron.
4. Test customer registration sync.
5. Test one paid order, one guest order and one refund.
6. Test one discount reward claim and validate the WooCommerce coupon.
7. Test one physical reward claim and verify zero pricing in cart and order.
8. Test points redemption preview, apply and remove flow in cart.
9. Test tier-based free shipping with qualifying and non-qualifying customers.
10. Test loyalty personalization with enabled and disabled states.
11. Test approved review export and confirm the review outbox drains correctly.

## Remaining validation before live

This project is hardened substantially, but still needs full platform validation in a real WooCommerce install before a production release on a live merchant store:

- HPOS compatibility validation on the target WooCommerce version
- real checkout and refund flow validation with the merchant theme
- final validation of the Loyalty Engage `redeem-points` response contract in the target environment
- final decision on guest checkout behavior for loyalty exports
- any merchant-specific barcode plugin compatibility
