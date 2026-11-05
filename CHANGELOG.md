# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-11-05

### Added
- `TestCartPlacementInterface`: a checkout that does not place through `CartManagementInterface`, or that creates the
  order later than the request carrying the proof, wraps its placement in it and honours a proof the same way, naming
  the payment method when it knows it. The module's own plugin is now one caller of it.
- Signed-in test customers: `test_order/customers` in `env.php` names the customers whose carts may become test orders,
  by the e-mail of their account. `TestCustomersInterface` asks the list. A proof on any other customer's cart stays
  refused.
- A paid test order ends: abandoning it refunds it with an offline credit memo, every item back to stock, and the order
  ends `closed`. No transaction is requested and no e-mail is sent. Two abandons that overlap make one credit memo. A
  refund that fails on an order no part of which was cancelled leaves it not undone, so the clean-up job tries it again;
  on an order partly cancelled the failure reaches the caller and nothing comes back to it.
  `PaidTestOrderRefundInterface` is the port; it answers the order as it stands, so an abandon that lost such a race
  still answers `closed`.
- `CartGuardInterface`: a guard that sees the cart and the method it will be paid with as far as known, the one the
  placement names or else the one saved on the cart, so a shop with one live provider beside a test-mode one can refuse
  the live method. An open pool, empty out of the box, asked behind a valid proof and the store's guards.

### Changed
- `abandon` can answer `closed`, which counts as ended. A runner older than `@hryvinskyi/magento2-e2e` 1.2.0 reports
  it as still open.
- An order already `closed` is ended: it gets no cancellation, no refund and no warning, and it is never deleted,
  whatever `delete_cancelled` says.
- `live_payments` now has a reader in the module: a declared shop does not refund a paid test order, and leaves it open
  with a warning for a person.
- The refusal of a cart that may not become a test order now reads: "A test order can only be placed from a guest cart
  or a test customer's cart, so the order was not placed."
- `RequestProofInterface` is marked `@api`, as a checkout's bridge needs `isSent()`.
- The proof is judged before the cart is looked at. Without a valid one a missing cart, a cart with no masked id, a
  guest's cart and a customer's cart all get the proof's refusal, so `TestCartRecognitionInterface` no longer answers
  `NoSuchEntityException` for a cart that does not exist, and `TestCartPlacementInterface` only for a masked id that
  resolves to nothing.

### Fixed
- `AssertsObserverSkipsTestOrders` fails for a class that no longer exists. The plugin list keeps an entry for it, so a
  vendor's rename left the assertion green.

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
