<?php

namespace justinholtweb\headdy\controllers\api;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Response;
use justinholtweb\headdy\errors\ApiException;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\models\Webhook;
use justinholtweb\headdy\Plugin;
use justinholtweb\headdy\services\Serializer;
use justinholtweb\headdy\web\ApiController;

/**
 * Cart endpoints.
 *
 * Every mutation here is a handful of lines that assembles parameters and hands them to
 * {@see \justinholtweb\headdy\services\Carts::update()}. That is the point: the granular REST verbs
 * exist for the client's benefit, not as separate implementations.
 */
class CartsController extends ApiController
{
    protected function requiredScope(?string $actionId = null): ?string
    {
        return in_array($actionId, ['get', 'options-for'], true)
            ? ApiKey::SCOPE_CART_READ
            : ApiKey::SCOPE_CART_WRITE;
    }

    /**
     * `POST /carts` — creates a cart and returns it with a fresh token.
     *
     * Accepts the same body as an update, so a front end can create a cart with items already in
     * it and skip a round trip on the "add to cart from a product page with no cart yet" path,
     * which is the single most common first request a storefront makes.
     */
    public function actionCreate(): Response
    {
        $plugin = Plugin::getInstance();
        $context = $plugin->getRequestContext();
        $body = $this->body();

        $cart = $plugin->getCarts()->createCart(
            $context->getKey(),
            $this->_storeId(),
            $this->_siteId(),
            $context->getCustomer(),
        );

        $token = $plugin->getTokens()->issueCartToken($cart, $context->getKey());
        $context->setCart($cart, $token);

        if ($this->_hasMutations($body)) {
            $cart = $plugin->getCarts()->update($cart, $this->_mutationParams($body));
        }

        $plugin->getWebhooks()->dispatch(Webhook::TOPIC_CART_CREATED, [
            'cart' => $plugin->getSerializer()->cart($cart),
        ], $cart->storeId);

        return $this->success(['cart' => $plugin->getSerializer()->cart($cart, $token)], 201);
    }

    /**
     * `GET /carts/current` — the cart behind the supplied token.
     */
    public function actionGet(): Response
    {
        $cart = $this->requireCart();
        $plugin = Plugin::getInstance();

        return $this->success([
            'cart' => $plugin->getSerializer()->cart($cart, $plugin->getRequestContext()->getCartToken()),
        ]);
    }

    /**
     * `PATCH /carts/current` — applies any combination of changes in one request.
     */
    public function actionUpdate(): Response
    {
        return $this->_mutate(fn(Order $cart) => Plugin::getInstance()
            ->getCarts()
            ->update($cart, $this->_mutationParams($this->body())));
    }

    /**
     * `POST /carts/current/items` — adds one item, or several.
     */
    public function actionAddItem(): Response
    {
        $body = $this->body();
        $items = $body['items'] ?? null;

        if (!is_array($items) || !$items) {
            $items = [[
                'purchasableId' => $body['purchasableId'] ?? null,
                'qty' => $body['qty'] ?? 1,
                'options' => $body['options'] ?? [],
                'note' => $body['note'] ?? '',
            ]];
        }

        return $this->_mutate(fn(Order $cart) => Plugin::getInstance()
            ->getCarts()
            ->update($cart, ['addItems' => $items]));
    }

    /**
     * `PATCH /carts/current/items/<lineItemId>` — changes quantity, note or options.
     */
    public function actionUpdateItem(string $lineItemId): Response
    {
        $body = $this->body();
        $changes = [];

        foreach (['qty', 'note', 'options', 'remove'] as $field) {
            if (array_key_exists($field, $body)) {
                $changes[$field] = $body[$field];
            }
        }

        return $this->_mutate(fn(Order $cart) => Plugin::getInstance()
            ->getCarts()
            ->update($cart, ['updateItems' => [$lineItemId => $changes]]));
    }

    /**
     * `DELETE /carts/current/items/<lineItemId>`
     */
    public function actionRemoveItem(string $lineItemId): Response
    {
        return $this->_mutate(fn(Order $cart) => Plugin::getInstance()
            ->getCarts()
            ->update($cart, ['removeItems' => [$lineItemId]]));
    }

    /**
     * `DELETE /carts/current/items` — empties the cart but keeps it, and keeps its token.
     */
    public function actionClear(): Response
    {
        return $this->_mutate(fn(Order $cart) => Plugin::getInstance()
            ->getCarts()
            ->update($cart, ['clearLineItems' => true]));
    }

    /**
     * `PUT /carts/current/addresses`
     */
    public function actionSetAddresses(): Response
    {
        $body = $this->body();
        $params = [];

        foreach (['shippingAddress', 'billingAddress', 'billingSameAsShipping', 'shippingSameAsBilling'] as $field) {
            if (array_key_exists($field, $body)) {
                $params[$field] = $body[$field];
            }
        }

        return $this->_mutate(fn(Order $cart) => Plugin::getInstance()->getCarts()->update($cart, $params));
    }

    /**
     * `PUT /carts/current/email`
     */
    public function actionSetEmail(): Response
    {
        return $this->_mutate(fn(Order $cart) => Plugin::getInstance()
            ->getCarts()
            ->update($cart, ['email' => $this->param('email')]));
    }

    /**
     * `PUT /carts/current/coupon` — send `{"couponCode": null}` to remove one.
     */
    public function actionSetCoupon(): Response
    {
        return $this->_mutate(fn(Order $cart) => Plugin::getInstance()
            ->getCarts()
            ->update($cart, ['couponCode' => $this->param('couponCode')]));
    }

    /**
     * `PUT /carts/current/shipping-method`
     */
    public function actionSetShippingMethod(): Response
    {
        return $this->_mutate(fn(Order $cart) => Plugin::getInstance()
            ->getCarts()
            ->update($cart, ['shippingMethodHandle' => $this->param('shippingMethodHandle')]));
    }

    /**
     * `GET /carts/current/shipping-methods` — what this cart can actually be shipped by, priced.
     */
    public function actionShippingMethods(): Response
    {
        $cart = $this->requireCart();
        $serializer = Plugin::getInstance()->getSerializer();

        return $this->success([
            'shippingMethods' => $serializer->shippingMethodOptions(
                $cart->getAvailableShippingMethodOptions(),
                Serializer::currencyFor($cart),
            ),
        ]);
    }

    /**
     * `DELETE /carts/current` — throws the cart away and revokes its token.
     */
    public function actionDelete(): Response
    {
        $cart = $this->requireCart();
        Plugin::getInstance()->getCarts()->deleteCart($cart);

        return $this->success(['deleted' => true]);
    }

    /**
     * `POST /carts/current/attach` — claims a guest cart for the signed-in customer.
     *
     * The move that stops a shopper's basket evaporating when they log in halfway through.
     *
     * @throws ApiException
     */
    public function actionAttach(): Response
    {
        $cart = $this->requireCart();
        $customer = $this->requireCustomer();

        Plugin::getInstance()->getCustomers()->attachCartToCustomer($cart, $customer);

        return $this->success([
            'cart' => Plugin::getInstance()->getSerializer()->cart(
                $cart,
                Plugin::getInstance()->getRequestContext()->getCartToken(),
            ),
        ]);
    }

    // Internals
    // =========================================================================

    /**
     * @param callable(Order): Order $mutation
     * @throws ApiException
     */
    private function _mutate(callable $mutation): Response
    {
        $plugin = Plugin::getInstance();
        $cart = $mutation($this->requireCart());
        $token = $plugin->getRequestContext()->getCartToken();

        $plugin->getWebhooks()->dispatch(Webhook::TOPIC_CART_UPDATED, [
            'cart' => $plugin->getSerializer()->cart($cart),
        ], $cart->storeId);

        return $this->success(['cart' => $plugin->getSerializer()->cart($cart, $token)]);
    }

    /**
     * The subset of a request body that `Carts::update()` understands.
     *
     * Built by whitelist. Copying the body wholesale would let a caller set `isCompleted` or
     * `totalPaid` by guessing an attribute name.
     */
    private function _mutationParams(array $body): array
    {
        $allowed = [
            'clearLineItems', 'clearNotices',
            'addItems', 'updateItems', 'removeItems',
            'shippingAddress', 'billingAddress', 'billingSameAsShipping', 'shippingSameAsBilling',
            'email', 'couponCode', 'shippingMethodHandle', 'gatewayId', 'paymentCurrency',
            'message', 'fields',
            'registerUserOnOrderComplete', 'saveBillingAddressOnOrderComplete', 'saveShippingAddressOnOrderComplete',
        ];

        // `array_intersect_key` rather than a loop with `!empty()`: a present-but-null value has to
        // survive, because that is how a caller clears a coupon or an address.
        $params = array_intersect_key($body, array_flip($allowed));

        // `items` is the friendlier alias the create and add-item endpoints document.
        if (!isset($params['addItems']) && isset($body['items']) && is_array($body['items'])) {
            $params['addItems'] = $body['items'];
        }

        return $params;
    }

    private function _hasMutations(array $body): bool
    {
        return $this->_mutationParams($body) !== [];
    }

    private function _storeId(): ?int
    {
        $value = $this->param('storeId') ?? $this->request->getHeaders()->get('X-Headdy-Store');

        return $value !== null && $value !== '' ? (int)$value : null;
    }

    private function _siteId(): ?int
    {
        $value = $this->param('siteId') ?? $this->request->getHeaders()->get('X-Headdy-Site');

        if ($value === null || $value === '') {
            return null;
        }

        // A handle is friendlier for a front end whose config already names the site.
        if (!is_numeric($value)) {
            return Craft::$app->getSites()->getSiteByHandle((string)$value)?->id;
        }

        return (int)$value;
    }
}
