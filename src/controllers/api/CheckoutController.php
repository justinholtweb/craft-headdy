<?php

namespace justinholtweb\headdy\controllers\api;

use craft\web\Response;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\Plugin;
use justinholtweb\headdy\web\ApiController;

/**
 * Checkout and payment.
 */
class CheckoutController extends ApiController
{
    protected function requiredScope(?string $actionId = null): ?string
    {
        return in_array($actionId, ['pay', 'complete-payment'], true)
            ? ApiKey::SCOPE_PAYMENT
            : ApiKey::SCOPE_CHECKOUT;
    }

    /**
     * `GET /checkout` — everything a checkout UI needs to render itself.
     */
    public function actionState(): Response
    {
        $cart = $this->requireCart();

        return $this->success(['checkout' => Plugin::getInstance()->getCheckout()->state($cart)]);
    }

    /**
     * `POST /checkout/pay` — takes payment.
     *
     * On success the response carries the completed order and, for an off-site gateway, a
     * `redirect` object. `redirect.method` is `POST` when the gateway needs a form submission
     * rather than a navigation — the client must honour it, or the gateways that sign a POST body
     * will reject the customer.
     */
    public function actionPay(): Response
    {
        $cart = $this->requireCart();
        $body = $this->body();

        $params = array_intersect_key($body, array_flip([
            'gatewayId', 'paymentSourceId', 'paymentForm', 'returnUrl', 'cancelUrl',
            'paymentAmount', 'paymentCurrency',
        ]));

        // A gateway's own namespaced payment form key, forwarded as-is, so an existing Twig
        // integration's body works unchanged.
        foreach ($body as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'paymentForm') && !isset($params[$key])) {
                $params[$key] = $value;
            }
        }

        return $this->success(Plugin::getInstance()->getPayments()->pay($cart, $params));
    }

    /**
     * `POST /checkout/complete-payment` — the callback leg of an off-site payment.
     *
     * Takes no cart token: by this point the cart is an order and its token has been revoked. The
     * transaction hash is the credential, and it is single-use by Commerce's own accounting.
     */
    public function actionCompletePayment(): Response
    {
        $hash = (string)($this->param('commerceTransactionHash') ?? $this->param('transactionHash') ?? '');

        return $this->success(Plugin::getInstance()->getPayments()->complete($hash));
    }

    /**
     * `POST /checkout/complete` — completes an order that needs no payment.
     */
    public function actionComplete(): Response
    {
        $plugin = Plugin::getInstance();
        $cart = $this->requireCart();
        $order = $plugin->getCheckout()->complete($cart);

        $plugin->getWebhooks()->dispatch(\justinholtweb\headdy\models\Webhook::TOPIC_CART_COMPLETED, [
            'order' => $plugin->getSerializer()->cart($order),
        ], $order->storeId);

        return $this->success(['order' => $plugin->getSerializer()->cart($order)]);
    }
}
