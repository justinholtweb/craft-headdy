<?php

namespace justinholtweb\headdy\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\helpers\LineItem as LineItemHelper;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use craft\errors\ElementNotFoundException;
use justinholtweb\headdy\errors\ApiException;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Cart mutation.
 *
 * ## The invariant
 *
 * **`update()` is the only place a cart changes.** Every REST endpoint, every GraphQL mutation and
 * every console command builds a parameter array and hands it here. There is no second path that
 * adds a line item, and so no second path that can disagree about stock checks, option signatures,
 * recalculation order or what a saved cart looks like afterwards.
 *
 * The granular endpoints (`POST /cart/items`, `PATCH /cart/items/{id}`, …) exist because they read
 * well and they are what a REST client expects — but each one is a few lines that assembles params
 * and calls `update()`.
 *
 * ## Ordering
 *
 * Mutations are applied in a fixed order regardless of the order the caller wrote them in:
 * clear → items → addresses → email → coupon → shipping → gateway → fields. That matters. Setting
 * a shipping method before an address exists picks from an empty option list; applying a coupon
 * before the items are in does not meet the discount's minimum. A client that sends one big patch
 * gets the same result as one that sends six small ones.
 */
class Carts extends Component
{
    /**
     * @var int Seconds to wait for another request to finish with this cart.
     */
    public int $lockTimeout = 5;

    /**
     * Creates and saves a new empty cart.
     *
     * Unlike Commerce's `Carts::getCart()`, this never touches the session or a cookie — the
     * returned cart is identified from here on by the token the caller is handed alongside it.
     */
    public function createCart(?ApiKey $key = null, ?int $storeId = null, ?int $siteId = null, ?User $customer = null): Order
    {
        $commerce = Commerce::getInstance();
        $store = $storeId !== null
            ? $commerce->getStores()->getStoreById($storeId)
            : $commerce->getStores()->getCurrentStore();

        if ($store === null) {
            throw ApiException::invalid(Craft::t('headdy', 'Unknown store.'));
        }

        $cart = Craft::createObject(Order::class);
        $cart->number = $commerce->getCarts()->generateCartNumber();
        $cart->storeId = $store->id;
        $cart->orderSiteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $cart->origin = Order::ORIGIN_WEB;

        if ($customer !== null) {
            $cart->setCustomer($customer);
        }

        if (!Craft::$app->getElements()->saveElement($cart, false, false, false)) {
            throw new ApiException(
                ApiException::CART_INVALID,
                Craft::t('headdy', 'Could not create a cart.'),
                500,
                Plugin::getInstance()->getSerializer()->errors($cart),
            );
        }

        return $cart;
    }

    /**
     * Applies a set of changes to a cart and saves it.
     *
     * Every key is optional. An absent key means "leave this alone"; a present key with a null
     * value means "clear it". That distinction is the whole reason the API takes JSON rather than
     * form-encoded params — `couponCode=""` and no `couponCode` at all are different requests, and
     * a form body cannot tell them apart.
     *
     * @param array $params {
     *     @var bool        $clearLineItems
     *     @var bool        $clearNotices
     *     @var array[]     $addItems         Each: purchasableId, qty, options, note
     *     @var array[]     $updateItems      Keyed by line item id or uid: qty, note, options, remove
     *     @var int[]       $removeItems      Line item ids
     *     @var array|null  $shippingAddress
     *     @var array|null  $billingAddress
     *     @var bool        $billingSameAsShipping
     *     @var bool        $shippingSameAsBilling
     *     @var string|null $email
     *     @var string|null $couponCode
     *     @var string|null $shippingMethodHandle
     *     @var int|null    $gatewayId
     *     @var string|null $paymentCurrency
     *     @var string|null $message
     *     @var array       $fields
     * }
     * @throws ApiException
     */
    public function update(Order $cart, array $params): Order
    {
        if ($cart->isCompleted) {
            throw new ApiException(
                ApiException::CART_COMPLETED,
                Craft::t('headdy', 'This order has already been completed and can no longer be changed.'),
                409,
            );
        }

        return $this->withLock($cart, function(Order $cart) use ($params): Order {
            $this->_applyClear($cart, $params);
            $this->_applyItems($cart, $params);
            $this->_applyAddresses($cart, $params);
            $this->_applyCustomer($cart, $params);
            $this->_applyCoupon($cart, $params);
            $this->_applyShipping($cart, $params);
            $this->_applyPayment($cart, $params);
            $this->_applyMisc($cart, $params);

            $this->save($cart);

            return $cart;
        });
    }

    /**
     * Validates and saves a cart, turning a failure into an `ApiException` that carries the cart's
     * current state — a client that gets a 422 still needs to render something.
     *
     * @throws ApiException
     */
    public function save(Order $cart): void
    {
        $settings = Commerce::getInstance()->getSettings();
        $serializer = Plugin::getInstance()->getSerializer();

        // Craft's own cart controller validates `activeAttributes()` rather than everything, so
        // that a half-filled cart is savable and only checkout enforces completeness. Headdy has
        // to match that, or adding the first item to an empty cart would fail on a missing address.
        $valid = $cart->validate($cart->activeAttributes(), false);
        $saved = $valid && Craft::$app->getElements()->saveElement($cart, false, false, $settings->updateCartSearchIndexes);

        if (!$saved) {
            throw new ApiException(
                ApiException::CART_INVALID,
                Craft::t('headdy', 'Could not update the cart.'),
                422,
                $serializer->shouldExposeErrors() ? $serializer->errors($cart) : [],
                ['cart' => $serializer->cart($cart)],
            );
        }
    }

    /**
     * Runs a callback holding Commerce's own per-order mutex.
     *
     * The same lock name Commerce uses (`order:<number>`), deliberately: a Headdy request and a
     * legacy Twig form post against the same cart must contend with each other, not run
     * concurrently and clobber one another's line items.
     *
     * @template T
     * @param callable(Order): T $callback
     * @return T
     * @throws ApiException
     */
    public function withLock(Order $cart, callable $callback): mixed
    {
        if (!$cart->number) {
            return $callback($cart);
        }

        $mutex = Craft::$app->getMutex();
        $lockName = "order:{$cart->number}";

        if (!$mutex->acquire($lockName, $this->lockTimeout)) {
            throw new ApiException(
                ApiException::CART_LOCKED,
                Craft::t('headdy', 'This cart is busy with another request. Try again.'),
                409,
            );
        }

        try {
            return $callback($cart);
        } finally {
            $mutex->release($lockName);
        }
    }

    // Mutations
    // =========================================================================

    private function _applyClear(Order $cart, array $params): void
    {
        if (!empty($params['clearLineItems'])) {
            $cart->setLineItems([]);
        }

        if (!empty($params['clearNotices'])) {
            $cart->clearNotices();
        }
    }

    /**
     * @throws ApiException
     */
    private function _applyItems(Order $cart, array $params): void
    {
        $adds = $params['addItems'] ?? null;

        if (is_array($adds) && $adds) {
            foreach ($this->_mergeDuplicates($adds) as $item) {
                $this->_addItem($cart, $item);
            }
        }

        $updates = $params['updateItems'] ?? null;

        if (is_array($updates) && $updates) {
            foreach ($updates as $ref => $changes) {
                $this->_updateItem($cart, (string)$ref, is_array($changes) ? $changes : []);
            }
        }

        $removes = $params['removeItems'] ?? null;

        if (is_array($removes) && $removes) {
            foreach ($removes as $ref) {
                $item = $this->findLineItem($cart, (string)$ref);

                if ($item === null) {
                    throw ApiException::notFound(
                        Craft::t('headdy', 'No line item “{ref}” in this cart.', ['ref' => $ref]),
                        ApiException::LINE_ITEM_NOT_FOUND,
                    );
                }

                $cart->removeLineItem($item);
            }
        }
    }

    /**
     * Two entries for the same purchasable and the same options are one line item with the
     * quantities added, not two line items — matching what Commerce's own multi-add does. Without
     * this, `[{id:1,qty:1},{id:1,qty:1}]` silently becomes a cart with one item of quantity 1,
     * because the second `resolveLineItem()` finds and overwrites the first.
     *
     * @return array[]
     */
    private function _mergeDuplicates(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $purchasableId = (int)($item['purchasableId'] ?? $item['id'] ?? 0);

            if (!$purchasableId) {
                continue;
            }

            $options = $item['options'] ?? [];
            $options = is_array($options) ? $options : [];
            $key = $purchasableId . '-' . LineItemHelper::generateOptionsSignature($options);

            if (isset($merged[$key])) {
                $merged[$key]['qty'] += max(0, (int)($item['qty'] ?? 1));
                continue;
            }

            $merged[$key] = [
                'purchasableId' => $purchasableId,
                'qty' => max(0, (int)($item['qty'] ?? 1)),
                'options' => $options,
                'note' => (string)($item['note'] ?? ''),
            ];
        }

        return array_values($merged);
    }

    /**
     * @throws ApiException
     */
    private function _addItem(Order $cart, array $item): void
    {
        $purchasableId = (int)$item['purchasableId'];
        $qty = (int)$item['qty'];

        if ($qty <= 0) {
            return;
        }

        $purchasable = Commerce::getInstance()->getPurchasables()->getPurchasableById($purchasableId, $cart->orderSiteId);

        if ($purchasable === null) {
            throw ApiException::notFound(
                Craft::t('headdy', 'No purchasable with the ID “{id}”.', ['id' => $purchasableId]),
                ApiException::PURCHASABLE_NOT_FOUND,
            );
        }

        // Checked up front so an unavailable item is a clean 422 naming the item, rather than a
        // generic "could not update the cart" from the save further down.
        if (!Commerce::getInstance()->getPurchasables()->isPurchasableAvailable($purchasable, $cart)) {
            throw new ApiException(
                ApiException::PURCHASABLE_UNAVAILABLE,
                Craft::t('headdy', '“{description}” is not available.', ['description' => $purchasable->getDescription()]),
                422,
                [],
                ['purchasableId' => $purchasableId],
            );
        }

        $params = [
            'purchasableId' => $purchasableId,
            'options' => $item['options'],
            'note' => $item['note'],
            'qty' => $qty,
        ];

        $lineItem = Commerce::getInstance()->getLineItems()->resolveLineItem($cart, $purchasableId, $item['options'], $params);

        // `resolveLineItem()` returns the existing line if one matches; a new one already carries a
        // qty of 1, so assigning rather than adding would silently drop an item.
        if ($lineItem->id) {
            $lineItem->qty += $qty;
        } else {
            $lineItem->qty = $qty;
        }

        if ($item['note'] !== '') {
            $lineItem->note = $item['note'];
        }

        $cart->addLineItem($lineItem);
    }

    /**
     * @throws ApiException
     */
    private function _updateItem(Order $cart, string $ref, array $changes): void
    {
        $lineItem = $this->findLineItem($cart, $ref);

        if ($lineItem === null) {
            throw ApiException::notFound(
                Craft::t('headdy', 'No line item “{ref}” in this cart.', ['ref' => $ref]),
                ApiException::LINE_ITEM_NOT_FOUND,
            );
        }

        if (array_key_exists('options', $changes) && is_array($changes['options'])) {
            $lineItem->setOptions($changes['options']);
        }

        if (array_key_exists('note', $changes)) {
            $lineItem->note = (string)$changes['note'];
        }

        $remove = !empty($changes['remove']);
        $qty = array_key_exists('qty', $changes) ? (int)$changes['qty'] : $lineItem->qty;

        if ($remove || $qty <= 0) {
            $cart->removeLineItem($lineItem);
            return;
        }

        $lineItem->qty = $qty;
        $cart->addLineItem($lineItem);
    }

    /**
     * Finds a line item by id or uid.
     *
     * Both, because a client that adds an item and immediately patches it has the uid from the
     * response but may be racing the id assignment on a cart that was never saved.
     */
    public function findLineItem(Order $cart, string $ref): ?LineItem
    {
        foreach ($cart->getLineItems() as $item) {
            if (($item->id && (string)$item->id === $ref) || ($item->uid && $item->uid === $ref)) {
                return $item;
            }
        }

        return null;
    }

    private function _applyAddresses(Order $cart, array $params): void
    {
        $shippingSameAsBilling = !empty($params['shippingSameAsBilling']);
        $billingSameAsShipping = !empty($params['billingSameAsShipping']);

        if (array_key_exists('shippingAddress', $params) && !$shippingSameAsBilling) {
            $address = $params['shippingAddress'];

            if ($address === null) {
                $cart->setShippingAddress(null);
                $cart->sourceShippingAddressId = null;
            } elseif (is_array($address)) {
                $cart->sourceShippingAddressId = null;

                // An *array* rather than an Address element on purpose. Commerce refuses an address
                // element it does not own — "Can not set a shipping address on the order that is
                // not owned by the order" — so it has to build the owned element itself.
                $cart->setShippingAddress($this->_addressAttributes($address));

                if (!empty($address['fields']) && $cart->getShippingAddress()) {
                    $cart->getShippingAddress()->setFieldValues($address['fields']);
                }
            }
        }

        if (array_key_exists('billingAddress', $params) && !$billingSameAsShipping) {
            $address = $params['billingAddress'];

            if ($address === null) {
                $cart->setBillingAddress(null);
                $cart->sourceBillingAddressId = null;
            } elseif (is_array($address)) {
                $cart->sourceBillingAddressId = null;
                $cart->setBillingAddress($this->_addressAttributes($address));

                if (!empty($address['fields']) && $cart->getBillingAddress()) {
                    $cart->getBillingAddress()->setFieldValues($address['fields']);
                }
            }
        }

        // Set after both addresses so the copy picks up whatever this request just wrote, rather
        // than whatever was on the cart when the request started.
        if ($billingSameAsShipping) {
            $cart->sourceBillingAddressId = null;
            $cart->setBillingAddress($cart->getShippingAddress());
        }

        if ($shippingSameAsBilling) {
            $cart->sourceShippingAddressId = null;
            $cart->setShippingAddress($cart->getBillingAddress());
        }

        $cart->billingSameAsShipping = $billingSameAsShipping;
        $cart->shippingSameAsBilling = $shippingSameAsBilling;
    }

    /**
     * Strips anything that is not an address attribute.
     *
     * `fields` is handled separately and everything else — an `id` a client echoed back from a GET,
     * say — would either be ignored or, worse, make Commerce try to adopt someone else's address.
     */
    private function _addressAttributes(array $address): array
    {
        $allowed = [
            'fullName', 'firstName', 'lastName', 'organization', 'organizationTaxId',
            'addressLine1', 'addressLine2', 'addressLine3',
            'locality', 'dependentLocality', 'administrativeArea',
            'postalCode', 'sortingCode', 'countryCode',
        ];

        $out = array_intersect_key($address, array_flip($allowed));

        if (isset($address['label']) && is_string($address['label'])) {
            $out['title'] = $address['label'];
        }

        return $out;
    }

    /**
     * @throws ApiException
     */
    private function _applyCustomer(Order $cart, array $params): void
    {
        if (!array_key_exists('email', $params)) {
            return;
        }

        $email = $params['email'];

        if ($email === null || $email === '') {
            return;
        }

        // FILTER_VALIDATE_EMAIL accepts `*` and `%` in the local part, and `ensureUserByEmail()`
        // looks the user up through Db::parseParam(), where those are LIKE wildcards — so
        // `*@gmail.com` would bind the cart to whichever account matched first. Characters that
        // mean something to parseParam() are refused outright, not escaped: escaping the lookup
        // would still leave ensureUserByEmail() to run the unescaped one.
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[*%,\\\\]|^[=<>!]/', $email)) {
            throw ApiException::invalid(
                Craft::t('headdy', 'That email address is not valid.'),
                ['email' => [Craft::t('headdy', 'That email address is not valid.')]],
            );
        }

        // A cart already owned by a credentialed user keeps its customer: letting an anonymous
        // caller rewrite the email would move someone else's cart onto an address they control.
        $existing = $cart->getCustomer();

        if ($existing !== null && $existing->getIsCredentialed() && strcasecmp((string)$existing->email, $email) !== 0) {
            throw ApiException::forbidden(
                Craft::t('headdy', 'This cart belongs to a registered customer and its email address cannot be changed.'),
            );
        }

        if ($cart->getEmail() !== null && strcasecmp((string)$cart->getEmail(), $email) === 0) {
            return;
        }

        try {
            $cart->setCustomer(Craft::$app->getUsers()->ensureUserByEmail($email));
        } catch (Throwable $e) {
            throw ApiException::invalid(
                Craft::t('headdy', 'That email address could not be used.'),
                ['email' => [$e->getMessage()]],
            );
        }
    }

    private function _applyCoupon(Order $cart, array $params): void
    {
        if (!array_key_exists('couponCode', $params)) {
            return;
        }

        $code = $params['couponCode'];
        $cart->couponCode = is_string($code) && trim($code) !== '' ? trim($code) : null;
    }

    /**
     * @throws ApiException
     */
    private function _applyShipping(Order $cart, array $params): void
    {
        if (!array_key_exists('shippingMethodHandle', $params)) {
            return;
        }

        $handle = $params['shippingMethodHandle'];

        if ($handle === null || $handle === '') {
            $cart->shippingMethodHandle = null;
            return;
        }

        // Checked against the options *for this cart*, not the store's full list: a method that
        // exists but does not match this cart's address would otherwise be accepted here and then
        // silently priced at zero.
        $options = $cart->getAvailableShippingMethodOptions();

        if (!isset($options[$handle])) {
            throw new ApiException(
                ApiException::SHIPPING_METHOD_UNAVAILABLE,
                Craft::t('headdy', '“{handle}” is not an available shipping method for this cart.', ['handle' => $handle]),
                422,
                [],
                ['availableShippingMethods' => array_keys($options)],
            );
        }

        $cart->shippingMethodHandle = $handle;
    }

    /**
     * @throws ApiException
     */
    private function _applyPayment(Order $cart, array $params): void
    {
        if (array_key_exists('gatewayId', $params)) {
            $gatewayId = $params['gatewayId'];

            if ($gatewayId !== null && $gatewayId !== '') {
                $gateway = Commerce::getInstance()->getGateways()->getGatewayById((int)$gatewayId);

                if ($gateway === null || !$gateway->getIsFrontendEnabled()) {
                    throw new ApiException(
                        ApiException::PAYMENT_GATEWAY_UNAVAILABLE,
                        Craft::t('headdy', 'That payment gateway is not available.'),
                        422,
                    );
                }

                $cart->setGatewayId((int)$gatewayId);
            }
        }

        if (array_key_exists('paymentCurrency', $params) && is_string($params['paymentCurrency']) && $params['paymentCurrency'] !== '') {
            $cart->paymentCurrency = $params['paymentCurrency'];
        }
    }

    private function _applyMisc(Order $cart, array $params): void
    {
        if (array_key_exists('message', $params)) {
            $cart->message = is_string($params['message']) ? $params['message'] : null;
        }

        if (!empty($params['fields']) && is_array($params['fields'])) {
            $cart->setFieldValues($params['fields']);
        }

        // Each of these writes to the customer's account when the order completes. On a cart
        // belonging to a registered account, only that customer — proven by their token — may
        // ask for it; otherwise a cart token alone could plant an address in someone's book.
        $customer = $cart->getCustomer();
        $mayTouchAccount = $customer === null
            || !$customer->getIsCredentialed()
            || Plugin::getInstance()->getRequestContext()->getCustomer()?->id === $customer->id;

        foreach (['registerUserOnOrderComplete', 'saveBillingAddressOnOrderComplete', 'saveShippingAddressOnOrderComplete'] as $flag) {
            if (array_key_exists($flag, $params) && $mayTouchAccount) {
                $cart->$flag = (bool)$params[$flag];
            }
        }
    }

    /**
     * Deletes a cart outright, tokens and all.
     *
     * @throws Throwable
     * @throws ElementNotFoundException
     */
    public function deleteCart(Order $cart): bool
    {
        Plugin::getInstance()->getTokens()->revokeCartTokensForOrder((int)$cart->id);

        return Craft::$app->getElements()->deleteElement($cart, true);
    }
}
