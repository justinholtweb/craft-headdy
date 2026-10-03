<?php

namespace justinholtweb\headdy\gql;

use Craft;
use craft\commerce\elements\Order;
use craft\gql\base\Mutation;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\headdy\errors\ApiException;
use justinholtweb\headdy\gql\types\CartType;
use justinholtweb\headdy\Plugin;
use justinholtweb\headdy\web\RateLimiter;

/**
 * The cart mutations Craft Commerce does not ship.
 *
 * Commerce has exposed products through GraphQL since version 2 and has never exposed a single
 * cart mutation — craftcms/commerce discussion #2350 has been open for years, and the standing
 * advice is to abandon GraphQL for the cart and POST form-encoded requests to
 * `actions/commerce/cart/update-cart` with a session cookie and a CSRF token.
 *
 * These mutations close that gap. Every one of them resolves through
 * {@see \justinholtweb\headdy\services\Carts::update()} — the same method the REST endpoints call —
 * so a shop can use GraphQL for the cart and REST for payment, or either for both, and get
 * identical behaviour.
 *
 * ## Authentication
 *
 * A `cartToken` argument, not a cookie. `headdyCartCreate` returns one; hold it and pass it back.
 * A Craft GraphQL schema still governs *access to the endpoint*, so a public schema is required
 * for an anonymous storefront exactly as it is for product queries.
 */
class CartMutations extends Mutation
{
    /**
     * @inheritdoc
     */
    public static function getMutations(): array
    {
        $cart = CartType::cart();

        return [
            'headdyCartCreate' => [
                'type' => $cart,
                'description' => 'Creates a cart and returns it with a fresh token. Hold the token — every other cart mutation needs it.',
                'args' => [
                    'items' => Type::listOf(CartType::lineItemInput()),
                    'email' => Type::string(),
                    'storeId' => Type::int(),
                    'siteId' => Type::int(),
                ],
                'resolve' => static fn($root, array $args) => self::create($args),
            ],

            'headdyCartAddItem' => [
                'type' => $cart,
                'description' => 'Adds a purchasable to the cart.',
                'args' => [
                    'cartToken' => Type::nonNull(Type::string()),
                    'purchasableId' => Type::nonNull(Type::int()),
                    'qty' => Type::int(),
                    'note' => Type::string(),
                    'options' => Type::string(),
                ],
                'resolve' => static fn($root, array $args) => self::mutate($args, [
                    'addItems' => [[
                        'purchasableId' => $args['purchasableId'],
                        'qty' => $args['qty'] ?? 1,
                        'note' => $args['note'] ?? '',
                        'options' => self::decodeOptions($args['options'] ?? null),
                    ]],
                ]),
            ],

            'headdyCartUpdateItem' => [
                'type' => $cart,
                'description' => 'Changes a line item’s quantity or note. A quantity of 0 removes it.',
                'args' => [
                    'cartToken' => Type::nonNull(Type::string()),
                    'lineItemId' => Type::nonNull(Type::string()),
                    'qty' => Type::int(),
                    'note' => Type::string(),
                    'options' => Type::string(),
                ],
                'resolve' => static function($root, array $args) {
                    $changes = [];

                    foreach (['qty', 'note'] as $field) {
                        if (array_key_exists($field, $args)) {
                            $changes[$field] = $args[$field];
                        }
                    }

                    if (!empty($args['options'])) {
                        $changes['options'] = self::decodeOptions($args['options']);
                    }

                    return self::mutate($args, ['updateItems' => [$args['lineItemId'] => $changes]]);
                },
            ],

            'headdyCartRemoveItem' => [
                'type' => $cart,
                'description' => 'Removes a line item.',
                'args' => [
                    'cartToken' => Type::nonNull(Type::string()),
                    'lineItemId' => Type::nonNull(Type::string()),
                ],
                'resolve' => static fn($root, array $args) => self::mutate($args, [
                    'removeItems' => [$args['lineItemId']],
                ]),
            ],

            'headdyCartClear' => [
                'type' => $cart,
                'description' => 'Empties the cart, keeping the cart and its token.',
                'args' => ['cartToken' => Type::nonNull(Type::string())],
                'resolve' => static fn($root, array $args) => self::mutate($args, ['clearLineItems' => true]),
            ],

            'headdyCartSetEmail' => [
                'type' => $cart,
                'args' => [
                    'cartToken' => Type::nonNull(Type::string()),
                    'email' => Type::nonNull(Type::string()),
                ],
                'resolve' => static fn($root, array $args) => self::mutate($args, ['email' => $args['email']]),
            ],

            'headdyCartSetAddresses' => [
                'type' => $cart,
                'args' => [
                    'cartToken' => Type::nonNull(Type::string()),
                    'shippingAddress' => CartType::addressInput(),
                    'billingAddress' => CartType::addressInput(),
                    'billingSameAsShipping' => Type::boolean(),
                    'shippingSameAsBilling' => Type::boolean(),
                ],
                'resolve' => static function($root, array $args) {
                    $params = [];

                    foreach (['shippingAddress', 'billingAddress', 'billingSameAsShipping', 'shippingSameAsBilling'] as $field) {
                        if (array_key_exists($field, $args)) {
                            $params[$field] = $args[$field];
                        }
                    }

                    return self::mutate($args, $params);
                },
            ],

            'headdyCartApplyCoupon' => [
                'type' => $cart,
                'description' => 'Applies a coupon code. Pass an empty string to remove the one that is set.',
                'args' => [
                    'cartToken' => Type::nonNull(Type::string()),
                    'couponCode' => Type::nonNull(Type::string()),
                ],
                'resolve' => static fn($root, array $args) => self::mutate($args, ['couponCode' => $args['couponCode']]),
            ],

            'headdyCartSetShippingMethod' => [
                'type' => $cart,
                'args' => [
                    'cartToken' => Type::nonNull(Type::string()),
                    'shippingMethodHandle' => Type::nonNull(Type::string()),
                ],
                'resolve' => static fn($root, array $args) => self::mutate($args, [
                    'shippingMethodHandle' => $args['shippingMethodHandle'],
                ]),
            ],

            'headdyCartSetGateway' => [
                'type' => $cart,
                'args' => [
                    'cartToken' => Type::nonNull(Type::string()),
                    'gatewayId' => Type::nonNull(Type::int()),
                ],
                'resolve' => static fn($root, array $args) => self::mutate($args, ['gatewayId' => $args['gatewayId']]),
            ],

            'headdyCheckoutComplete' => [
                'type' => $cart,
                'description' => 'Completes an order that owes nothing. Orders that need paying go through the REST payment endpoint, where the gateway’s redirect can be handled properly.',
                'args' => ['cartToken' => Type::nonNull(Type::string())],
                'resolve' => static function($root, array $args) {
                    $plugin = Plugin::getInstance();
                    $order = $plugin->getCheckout()->complete(self::cartFor($args));

                    return $plugin->getSerializer()->cart($order);
                },
            ],
        ];
    }

    /**
     * @throws \GraphQL\Error\UserError
     */
    public static function create(array $args): array
    {
        $plugin = Plugin::getInstance();
        self::guard();

        $cart = $plugin->getCarts()->createCart(null, $args['storeId'] ?? null, $args['siteId'] ?? null);
        $token = $plugin->getTokens()->issueCartToken($cart);

        $params = [];

        if (!empty($args['items'])) {
            $params['addItems'] = array_map(static fn(array $item) => [
                'purchasableId' => $item['purchasableId'],
                'qty' => $item['qty'] ?? 1,
                'note' => $item['note'] ?? '',
                'options' => self::decodeOptions($item['options'] ?? null),
            ], $args['items']);
        }

        if (!empty($args['email'])) {
            $params['email'] = $args['email'];
        }

        if ($params) {
            $cart = self::run(fn() => $plugin->getCarts()->update($cart, $params));
        }

        return $plugin->getSerializer()->cart($cart, $token);
    }

    /**
     * @throws \GraphQL\Error\UserError
     */
    public static function mutate(array $args, array $params): array
    {
        $plugin = Plugin::getInstance();
        $cart = self::cartFor($args);
        $cart = self::run(fn() => $plugin->getCarts()->update($cart, $params));

        return $plugin->getSerializer()->cart($cart, $args['cartToken']);
    }

    /**
     * @throws \GraphQL\Error\UserError
     */
    public static function cartFor(array $args): Order
    {
        self::guard();

        $cart = Plugin::getInstance()->getTokens()->getCartByToken((string)($args['cartToken'] ?? ''));

        if ($cart === null) {
            throw new \GraphQL\Error\UserError(Craft::t('headdy', 'That cart token is not valid or has expired.'));
        }

        return $cart;
    }

    /**
     * Turns an `ApiException` into a GraphQL error the client can actually read.
     *
     * The stable error code is prefixed onto the message rather than dropped: GraphQL has no status
     * code, so the code is the only machine-readable part a client gets.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws \GraphQL\Error\UserError
     */
    private static function run(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ApiException $e) {
            throw new \GraphQL\Error\UserError($e->errorCode . ': ' . $e->getMessage());
        }
    }

    /**
     * @throws \GraphQL\Error\UserError
     */
    public static function guard(string $action = 'edit'): void
    {
        $plugin = Plugin::getInstance();
        $plugin->getRequestContext()->markPublic();

        if (!$plugin->getSettings()->enabled) {
            throw new \GraphQL\Error\UserError(Craft::t('headdy', 'The storefront API is turned off.'));
        }

        if (!Plugin::commerceIsReady()) {
            throw new \GraphQL\Error\UserError(Craft::t('headdy', 'Craft Commerce is not available.'));
        }

        // Checked again here, not only when the schema is built: Craft caches schema definitions,
        // and a resolver is the last place that can refuse.
        if (!self::schemaAllows($action)) {
            throw new \GraphQL\Error\UserError(Craft::t('headdy', 'This GraphQL schema does not include Headdy carts.'));
        }

        // The REST API's rate limit applies here too, per address — GraphQL has no API key to
        // count against.
        $limit = $plugin->getSettings()->rateLimit;
        $request = Craft::$app->getRequest();

        if ($limit > 0 && !$request->getIsConsoleRequest() && RateLimiter::hit('gql.ip' . $request->getUserIP(), $limit) < 0) {
            throw new \GraphQL\Error\UserError(ApiException::RATE_LIMITED . ': ' . Craft::t('headdy', 'Too many requests. Try again shortly.'));
        }
    }

    /** The schema component this plugin registers; `headdyCarts:read` / `headdyCarts:edit`. */
    public const COMPONENT = 'headdyCarts';

    /**
     * Whether the active GraphQL schema has been granted Headdy's carts.
     */
    public static function schemaAllows(string $action): bool
    {
        try {
            return GqlHelper::canSchema(self::COMPONENT, $action);
        } catch (\Throwable) {
            // No active schema at all — nothing has granted anything.
            return false;
        }
    }

    /**
     * Line item options arrive as a JSON string because GraphQL has no map type.
     */
    public static function decodeOptions(?string $options): array
    {
        if ($options === null || trim($options) === '') {
            return [];
        }

        $decoded = json_decode($options, true);

        return is_array($decoded) ? $decoded : [];
    }
}
