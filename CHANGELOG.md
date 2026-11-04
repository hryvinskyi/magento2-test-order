# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-04

### Added
- The runner's proof: the `X-Test-Order-Proof` header, an HMAC-SHA256 bound to an action and a subject, fresh for 300
  seconds either side of its time. Without a secret of at least 32 characters in `env.php` nothing is recognised.
- Recognition at placement: a REST or GraphQL placement with a proof goes ahead only as a test order of a guest cart, or
  is refused and no order is created.
- `TestOrdersInterface` and `TestCartsInterface`, the question an integration asks before it acts on an order or a cart.
- `SkipObserverForTestOrders`, the plugin an integration puts on its own observer, with a pool of event readers and the
  `AssertsObserverSkipsTestOrders` test trait.
- `POST /V1/test-orders/recognition` and `POST /V1/test-orders/:cartId/abandon`.
- `RecognitionGuardInterface` and `UndoStepInterface`, both open pools, empty out of the box.
- `TestOrderFilterInterface` for code that selects orders from a collection.
- The clean-up cron `hryvinskyi_test_order_abandon_stale` and the settings under `test_order/cleanup`.
