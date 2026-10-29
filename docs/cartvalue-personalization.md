# CartValue gebruiken in Loyalty Engage-personalisaties

Vanaf WooCommerce-plugin versie **1.5.10** stelt de plugin automatisch de actuele waarde beschikbaar voor Loyalty Engage-personalisaties.

## Beschikbare gegevens

De gegevens staan in `window.lewWooCommerce`:

```javascript
window.lewWooCommerce.cartValue;      // Actuele product- of winkelwagenwaarde
window.lewWooCommerce.currency;       // Bijvoorbeeld EUR
window.lewWooCommerce.identifier;     // E-mailadres van de ingelogde klant
window.lewWooCommerce.pageType;       // product, cart of other
window.lewWooCommerce.cartValueBasis; // Beschrijving van de gebruikte prijsbasis
```

- Op een **productpagina** is `cartValue` de actuele productprijs exclusief btw.
- Bij een **variabel product** wordt `cartValue` automatisch aangepast na variantselectie.
- Op de **winkelwagenpagina** is `cartValue` de productwaarde na kortingen en exclusief btw.
- Deze prijsbasis sluit aan op de bestaande Purchase-export naar Loyalty Engage.

## Gebruiken in een plaatselijk script

Lees eerst de beginwaarde en luister daarna naar wijzigingen:

```javascript
function renderLoyaltyPersonalization(context) {
  // Vervang dit door de Loyalty Engage-triggerfunctie.
  triggerPersonalization({
    identifier: context.identifier,
    cartValue: context.cartValue,
    currency: context.currency
  });
}

if (window.lewWooCommerce) {
  renderLoyaltyPersonalization(window.lewWooCommerce);
}

window.addEventListener('lew:commerce-context-updated', function (event) {
  renderLoyaltyPersonalization(event.detail);
});
```

`getOrderAward(cartValue)` in het Loyalty Engage-template gebruikt vervolgens deze meegestuurde `cartValue`. Wanneer geen geldige waarde wordt meegestuurd, gebruikt Loyalty Engage `0`.

> `triggerPersonalization()` is een voorbeeldnaam. Gebruik hier de daadwerkelijke triggerfunctie uit de Loyalty Engage frontend-implementatie.
