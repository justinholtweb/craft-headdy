<?php

namespace justinholtweb\headdy\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\Address;
use craft\elements\User;
use craft\enums\CmsEdition;
use justinholtweb\headdy\errors\ApiException;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\Plugin;
use yii\base\Component;

/**
 * Customer accounts, over tokens.
 *
 * Craft authenticates with a session cookie, which a cross-origin front end cannot use without
 * `credentials: 'include'`, a matching `SameSite` policy and a CSRF dance. This service issues a
 * bearer token pair instead — and, importantly, **never logs the user into Craft**. There is no
 * `$userSession->login()` anywhere in this plugin. A token identifies a customer to Headdy's own
 * endpoints and nothing more: it cannot be replayed against the control panel, and it carries no
 * permissions beyond reading and writing that customer's own carts, addresses and orders.
 */
class Customers extends Component
{
    /**
     * Exchanges credentials for a token pair.
     *
     * @return array{token: string, refreshToken: string, expiresIn: int, refreshExpiresIn: int, customer: array}
     * @throws ApiException
     */
    public function login(string $loginName, string $password, ?ApiKey $key = null): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->allowCustomerLogin) {
            throw ApiException::forbidden(
                Craft::t('headdy', 'Customer login is turned off.'),
                ApiException::CUSTOMER_LOGIN_FAILED,
            );
        }

        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($loginName);

        // One message and one code whether the account is missing, the password is wrong or the
        // account is suspended. Distinguishing them turns this endpoint into a way to enumerate
        // which email addresses have accounts.
        $failure = fn() => ApiException::unauthorized(
            Craft::t('headdy', 'Invalid credentials.'),
            ApiException::CUSTOMER_LOGIN_FAILED,
        );

        // A control-panel account is not a storefront account. Handing out an API token for one
        // means a leaked storefront token is a leaked admin identity somewhere down the line.
        //
        // It is refused without its password ever being checked. Checking it would count towards
        // Craft's lockout — so anyone could lock the site's admins out from here — or, checked
        // quietly, would answer "right password" with a different response, which is a lockout-free
        // way to guess an admin's password. So it fails exactly as a wrong guess does.
        if ($user === null || $user->password === null || $user->admin || $user->can('accessCp')) {
            // Pay the cost of a password check anyway. Failing fast for an unknown account would
            // tell a stopwatch which addresses are registered.
            Craft::$app->getSecurity()->hashPassword($password !== '' ? $password : 'x');

            throw $failure();
        }

        // Through Craft's own authentication, not a bare password check: that is what counts
        // failed attempts and locks the account after `maxInvalidLogins`. A bare validatePassword()
        // would let the storefront API guess passwords without ever tripping the lockout the
        // control panel login has.
        if (!$user->authenticate($password)) {
            throw $failure();
        }

        if ($user->suspended || $user->getStatus() !== User::STATUS_ACTIVE || $user->getIsCredentialed() === false) {
            throw $failure();
        }

        // Resets the failed-attempt count, as a successful control panel sign-in does.
        Craft::$app->getUsers()->handleValidLogin($user);

        $tokens = Plugin::getInstance()->getTokens()->issueCustomerToken($user, $key);
        $tokens['customer'] = $this->profile($user);

        return $tokens;
    }

    /**
     * Whether a new account must prove its email address before it can sign in — Craft's own
     * rule, the one its front-end registration follows.
     */
    public function requiresEmailVerification(): bool
    {
        return Craft::$app->edition->value >= CmsEdition::Pro->value
            && (bool)(Craft::$app->getProjectConfig()->get('users.requireEmailVerification') ?? true);
    }

    /**
     * Creates an account.
     *
     * When Craft requires email verification (its default), the account is created **pending**,
     * Craft's activation email is sent, and no tokens are issued — the response says
     * `verificationRequired`. Activating on the spot and handing out tokens would let anyone
     * register someone else's address, wait for that person to check out as a guest (Commerce
     * files the order under the existing account), and read the order back.
     *
     * @return array{verificationRequired: bool, token?: string, refreshToken?: string, expiresIn?: int, refreshExpiresIn?: int, customer?: array}
     * @throws ApiException
     */
    public function register(array $params, ?ApiKey $key = null): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->allowCustomerRegistration) {
            throw ApiException::forbidden(
                Craft::t('headdy', 'Customer registration is turned off.'),
                ApiException::CUSTOMER_REGISTRATION_DISABLED,
            );
        }

        $email = trim((string)($params['email'] ?? ''));
        $password = (string)($params['password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiException::invalid(
                Craft::t('headdy', 'A valid email address is required.'),
                ['email' => [Craft::t('headdy', 'A valid email address is required.')]],
            );
        }

        $existing = Craft::$app->getUsers()->getUserByUsernameOrEmail($email);

        // An account created by `ensureUserByEmail()` during a guest checkout has no password yet.
        // That is a real account waiting to be claimed, not a duplicate — but claiming it here
        // without proof of the address would hand over that person's order history.
        if ($existing !== null) {
            throw new ApiException(
                ApiException::CUSTOMER_EXISTS,
                Craft::t('headdy', 'An account already exists for that email address.'),
                409,
            );
        }

        $verify = $this->requiresEmailVerification();

        $user = new User();
        $user->email = $email;
        $user->username = $email;
        $user->firstName = is_scalar($params['firstName'] ?? null) ? (string)$params['firstName'] : null;
        $user->lastName = is_scalar($params['lastName'] ?? null) ? (string)$params['lastName'] : null;
        $user->pending = $verify;

        // Without a password, a verified account gets Craft's "set your password" link instead.
        // An unverified one needs a password now, or nobody could ever sign in to it.
        if ($password !== '') {
            $user->newPassword = $password;
        } elseif (!$verify) {
            throw ApiException::invalid(
                Craft::t('headdy', 'A password is required.'),
                ['password' => [Craft::t('headdy', 'A password is required.')]],
            );
        }

        // Only the custom fields the merchant has opened to registration. Anything else on the
        // user — an approval flag, a membership tier — would otherwise be settable by whoever
        // registers.
        $fields = is_array($params['fields'] ?? null)
            ? array_intersect_key($params['fields'], array_flip(Plugin::getInstance()->getSettings()->getRegistrationFields()))
            : [];

        if ($fields !== []) {
            $user->setFieldValues($fields);
        }

        if (!Craft::$app->getElements()->saveElement($user)) {
            throw ApiException::invalid(
                Craft::t('headdy', 'Could not create that account.'),
                Plugin::getInstance()->getSerializer()->errors($user),
            );
        }

        if ($verify) {
            try {
                Craft::$app->getUsers()->sendActivationEmail($user);
            } catch (\Throwable $e) {
                Craft::error('Headdy could not send an activation email: ' . $e->getMessage(), 'headdy');
            }

            return ['verificationRequired' => true];
        }

        Craft::$app->getUsers()->activateUser($user);

        $tokens = Plugin::getInstance()->getTokens()->issueCustomerToken($user, $key);
        $tokens['customer'] = $this->profile($user);

        return ['verificationRequired' => false] + $tokens;
    }

    /**
     * The signed-in customer's profile.
     */
    public function profile(User $user): array
    {
        $serializer = Plugin::getInstance()->getSerializer();

        return [
            'id' => $user->id,
            'uid' => $user->uid,
            'email' => $user->email,
            'username' => $user->username,
            'fullName' => $user->fullName,
            'firstName' => $user->firstName,
            'lastName' => $user->lastName,
            'primaryBillingAddressId' => $user->primaryBillingAddressId,
            'primaryShippingAddressId' => $user->primaryShippingAddressId,
            'addresses' => array_map(
                fn(Address $address) => $serializer->address($address),
                // An ElementCollection, not an array — array_map() on it silently returns nothing.
                $user->getAddresses()->all(),
            ),
        ];
    }

    /**
     * The customer's completed orders, newest first.
     */
    public function orders(User $user, int $page = 1, int $pageSize = 20): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $serializer = Plugin::getInstance()->getSerializer();

        $page = max(1, $page);
        $pageSize = max(1, min($pageSize, $settings->maxPageSize));

        $query = Order::find()
            ->customer($user)
            ->isCompleted(true)
            ->orderBy(['dateOrdered' => SORT_DESC])
            ->limit($pageSize)
            ->offset(($page - 1) * $pageSize);

        $total = (int)(clone $query)->limit(null)->offset(null)->count();

        return [
            'orders' => array_map(fn(Order $order) => $serializer->cart($order), $query->all()),
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => (int)ceil($total / $pageSize),
            ],
        ];
    }

    /**
     * One of the customer's own orders.
     *
     * Scoped by customer ID rather than looked up by number and then checked, so there is no window
     * in which the wrong order has been loaded.
     *
     * @throws ApiException
     */
    public function order(User $user, string $number): array
    {
        $order = Order::find()
            ->customer($user)
            ->number($number)
            ->isCompleted(true)
            ->one();

        if (!$order instanceof Order) {
            throw ApiException::notFound(Craft::t('headdy', 'No order found.'));
        }

        return Plugin::getInstance()->getSerializer()->cart($order);
    }

    /**
     * Looks up a completed order by number and email, for a guest checking on a purchase.
     *
     * The email match is the whole authorisation here, so it is constant-time and the failure is
     * indistinguishable from "no such order" — otherwise the endpoint enumerates order numbers.
     *
     * @throws ApiException
     */
    public function guestOrder(string $number, string $email): array
    {
        $order = Order::find()->number($number)->isCompleted(true)->one();
        $notFound = fn() => ApiException::notFound(Craft::t('headdy', 'No order found.'));

        if (!$order instanceof Order) {
            throw $notFound();
        }

        if (!hash_equals(strtolower((string)$order->getEmail()), strtolower($email))) {
            throw $notFound();
        }

        return Plugin::getInstance()->getSerializer()->cart($order);
    }

    /**
     * Saves an address to the customer's address book.
     *
     * @throws ApiException
     */
    public function saveAddress(User $user, array $params, ?int $addressId = null): array
    {
        if ($addressId !== null) {
            $address = $this->_ownedAddress($user, $addressId);
        } else {
            $address = new Address();
            $address->ownerId = $user->id;
        }

        $allowed = [
            'title', 'fullName', 'firstName', 'lastName', 'organization', 'organizationTaxId',
            'addressLine1', 'addressLine2', 'addressLine3',
            'locality', 'dependentLocality', 'administrativeArea',
            'postalCode', 'sortingCode', 'countryCode',
        ];

        if (isset($params['label'])) {
            $params['title'] = $params['label'];
        }

        $address->setAttributes(array_intersect_key($params, array_flip($allowed)), false);

        if (!empty($params['fields']) && is_array($params['fields'])) {
            $address->setFieldValues($params['fields']);
        }

        if (!Craft::$app->getElements()->saveElement($address)) {
            throw ApiException::invalid(
                Craft::t('headdy', 'Could not save that address.'),
                Plugin::getInstance()->getSerializer()->errors($address),
            );
        }

        // Through Commerce's own service rather than by assigning the behavior property and
        // saving the user: the ID lives in a Commerce table, not on the user element, so a plain
        // element save writes nothing.
        if (!empty($params['makePrimaryBilling'])) {
            Commerce::getInstance()->getCustomers()->savePrimaryBillingAddressId($user, $address->id);
        }

        if (!empty($params['makePrimaryShipping'])) {
            Commerce::getInstance()->getCustomers()->savePrimaryShippingAddressId($user, $address->id);
        }

        return Plugin::getInstance()->getSerializer()->address($address);
    }

    /**
     * @throws ApiException
     */
    public function deleteAddress(User $user, int $addressId): void
    {
        Craft::$app->getElements()->deleteElement($this->_ownedAddress($user, $addressId));
    }

    /**
     * An address the customer actually owns.
     *
     * Fetched by ID and then checked, rather than filtered in the query: Craft's `AddressQuery` has
     * no owner param, and a query that silently ignores an unknown condition would return every
     * address on the site.
     *
     * @throws ApiException
     */
    private function _ownedAddress(User $user, int $addressId): Address
    {
        $address = Address::find()->id($addressId)->status(null)->one();

        if (!$address instanceof Address || (int)$address->ownerId !== (int)$user->id) {
            throw ApiException::notFound(Craft::t('headdy', 'No address found.'));
        }

        return $address;
    }

    /**
     * The customer's saved payment sources.
     */
    public function paymentSources(User $user): array
    {
        $out = [];

        foreach (Commerce::getInstance()->getPaymentSources()->getAllPaymentSourcesByCustomerId($user->id) as $source) {
            $out[] = [
                'id' => $source->id,
                'gatewayId' => $source->gatewayId,
                'description' => $source->description,
                'isPrimary' => $user->primaryPaymentSourceId === $source->id,
            ];
        }

        return $out;
    }

    /**
     * Claims the guest carts belonging to a customer's email address after they sign in.
     *
     * Without this, signing in mid-shop silently abandons whatever is in the basket — the single
     * most common complaint about hand-rolled headless Commerce checkouts.
     */
    public function attachCartToCustomer(Order $cart, User $user): void
    {
        $existing = $cart->getCustomer();

        if ($existing !== null && $existing->id === $user->id) {
            return;
        }

        $cart->setCustomer($user);
        Craft::$app->getElements()->saveElement($cart, false, false, false);
    }
}
