<?php

namespace justinholtweb\headdy\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use justinholtweb\headdy\errors\ApiException;
use justinholtweb\headdy\helpers\Money;
use justinholtweb\headdy\Plugin;
use yii\base\Component;

/**
 * Checkout readiness.
 *
 * A Twig checkout knows what it needs because a developer wrote the steps into the templates. A
 * headless front end has no such luck: the store's requirements live in Commerce's store settings,
 * change per store, and are only discovered when a payment is rejected.
 *
 * `requirements()` reads those settings up front and answers the one question a checkout UI
 * actually asks — *what is still missing before this cart can be paid for?* — as a list of machine
 * codes. The same list is checked again inside `Payments`, so the answer here is not advice, it is
 * the actual gate.
 */
class Checkout extends Component
{
    public const REQUIRES_ITEMS = 'items';
    public const REQUIRES_EMAIL = 'email';
    public const REQUIRES_SHIPPING_ADDRESS = 'shippingAddress';
    public const REQUIRES_BILLING_ADDRESS = 'billingAddress';
    public const REQUIRES_SHIPPING_METHOD = 'shippingMethod';
    public const REQUIRES_PAYMENT_METHOD = 'paymentMethod';

    /**
     * What is still missing, in the order a checkout UI should ask for it.
     *
     * @return string[]
     */
    public function missingRequirements(Order $cart): array
    {
        $store = $cart->getStore();
        $missing = [];

        if (!$store->getAllowEmptyCartOnCheckout() && $cart->getIsEmpty()) {
            $missing[] = self::REQUIRES_ITEMS;
        }

        if (!$cart->getEmail()) {
            $missing[] = self::REQUIRES_EMAIL;
        }

        if ($store->getRequireShippingAddressAtCheckout() && !$cart->shippingAddressId) {
            $missing[] = self::REQUIRES_SHIPPING_ADDRESS;
        }

        if ($store->getRequireBillingAddressAtCheckout() && !$cart->billingAddressId) {
            $missing[] = self::REQUIRES_BILLING_ADDRESS;
        }

        if (
            $cart->hasShippableItems()
            && $store->getRequireShippingMethodSelectionAtCheckout()
            && !$cart->shippingMethodHandle
        ) {
            $missing[] = self::REQUIRES_SHIPPING_METHOD;
        }

        // A zero-balance cart — fully discounted, or a store that allows checkout without payment —
        // needs no gateway, and demanding one would make it impossible to complete.
        if ($this->requiresPayment($cart) && !$cart->gatewayId && !$cart->paymentSourceId) {
            $missing[] = self::REQUIRES_PAYMENT_METHOD;
        }

        return $missing;
    }

    /**
     * Whether this cart has to be paid for before it can be completed.
     */
    public function requiresPayment(Order $cart): bool
    {
        if ($cart->getOutstandingBalance() <= 0) {
            return false;
        }

        return !$cart->getStore()->getAllowCheckoutWithoutPayment();
    }

    public function isReady(Order $cart): bool
    {
        return $this->missingRequirements($cart) === [];
    }

    /**
     * The whole checkout picture in one response: what is required, what is available to satisfy
     * it, and what the store's rules are.
     *
     * This exists so a front end can render an entire checkout from a single GET instead of
     * discovering the store's configuration by trial and error.
     */
    public function state(Order $cart): array
    {
        $serializer = Plugin::getInstance()->getSerializer();
        $store = $cart->getStore();
        $currency = Serializer::currencyFor($cart);
        $missing = $this->missingRequirements($cart);

        return [
            'ready' => $missing === [],
            'missing' => $missing,
            'requiresPayment' => $this->requiresPayment($cart),
            'amountDue' => Money::format($cart->getOutstandingBalance(), $currency),
            'availableShippingMethods' => $serializer->shippingMethodOptions(
                $cart->getAvailableShippingMethodOptions(),
                $currency,
            ),
            'availableGateways' => array_map(
                fn($gateway) => $serializer->gateway($gateway),
                array_values($this->availableGateways($cart)),
            ),
            'store' => [
                'id' => $store->id,
                'handle' => $store->handle,
                'name' => $store->getName(),
                'currency' => $currency,
                'requiresShippingAddress' => (bool)$store->getRequireShippingAddressAtCheckout(),
                'requiresBillingAddress' => (bool)$store->getRequireBillingAddressAtCheckout(),
                'requiresShippingMethod' => (bool)$store->getRequireShippingMethodSelectionAtCheckout(),
                'allowsEmptyCart' => (bool)$store->getAllowEmptyCartOnCheckout(),
                'allowsCheckoutWithoutPayment' => (bool)$store->getAllowCheckoutWithoutPayment(),
                'allowsPartialPayment' => (bool)$store->getAllowPartialPaymentOnCheckout(),
            ],
        ];
    }

    /**
     * Gateways this cart may actually be paid with.
     *
     * Filtered by `availableForUseWithOrder()` as well as the front-end flag, because a gateway can
     * be enabled generally and still reject a particular order — a currency it does not handle, for
     * instance. Listing one that will refuse the payment is worse than not listing it.
     *
     * @return \craft\commerce\base\GatewayInterface[]
     */
    public function availableGateways(Order $cart): array
    {
        return Commerce::getInstance()
            ->getGateways()
            ->getAllCustomerEnabledGatewaysAndAvailableForUseWithOrder($cart)
            ->all();
    }

    /**
     * Completes a cart with no payment.
     *
     * Only legal when nothing is owed, or when the store explicitly allows checkout without
     * payment. Everything else goes through {@see Payments::pay()}.
     *
     * @throws ApiException
     */
    public function complete(Order $cart): Order
    {
        $missing = $this->missingRequirements($cart);

        if ($missing !== []) {
            throw new ApiException(
                ApiException::CHECKOUT_INCOMPLETE,
                Craft::t('headdy', 'This cart is not ready to be completed.'),
                422,
                [],
                ['missing' => $missing],
            );
        }

        if ($this->requiresPayment($cart)) {
            throw new ApiException(
                ApiException::CHECKOUT_INCOMPLETE,
                Craft::t('headdy', 'This order has an outstanding balance and must be paid for.'),
                422,
                [],
                ['missing' => [self::REQUIRES_PAYMENT_METHOD]],
            );
        }

        return Plugin::getInstance()->getCarts()->withLock($cart, function(Order $cart): Order {
            // Recalculate first: completing on stale totals is how a cart that was discounted an
            // hour ago gets completed at yesterday's price.
            $cart->recalculate();

            // …and ask again afterwards. The check above ran on the totals the cart had before
            // recalculating; an expired coupon or a price change can open a balance here, and
            // completing then would hand over an unpaid order.
            if ($this->requiresPayment($cart)) {
                throw new ApiException(
                    ApiException::CHECKOUT_INCOMPLETE,
                    Craft::t('headdy', 'This order has an outstanding balance and must be paid for.'),
                    422,
                    [],
                    ['missing' => [self::REQUIRES_PAYMENT_METHOD]],
                );
            }

            if (!$cart->markAsComplete()) {
                throw new ApiException(
                    ApiException::CART_INVALID,
                    Craft::t('headdy', 'Could not complete the order.'),
                    422,
                    Plugin::getInstance()->getSerializer()->errors($cart),
                );
            }

            Plugin::getInstance()->getTokens()->revokeCartTokensForOrder((int)$cart->id);

            return $cart;
        });
    }
}
