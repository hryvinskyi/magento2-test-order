# Test Orders for Adobe Commerce (Magento 2)

Lets an end-to-end test runner place a real order that your shop knows is a test, and end it again. The runner proves itself with a signed header, the shop records the cart, and any integration can ask whether an order is a test before it acts on it.

> **The module recognises test orders. It does not stop anything by itself.** Only integrations that opt out ignore a test order. A shop that installs this module and opts nothing out sends its test orders to everything it integrates with.
>
> That holds for plain Magento too. A test order is placed like any other, so its payment method runs, stock moves and the order e-mail goes out unless something opted out. A payment method that charges at placement charges, and cancelling the order afterwards does not undo that by itself.

## Requirements

- PHP 8.3 or higher
- Verified on Magento 2.4.9-beta1 with PHP 8.4 and on 2.4.8-p5 with PHP 8.3. Other versions have not been run.

## Installation

```bash
composer require hryvinskyi/magento2-test-order
bin/magento module:enable Hryvinskyi_TestOrder
bin/magento setup:upgrade
bin/magento setup:di:compile
```

`setup:upgrade` creates two tables: `hryvinskyi_test_order` and `hryvinskyi_test_order_undo`.

## Configuration in `env.php`

Every key lives in `app/etc/env.php` and never in the database, so a copied database carries none of them.

```php
'test_order' => [
    'proof_secret' => '<the secret>',
    'customers' => ['buyer@example.test'],
    'live_payments' => true,
],
```

**`proof_secret`** turns recognition on. It needs at least 32 characters; `openssl rand -hex 32` makes one. Without a usable secret the shop recognises nothing, and a value that is set but too short is logged as a warning.

- The runner holds the same value in its own secret store.
- Use a different secret per environment, so that a staging leak proves nothing on the live shop.
- A shop on opcache without revalidation keeps the old value until PHP-FPM is reloaded.

**`customers`** names the customers whose carts may become test orders, by the e-mail of their account. See [A shop open only to signed-in customers](#a-shop-open-only-to-signed-in-customers).

**`live_payments`** counts only as the boolean `true`. It declares that this installation takes payments on live keys. The module reads it for one thing: a paid test order is not refunded offline, see [How a paid test order ends](#how-a-paid-test-order-ends). Otherwise it is a declaration for a guard to ask through `LivePaymentsDeclarationInterface`, and leaving it unset protects nothing on its own. See [Recognition guards](#recognition-guards).

## The contract with the runner

The runner's half is the npm package [`@hryvinskyi/magento2-e2e`](https://github.com/hryvinskyi/magento2-e2e). Anything that follows this contract can act as the runner.

**Who holds the secret can bypass every opted-out integration and cancel test orders.** Treat it like a payment key. A captured proof stays valid for its action and cart for five minutes either side of its time, so keep it out of logs and shared traces too.

### The proof

```
X-Test-Order-Proof: <unix-seconds>.<signature>
```

The signature is the lowercase hex HMAC-SHA256, made with the secret, of `<action>|<subject>|<unix-seconds>`.

| Action | Subject | Used by |
|--------|---------|---------|
| `place` | the cart's masked id | placing the order |
| `abandon` | the cart's masked id | the abandon route |
| `recognise` | the word `recognition` | the recognition route |

- The time is ten digits without a leading zero. Anything but that shape, a dot and 64 lowercase hex characters is malformed.
- A proof is fresh for 300 seconds either side of the server's time.
- The action is signed, so a proof made for one action is never accepted for another.

### Placing a test order

Send the header with a REST or GraphQL placement. No other area reads it.

- **No header:** an ordinary order. Nothing in this module runs.
- **A header the shop can honour:** the cart is recorded before the order exists, so opted-out integrations ignore the order from the moment it is placed.
- **A header it cannot honour:** no order. REST answers `HTTP 400`, GraphQL a GraphQL error. That covers a malformed, empty, wrong, stale or wrong-action proof, a shop with no secret, the cart of a customer the shop does not name, a closed cart, an open cart that already has an ordinary order, and a guard's refusal. On `payment-information` the core refuses a closed cart first, with `404`. Only a guest cart or a named customer's cart can become a test order, and a real order never does: a guest cart keeps its masked id after its order is placed.

The proof is judged before the cart. Without a valid one the refusal is the same for every cart id, so a caller learns nothing, not even whether the cart exists or whose it is.

A placement that fails forgets only a record it made itself, and only while no order of the cart exists: an order it saved before failing stays a test order. A record that was already there when it started is another placement's, perhaps one still running, and stays. A test cart with an order may place again: a placement can save an order and then fail, and the retry places another.

### A checkout that places elsewhere

The module reads the proof around `CartManagementInterface::placeOrder()`. A checkout that places through a service of its own, or that creates the order later than the request carrying the proof, wraps its placement in `TestCartPlacementInterface`:

```php
class HonourTestOrderProof
{
    public function __construct(private readonly TestCartPlacementInterface $placement)
    {
    }

    public function aroundPlace(CheckoutPlacementInterface $subject, callable $proceed, string $cartId): mixed
    {
        return $this->placement->place($cartId, static fn (): mixed => $proceed($cartId));
    }
}
```

`place()` takes the entity id or the masked id, and returns what the placement returned. Its third argument names the payment method when the checkout knows it, for the [cart guards](#cart-guards). It follows the rules above: with no proof it calls the placement and looks nothing up; with a proof it refuses, or records the cart and calls the placement; a placement that fails forgets only a record this call made. Put the plugin on the checkout's interface, outside anything that reports its failures, or a refusal reaches them as an error of the checkout.

The record is made where the proof is. An order placed later from that cart, by a return from a payment page or a webhook, needs no proof: it becomes a test order because its cart is recorded. A record whose cart never gets an order goes a day later. A placement wrapped in this call that reaches `placeOrder()` inside it stays one placement: the outer call owns the record.

### A shop open only to signed-in customers

A guest cart needs nothing more. A shop with no guest checkout names its test customers in `env.php`:

```php
'test_order' => [
    'proof_secret' => '<the secret>',
    'customers' => ['buyer@example.test'],
],
```

A proof then works on that customer's cart, given a masked id. The comparison is on the e-mail of the customer's account, trimmed and ignoring case, never on the address typed at checkout. It does not sign anyone in, and it allows nothing else: a proof on any other customer's cart is refused, a value that is not a list of non-empty strings counts as no list, and a copied database carries no such right.

### Routes

Both routes are anonymous, because the proof is the authorisation, and both are POST, so no cache can answer them. The proof is checked first: a caller without one learns nothing, not even whether the cart exists. A web server that replaces error pages (nginx `fastcgi_intercept_errors`, for one) turns the body of a `403` or `404` into its own page, so a client reads the status.

`POST /V1/test-orders/recognition` asks whether the shop recognises the runner. Ask before placing anything.

| Answer | Meaning |
|--------|---------|
| `200` `true` | Recognised |
| `401` | No valid proof, or the shop has no secret |
| `403` | A guard refuses the store; the message is its reason |

`POST /V1/test-orders/:cartId/abandon` ends every test order of the cart, whose masked id is `:cartId`. Repeating it is safe.

| Answer | Meaning |
|--------|---------|
| `200` `{"increment_id", "state", "deleted"}` | An order left open when the cart has one, otherwise its newest order. `state` is `canceled` once cancelled and `closed` once refunded; any other state means the order could be neither: it was undone, kept and logged. `deleted` is true only when every order of the cart was deleted |
| `400`, `500` | Cancelling, refunding or deleting an order failed: `400` with the reason, `500` when Magento masks it. Every other order of the cart was still attempted |
| `401` | No valid proof |
| `404` | The cart is unknown, was never recognised, or no order was placed from it |

### How a paid test order ends

A paid order is invoiced, and an invoiced order can no longer be cancelled. Abandoning it refunds it with an offline credit memo for the whole order, every item back to stock, and the order ends `closed`. Nothing leaves the shop: no transaction is requested, no e-mail is sent, and the order's payment method is asked to refund only when it declares itself offline. A closed order counts as ended, a repeated abandon answers the same, and it is never deleted, whatever `delete_cancelled` says: it holds an invoice and a credit memo.

The refund comes before the undo. A refund that fails on an order no part of which was cancelled leaves it open and not undone, so the clean-up job tries it again. An order partly cancelled was undone by that cancellation, so the failure reaches the caller and nothing comes back to it.

The refund holds the order and decides on it as it stands, so two abandons that overlap make one credit memo.

An offline credit memo says money went back without moving any, which is true only of test money. So the order is left open, and logged as a warning for a person to end, when:

- `live_payments` is declared: on live keys the credit memo would hide a real payment;
- it cannot take a credit memo, because it is on hold, in payment review or not paid.

Such an order is undone and kept.

**This is safe only while a guard covers each live provider the shop has: store-wide, or by cart where the method is known in the request that carries the proof.** The refusal rests on a person declaring `live_payments`, and the module cannot know a shop's providers. A test order paid on live keys on a shop that declares nothing is refunded on paper and the money stays with the provider. See [Recognition guards](#recognition-guards) and [Cart guards](#cart-guards).

## Opting an integration out

In the integration's own module:

1. Put the plugin on the observer class, in the `di.xml` of the area whose `events.xml` declares the observer, or the global one:

   ```xml
   <type name="Vendor\Module\Observer\SendOrderToWarehouse">
       <plugin name="skip_test_orders" type="Hryvinskyi\TestOrder\Plugin\SkipObserverForTestOrders"/>
   </type>
   ```

2. Add `Hryvinskyi_TestOrder` to the `<sequence>` in `etc/module.xml`, and `hryvinskyi/magento2-test-order` to the `require` in `composer.json`.
3. Prove it in an integration test with the trait `Hryvinskyi\TestOrder\Test\Integration\AssertsObserverSkipsTestOrders`:

   ```php
   self::assertObserverSkipsTestOrders(SendOrderToWarehouse::class);
   ```

Each observer class needs its own plugin, so a new observer in an opted-out module is not opted out by itself. The assertion fails for a class that no longer exists, so a vendor's rename shows up when the test runs after an update.

Code that is not an observer asks directly: `TestOrdersInterface::isTestOrder()` or `isTestOrderId()`, or `TestCartsInterface::isTestCart()` before the order exists. `isTestOrder()` reads only the order's cart id, so it answers while the order is being placed and has no id yet.

### What an event carries

The plugin finds the orders of an event through a pool of readers: `order`, `order_ids`, `item`, `payment` and `invoice` (while an order is being placed, they answer through the order they hold), `creditmemo`, `shipment`, `address`, `data_object` (an address), `status_history` and `quote`.

An event no reader understands still runs the observer, and a warning names the observer and the event. Add a reader for it, an `OrderEventReaderInterface` that reports what the event already holds without loading anything:

```xml
<type name="Hryvinskyi\TestOrder\Plugin\SkipObserverForTestOrders">
    <arguments>
        <argument name="readers" xsi:type="array">
            <item name="return_request" xsi:type="object">Vendor\Module\Model\ReturnRequestReader</item>
        </argument>
    </arguments>
</type>
```

## Extension points

### Recognition guards

`RecognitionGuardInterface` lets a module veto test orders in a store. The pool is **empty out of the box**, so a store on live payment keys is recognised like any other until a guard refuses it.

You want one if your runner ever targets a local copy of a production database. That copy keeps the live payment keys, and its order numbers collide with the live shop's, so ending a local test order could act on a real customer's payment. A typical guard sees live keys and refuses unless `LivePaymentsDeclarationInterface` says this installation declared them.

```xml
<type name="Hryvinskyi\TestOrder\Model\RecognitionGuardPool">
    <arguments>
        <argument name="guards" xsi:type="array">
            <item name="live_payment_keys" xsi:type="object">Vendor\Module\Model\LivePaymentKeysGuard</item>
        </argument>
    </arguments>
</type>
```

The recognition route asks the pool for the store it serves, a placement for the cart's store, and any one refusal refuses.

### Cart guards

A recognition guard answers for a whole store, so it cannot tell a live payment method from a test-mode one beside it. `CartGuardInterface` sees the cart, and that pool is **empty out of the box** too.

```xml
<type name="Hryvinskyi\TestOrder\Model\CartGuardPool">
    <arguments>
        <argument name="guards" xsi:type="array">
            <item name="test_mode_methods_only" xsi:type="object">Vendor\Module\Model\TestModeMethodsGuard</item>
        </argument>
    </arguments>
</type>
```

The pool is asked after the store's guards, so only behind a valid proof. Any one refusal refuses the placement with its reason, and nothing is recorded.

The guard is handed the cart as saved before the placement started, and the method it will be paid with as far as known here: the one the placement names, otherwise the one saved on the cart, and null when neither names one. A checkout that chooses the method later names none yet, so null is an ordinary answer.

A cart guard sees only what is known in the request that carries the proof. Within that request a checkout passes the method to `place()` when it knows it, and an inner `place()` of a nested call asks the guards again with what is known by then. A `place()` in a request with no proof runs the placement at once and asks no guard, so a method chosen in a later request never reaches a cart guard and needs a [recognition guard](#recognition-guards).

### Undo steps

`UndoStepInterface` takes back work a test order did outside Magento. Add steps to the `steps` argument of `Hryvinskyi\TestOrder\Model\UndoRunner`.

A step runs once per committed claim on a test order, when the order is cancelled by anyone, or when it is abandoned after it can no longer be cancelled, which for a paid order is after its refund, so the step sees it closed. The claim and the steps run inside the cancellation's transaction, so when that rolls back the claim goes with it and the steps run again on the next try. A step must therefore be safe to repeat, and keep outside calls short, as it holds the order row. A step that throws is logged and not retried, and the other steps still run.

### Collection readers

An integration that selects orders itself instead of reacting to an event calls `TestOrderFilterInterface::excludeTestOrders()` on its order collection. Applying it twice changes nothing, and an order that names no cart stays in.

## Admin settings and clean-up

Go to **Stores > Configuration > Hryvinskyi Extensions > Test Orders**. Both settings are in the default scope.

| Path | Default | Meaning |
|------|---------|---------|
| `test_order/cleanup/delete_cancelled` | No | Deletes a test order for good once it is cancelled. A closed order stays. Meant for a test environment. |
| `test_order/cleanup/abandon_after_minutes` | 30 | How long after recognition the cron ends a test order the runner left behind. |

The cron job `hryvinskyi_test_order_abandon_stale` runs every five minutes, 50 carts at a time. It takes up a cart while one of its orders is not undone. With `delete_cancelled` on, it also takes up a cart while one of its orders is cancelled and still exists: its delete failed, or someone else cancelled it.

A paid order is refunded and closed, as [above](#how-a-paid-test-order-ends). An order that can be neither cancelled nor refunded is undone, kept, and logged as a warning for a person to end; the job does not come back for it. Nor does it come back for a closed order once that is undone. A cart that fails, whether cancelling, refunding or deleting, is tried five times in all. After that a critical log names the cart and its orders, and they stay test orders for a person to end. The job also forgets carts that never became an order, a day after recognition.

## Running the tests

Run `setup:di:compile` first: the tests mock generated factories, and PHPStan reads `generated/code`. These commands assume the module sits in `app/code/Hryvinskyi/TestOrder`.

```bash
vendor/bin/phpunit -c app/code/Hryvinskyi/TestOrder/phpunit.xml.dist
```

The integration tests run in Magento's own harness and read stock through Magento's inventory modules, so they need those enabled. A sandbox installed before this module needs one reinstall with `TESTS_CLEANUP` enabled, and that install fails while `generated/metadata` holds compiled files, so empty it first.

```bash
cd dev/tests/integration
../../../vendor/bin/phpunit ../../../app/code/Hryvinskyi/TestOrder/Test/Integration
```

PHPStan runs at level 10, which needs version 2:

```bash
phpstan analyse -c app/code/Hryvinskyi/TestOrder/phpstan.neon.dist
```

## License

MIT. See [LICENSE](LICENSE).
