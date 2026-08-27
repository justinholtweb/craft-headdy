<?php

namespace justinholtweb\headdy\controllers\api;

use craft\web\Response;
use justinholtweb\headdy\errors\ApiException;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\Plugin;
use justinholtweb\headdy\web\ApiController;

/**
 * Customer accounts, order history and the address book.
 *
 * Pro only — the free tier covers anonymous carts and checkout, which is the whole storefront for a
 * guest-checkout store.
 */
class CustomersController extends ApiController
{
    protected function requiredScope(?string $actionId = null): ?string
    {
        return in_array($actionId, ['me', 'orders', 'order', 'addresses', 'payment-sources'], true)
            ? ApiKey::SCOPE_CUSTOMER_READ
            : ApiKey::SCOPE_CUSTOMER_WRITE;
    }

    /**
     * @throws ApiException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Checked after the base class so a Lite install still gets a well-formed error envelope
        // and its CORS headers, rather than a bare 403 the browser turns into a network failure.
        $this->requirePro();

        return true;
    }

    /**
     * `POST /customers/sessions` — sign in.
     */
    public function actionLogin(): Response
    {
        $loginName = (string)($this->param('loginName') ?? $this->param('email') ?? '');
        $password = (string)($this->param('password') ?? '');

        $result = Plugin::getInstance()->getCustomers()->login(
            $loginName,
            $password,
            Plugin::getInstance()->getRequestContext()->getKey(),
        );

        // Signing in mid-shop hands the basket over with the session, rather than stranding it.
        $cart = Plugin::getInstance()->getRequestContext()->getCart();

        if ($cart !== null) {
            $user = Plugin::getInstance()->getTokens()->getUserByCustomerToken($result['token']);

            if ($user !== null) {
                Plugin::getInstance()->getCustomers()->attachCartToCustomer($cart, $user);
                $result['cart'] = Plugin::getInstance()->getSerializer()->cart(
                    $cart,
                    Plugin::getInstance()->getRequestContext()->getCartToken(),
                );
            }
        }

        return $this->success($result);
    }

    /**
     * `POST /customers/sessions/refresh` — trade a refresh token for a new pair.
     */
    public function actionRefresh(): Response
    {
        $refreshToken = (string)($this->param('refreshToken') ?? '');

        $result = Plugin::getInstance()->getTokens()->refreshCustomerToken(
            $refreshToken,
            Plugin::getInstance()->getRequestContext()->getKey(),
        );

        if ($result === null) {
            throw ApiException::unauthorized(
                \Craft::t('headdy', 'That refresh token is not valid.'),
                ApiException::CUSTOMER_TOKEN_EXPIRED,
            );
        }

        return $this->success($result);
    }

    /**
     * `DELETE /customers/sessions` — sign out.
     */
    public function actionLogout(): Response
    {
        $token = $this->customerToken();

        if ($token !== null) {
            Plugin::getInstance()->getTokens()->revokeCustomerToken($token);
        }

        return $this->success(['loggedOut' => true]);
    }

    /**
     * `POST /customers` — register.
     */
    public function actionRegister(): Response
    {
        $result = Plugin::getInstance()->getCustomers()->register(
            $this->body(),
            Plugin::getInstance()->getRequestContext()->getKey(),
        );

        return $this->success($result, 201);
    }

    /**
     * `GET /customers/me`
     */
    public function actionMe(): Response
    {
        return $this->success([
            'customer' => Plugin::getInstance()->getCustomers()->profile($this->requireCustomer()),
        ]);
    }

    /**
     * `GET /customers/me/orders`
     */
    public function actionOrders(): Response
    {
        return $this->success(Plugin::getInstance()->getCustomers()->orders(
            $this->requireCustomer(),
            (int)$this->request->getQueryParam('page', 1),
            (int)$this->request->getQueryParam('pageSize', 20),
        ));
    }

    /**
     * `GET /customers/me/orders/<number>`
     */
    public function actionOrder(string $number): Response
    {
        return $this->success([
            'order' => Plugin::getInstance()->getCustomers()->order($this->requireCustomer(), $number),
        ]);
    }

    /**
     * `GET /orders/lookup?number=…&email=…` — a guest checking on a purchase.
     */
    public function actionLookup(): Response
    {
        $number = (string)($this->param('number') ?? '');
        $email = (string)($this->param('email') ?? '');

        return $this->success([
            'order' => Plugin::getInstance()->getCustomers()->guestOrder($number, $email),
        ]);
    }

    /**
     * `GET /customers/me/addresses`
     */
    public function actionAddresses(): Response
    {
        $customer = $this->requireCustomer();
        $serializer = Plugin::getInstance()->getSerializer();

        return $this->success([
            'addresses' => array_map(
                fn($address) => $serializer->address($address),
                $customer->getAddresses()->all(),
            ),
        ]);
    }

    /**
     * `POST /customers/me/addresses`
     */
    public function actionSaveAddress(?int $addressId = null): Response
    {
        return $this->success([
            'address' => Plugin::getInstance()->getCustomers()->saveAddress(
                $this->requireCustomer(),
                $this->body(),
                $addressId,
            ),
        ], $addressId === null ? 201 : 200);
    }

    /**
     * `DELETE /customers/me/addresses/<addressId>`
     */
    public function actionDeleteAddress(int $addressId): Response
    {
        Plugin::getInstance()->getCustomers()->deleteAddress($this->requireCustomer(), $addressId);

        return $this->success(['deleted' => true]);
    }

    /**
     * `GET /customers/me/payment-sources`
     */
    public function actionPaymentSources(): Response
    {
        return $this->success([
            'paymentSources' => Plugin::getInstance()->getCustomers()->paymentSources($this->requireCustomer()),
        ]);
    }
}
