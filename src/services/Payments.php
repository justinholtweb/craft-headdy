<?php

namespace justinholtweb\headdy\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\errors\CurrencyException;
use craft\commerce\errors\PaymentException;
use craft\commerce\helpers\PaymentForm as PaymentFormHelper;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\helpers\UrlHelper;
use justinholtweb\headdy\errors\ApiException;
use justinholtweb\headdy\helpers\Money;
use justinholtweb\headdy\models\Webhook;
use justinholtweb\headdy\Plugin;
use yii\base\Component;

/**
 * Payment, headlessly.
 *
 * ## What Craft's own payment flow assumes
 *
 * `PaymentsController::actionPay()` reads its return and cancel URLs with
 * `getValidatedBodyParam()`, which only accepts a value Craft itself hashed into a Twig form. A
 * JSON client cannot produce that hash — there is no endpoint that will mint one — so the standard
 * advice for headless Commerce is to give up and proxy through a Twig page.
 *
 * Headdy validates those URLs against an explicit allow-list instead
 * ({@see \justinholtweb\headdy\models\Settings::$allowedRedirectOrigins}). The hash exists to stop
 * an attacker redirecting a shopper somewhere of their choosing after payment; an allow-list stops
 * the same thing, and unlike the hash it can be satisfied from JavaScript.
 *
 * **The list is empty by default.** An off-site gateway therefore cannot be driven from the API
 * until the merchant says where returns may land. That is the safe default, not an oversight.
 *
 * ## Off-site gateways
 *
 * Where Commerce would issue a 302, Headdy returns `{"redirect": {...}}` and lets the front end
 * decide — a full navigation, a popup, or a POST of `redirectData` into a hidden form for the
 * gateways that need one. The `completePayment` endpoint then takes the gateway's callback.
 */
class Payments extends Component
{
    /**
     * Takes a payment against a cart or an unpaid order.
     *
     * @param array $params {
     *     @var int|null    $gatewayId
     *     @var int|null    $paymentSourceId
     *     @var array       $paymentForm   Gateway-specific fields, un-namespaced
     *     @var string|null $returnUrl
     *     @var string|null $cancelUrl
     *     @var float|null  $paymentAmount For a partial payment, in the order's currency
     *     @var string|null $paymentCurrency
     * }
     * @return array The API response body.
     * @throws ApiException
     */
    public function pay(Order $order, array $params): array
    {
        $plugin = Plugin::getInstance();
        $commerce = Commerce::getInstance();
        $serializer = $plugin->getSerializer();
        $checkout = $plugin->getCheckout();

        return $plugin->getCarts()->withLock($order, function(Order $order) use ($params, $plugin, $commerce, $serializer, $checkout): array {
            $this->_applyPaymentSelection($order, $params);

            // Requirements are re-checked here rather than trusted from an earlier `/checkout`
            // call: the cart may have changed, and this is the last gate before money moves.
            $missing = $checkout->missingRequirements($order);

            if ($order->getIsActiveCart() && $missing !== []) {
                throw new ApiException(
                    ApiException::CHECKOUT_INCOMPLETE,
                    Craft::t('headdy', 'This cart is not ready to be paid for.'),
                    422,
                    [],
                    ['missing' => $missing, 'cart' => $serializer->cart($order)],
                );
            }

            $gateway = $order->getGateway();

            if ($gateway === null || !$gateway->availableForUseWithOrder($order) || !$gateway->getIsFrontendEnabled()) {
                throw new ApiException(
                    ApiException::PAYMENT_GATEWAY_UNAVAILABLE,
                    Craft::t('headdy', 'There is no payment gateway available for this order.'),
                    422,
                );
            }

            $order->returnUrl = $this->validateRedirect($params['returnUrl'] ?? null, $order);
            $order->cancelUrl = $this->validateRedirect($params['cancelUrl'] ?? null, $order);

            $paymentForm = $this->_buildPaymentForm($order, $gateway, $params);

            // Snapshot before the final recalculation, exactly as Commerce does: if the price moved
            // between the customer seeing it and paying, the payment must not go through silently.
            $originalBalance = $order->getOutstandingBalance();
            $originalQty = $order->getTotalQty();
            $originalAdjustments = count($order->getAdjustments() ?? []);

            $order->recalculate();

            $settings = $commerce->getSettings();
            $updateSearchIndex = $order->isCompleted || $settings->updateCartSearchIndexes;
            Craft::$app->getElements()->saveElement($order, true, false, $updateSearchIndex);

            $changed = [];

            if ($originalBalance != $order->getOutstandingBalance()) {
                $changed[] = 'totalPrice';
            }

            if ($originalQty != $order->getTotalQty()) {
                $changed[] = 'totalQty';
            }

            if ($originalAdjustments != count($order->getAdjustments() ?? [])) {
                $changed[] = 'totalAdjustments';
            }

            if ($changed) {
                throw new ApiException(
                    ApiException::PAYMENT_AMOUNT_CHANGED,
                    Craft::t('headdy', 'The order changed before payment. Review it and submit again.'),
                    409,
                    [],
                    ['changed' => $changed, 'cart' => $serializer->cart($order)],
                );
            }

            $this->_applyPartialPayment($order, $params);

            $paymentForm->validate();

            if ($paymentForm->hasErrors()) {
                throw new ApiException(
                    ApiException::PAYMENT_FAILED,
                    Craft::t('headdy', 'The payment details are not valid.'),
                    422,
                    $serializer->shouldExposeErrors() ? $serializer->errors($paymentForm) : [],
                    ['cart' => $serializer->cart($order)],
                );
            }

            // Commerce freezes recalculation across the gateway call so a failure leaves an editable
            // cart rather than a half-processed order.
            $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

            $redirect = '';
            $redirectData = [];
            $transaction = null;
            $wasCompleted = (bool)$order->isCompleted;
            $wasPaid = (bool)$order->getIsPaid();

            try {
                $commerce->getPayments()->processPayment($order, $paymentForm, $redirect, $transaction, $redirectData);
            } catch (PaymentException $e) {
                if (!$order->isCompleted) {
                    $order->setRecalculationMode(Order::RECALCULATION_MODE_ALL);
                }

                $plugin->getWebhooks()->dispatch(Webhook::TOPIC_PAYMENT_FAILED, [
                    'order' => $serializer->cart($order, null, true),
                    'message' => $e->getMessage(),
                ], $order->storeId);

                throw new ApiException(
                    ApiException::PAYMENT_FAILED,
                    $e->getMessage(),
                    402,
                    $serializer->shouldExposeErrors() ? $serializer->errors($order) : [],
                    ['cart' => $serializer->cart($order)],
                );
            }

            return $this->_result($order, $transaction, $redirect, $redirectData, $wasCompleted, $wasPaid);
        });
    }

    /**
     * Finishes an off-site payment from the gateway's callback.
     *
     * @throws ApiException
     */
    public function complete(string $transactionHash): array
    {
        $commerce = Commerce::getInstance();
        $transaction = $commerce->getTransactions()->getTransactionByHash($transactionHash);

        if ($transaction === null) {
            throw ApiException::notFound(
                Craft::t('headdy', 'No payment transaction matches that hash.'),
                ApiException::TRANSACTION_NOT_FOUND,
            );
        }

        $error = '';
        $before = $transaction->getOrder();
        $wasCompleted = (bool)$before?->isCompleted;
        $wasPaid = (bool)$before?->getIsPaid();

        if (!$commerce->getPayments()->completePayment($transaction, $error)) {
            throw new ApiException(
                ApiException::PAYMENT_FAILED,
                $error ?: Craft::t('headdy', 'The payment could not be completed.'),
                402,
                [],
                ['cancelUrl' => $transaction->getOrder()?->cancelUrl],
            );
        }

        $order = $transaction->getOrder();

        return $this->_result($order, $transaction, (string)$order?->returnUrl, [], $wasCompleted, $wasPaid);
    }

    /**
     * Whether a URL may be handed to a gateway as a return or cancel destination.
     *
     * A relative path is always fine — it can only ever land back on this site. An absolute URL has
     * to match an allowed origin exactly. Anything else is refused loudly rather than quietly
     * rewritten, because a checkout that returns to the wrong place is a support ticket either way
     * and an error at least says why.
     *
     * @throws ApiException
     */
    public function validateRedirect(?string $url, Order $order): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // Backslashes and newlines are how a header-splitting or scheme-confusion payload gets
        // past a naive parse; a legitimate URL never needs them.
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $url)) {
            throw new ApiException(
                ApiException::REDIRECT_NOT_ALLOWED,
                Craft::t('headdy', 'That return URL is not allowed.'),
                422,
            );
        }

        // A protocol-relative `//evil.example` is an absolute URL wearing a relative costume.
        if (!str_starts_with($url, '//') && !preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url)) {
            return UrlHelper::siteUrl($url, null, null, $order->orderSiteId);
        }

        $parts = parse_url(str_starts_with($url, '//') ? 'https:' . $url : $url);

        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            throw new ApiException(
                ApiException::REDIRECT_NOT_ALLOWED,
                Craft::t('headdy', 'That return URL is not allowed.'),
                422,
            );
        }

        $origin = strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $allowed = Plugin::getInstance()->getSettings()->getAllowedRedirectOrigins();

        foreach ($allowed as $candidate) {
            // `*` means any web origin, not any scheme: `javascript://x/%0a…` parses with a host
            // and would otherwise be handed to the front end as a URL to navigate to. A custom
            // app scheme still works when it is listed by name.
            $isWeb = in_array(strtolower($parts['scheme']), ['http', 'https'], true);

            if (strcasecmp($candidate, $origin) === 0 || ($candidate === '*' && $isWeb)) {
                return $url;
            }
        }

        throw new ApiException(
            ApiException::REDIRECT_NOT_ALLOWED,
            Craft::t('headdy', 'The return URL “{origin}” is not an allowed redirect origin. Add it in Headdy’s settings.', ['origin' => $origin]),
            422,
            [],
            ['allowedRedirectOrigins' => $allowed],
        );
    }

    // Internals
    // =========================================================================

    /**
     * @throws ApiException
     */
    private function _applyPaymentSelection(Order $order, array $params): void
    {
        $commerce = Commerce::getInstance();

        if (!empty($params['paymentCurrency']) && is_string($params['paymentCurrency'])) {
            try {
                $order->setPaymentCurrency($params['paymentCurrency']);
            } catch (CurrencyException $e) {
                throw ApiException::invalid($e->getMessage(), ['paymentCurrency' => [$e->getMessage()]]);
            }
        }

        if (!empty($params['gatewayId'])) {
            $gateway = $commerce->getGateways()->getGatewayById((int)$params['gatewayId']);

            if ($gateway === null || !$gateway->getIsFrontendEnabled()) {
                throw new ApiException(
                    ApiException::PAYMENT_GATEWAY_UNAVAILABLE,
                    Craft::t('headdy', 'That payment gateway is not available.'),
                    422,
                );
            }

            $order->setGatewayId((int)$params['gatewayId']);
        }

        if (!empty($params['paymentSourceId'])) {
            $source = $commerce->getPaymentSources()->getPaymentSourceById((int)$params['paymentSourceId']);

            if (!$this->_mayUsePaymentSource($order, $source)) {
                throw ApiException::forbidden(
                    Craft::t('headdy', 'That payment source cannot be used with this order.'),
                );
            }

            $order->setPaymentSource($source);
        } elseif ($order->paymentSourceId) {
            // A source chosen on an earlier attempt — one that was declined, or stopped by a 409 —
            // is still saved on the cart. Whoever is paying now has to pass the same test, or a
            // cart token alone would be enough to retry on the owner's card.
            try {
                $saved = $order->getPaymentSource();
            } catch (\Throwable) {
                // Commerce throws rather than answer for a guest, or for a source that no longer
                // belongs to the customer. Either way it is not one this caller may use.
                $saved = null;
            }

            if (!$this->_mayUsePaymentSource($order, $saved)) {
                $order->setPaymentSource(null);
            }
        }
    }

    /**
     * A saved card belongs to a person, and the only proof of who is calling is the customer
     * token. Without one, a stored source is off limits no matter whose cart this is — otherwise
     * anyone holding a cart token could charge someone else's card.
     */
    private function _mayUsePaymentSource(Order $order, ?PaymentSource $source): bool
    {
        $orderCustomerId = $order->getCustomer()?->id;
        $authenticated = Plugin::getInstance()->getRequestContext()->getCustomer();

        return $source !== null
            && $orderCustomerId !== null
            && $authenticated !== null
            && $authenticated->id === $orderCustomerId
            && $source->getCustomer()?->id === $orderCustomerId;
    }

    private function _buildPaymentForm(Order $order, \craft\commerce\base\GatewayInterface $gateway, array $params)
    {
        $paymentForm = $gateway->getPaymentFormModel();

        if ($order->paymentSourceId && $gateway->supportsPaymentSources()) {
            $paymentForm->populateFromPaymentSource($order->getPaymentSource());

            return $paymentForm;
        }

        $fields = $params['paymentForm'] ?? [];

        // Also accept the namespaced shape Commerce's own forms post, so an existing Twig
        // integration's payload can be forwarded straight through without rewriting it.
        if (!is_array($fields) || !$fields) {
            $namespaced = $params[PaymentFormHelper::getPaymentFormParamName($gateway->handle)] ?? null;
            $fields = is_array($namespaced) ? $namespaced : [];
        }

        if ($fields) {
            // `safeOnly: false` matches Commerce — a gateway's payment form model does not declare
            // validation rules for every field it needs, so a safe-only assign silently drops the
            // card number on several gateways.
            $paymentForm->setAttributes($fields, false);
        }

        return $paymentForm;
    }

    /**
     * @throws ApiException
     */
    private function _applyPartialPayment(Order $order, array $params): void
    {
        if (!isset($params['paymentAmount'])) {
            return;
        }

        if (!$order->getStore()->getAllowPartialPaymentOnCheckout()) {
            throw new ApiException(
                ApiException::PAYMENT_FAILED,
                Craft::t('headdy', 'Partial payment is not allowed on this store.'),
                422,
            );
        }

        $order->setPaymentAmount((float)$params['paymentAmount']);
    }

    /**
     * @param bool $wasCompleted Whether the order was already complete before this call
     * @param bool $wasPaid Whether it was already paid before this call
     */
    private function _result(?Order $order, ?Transaction $transaction, string $redirect, array $redirectData, bool $wasCompleted, bool $wasPaid): array
    {
        $plugin = Plugin::getInstance();
        $serializer = $plugin->getSerializer();

        if ($order === null) {
            throw ApiException::notFound(Craft::t('headdy', 'The order behind that payment is gone.'));
        }

        $currency = Serializer::currencyFor($order);

        if ($order->isCompleted) {
            // The cart token dies with the cart. A completed order is fetched by number and email,
            // not by a token a browser might still be holding.
            $plugin->getTokens()->revokeCartTokensForOrder((int)$order->id);

            // Only on the transition. Commerce's completePayment() answers true for a transaction
            // that already succeeded, so a replayed `complete-payment` would otherwise re-send
            // `order.paid` every time — and a receiver that fulfils on it ships again.
            if (!$wasCompleted) {
                $plugin->getWebhooks()->dispatch(Webhook::TOPIC_CART_COMPLETED, [
                    'order' => $serializer->cart($order, null, true),
                ], $order->storeId);
            }

            if (!$wasPaid && $order->getIsPaid()) {
                $plugin->getWebhooks()->dispatch(Webhook::TOPIC_ORDER_PAID, [
                    'order' => $serializer->cart($order, null, true),
                ], $order->storeId);
            }
        }

        return [
            'success' => true,
            'order' => $serializer->cart($order),
            'transaction' => $transaction !== null ? $serializer->transaction($transaction) : null,
            'amountPaid' => Money::format($order->getTotalPaid(), $currency),
            'outstandingBalance' => Money::format($order->getOutstandingBalance(), $currency),
            // Present and non-null means the gateway wants the customer sent somewhere. `data`
            // non-empty means it must be a POST, not a navigation.
            'redirect' => $redirect !== '' ? [
                'url' => $redirect,
                'method' => $redirectData ? 'POST' : 'GET',
                'data' => $redirectData,
            ] : null,
        ];
    }
}
