# Changelog

## 0.1.0 — 2026-10-26

First release.

### Added

- Purchase events (review requests): `PurchaseEventBuilder` (one event per order, one product
  per product code, service lines excluded, `solicitation_parameters` left to the portal),
  `PurchaseEventQueue` (one row per order, never sent twice, nothing queued while disabled),
  `PurchaseEventSender` (50 per request; 201, 206, 400 one by one, 401, 429, unknown outcome
  on 5xx or no answer), commands `skeepers:purchase-events:send` (`--dry-run`, `--order`,
  `--retry-errors`, `--status`) and `skeepers:purchase-events:enqueue`.
- Triggers: `manual`, `order_fulfilled`, `shipment_shipped` (Symfony workflow).
- Safety switch `purchase_events.enabled`, `false` by default.
- Product reviews: `ProductReviewSynchronizer` and command `skeepers:product-reviews:sync`
  (`--since`, `--raw-dir`, `--dry-run`), `ReviewProductResolverInterface` with a default
  resolver (page slug, product code, variant code, composed references).
- Widgets: configured scripts or code added in `<head>`, at the end of `<body>`, or by the Twig
  functions `skeepers_widget()` / `skeepers_widgets()`.
- OAuth2 client credentials token, one per audience, kept until 30 seconds before expiry.
- Migration: queue table, `external_id` and `author_name` on `sylius_product_review` when missing.
- Unit tests.
