<?php

namespace justinholtweb\headdy\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use justinholtweb\headdy\db\Table;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\Plugin;
use justinholtweb\headdy\records\CartTokenRecord;
use justinholtweb\headdy\records\CustomerTokenRecord;
use yii\base\Component;

/**
 * Cart and customer tokens.
 *
 * ## Why tokens at all
 *
 * Commerce identifies a cart by a cookie holding the order number, and `CartController` will also
 * accept that number as a POST param. Either way the number *is* the credential — and it is also
 * printed on the order, quoted in emails and shown in the control panel. A headless front end
 * needs a credential it can hold in memory across origins, rotate on checkout and revoke; the
 * order number is none of those things.
 *
 * ## The invariant
 *
 * A token is generated once, returned once, and stored **only** as a SHA-256 hash. Lookup is by
 * hash against a unique index, so a stolen database gives an attacker no usable tokens and the
 * lookup stays a single indexed read rather than a scan.
 */
class Tokens extends Component
{
    public const CART_PREFIX = 'hdc_';
    public const CUSTOMER_PREFIX = 'hda_';
    public const REFRESH_PREFIX = 'hdr_';

    /**
     * Hashes a token for storage and lookup.
     *
     * SHA-256 rather than a password hash on purpose: these are 32 bytes of CSPRNG output, not
     * user-chosen secrets, so there is nothing to brute force and an indexed equality lookup is
     * required. `password_verify()` cannot be an index.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    // Cart tokens
    // =========================================================================

    /**
     * Issues a token for a cart, replacing any token that cart already had.
     *
     * One live token per cart: two tokens for one cart means revoking the leaked one still leaves
     * the cart reachable, which is not a revocation.
     */
    public function issueCartToken(Order $cart, ?ApiKey $key = null): string
    {
        if (!$cart->id) {
            throw new \InvalidArgumentException('Cannot issue a token for an unsaved cart.');
        }

        $this->revokeCartTokensForOrder($cart->id);

        $token = self::CART_PREFIX . StringHelper::randomString(48);

        $record = new CartTokenRecord();
        $record->tokenHash = self::hash($token);
        $record->orderId = $cart->id;
        $record->keyId = $key?->id;
        $record->storeId = $cart->storeId;
        $record->siteId = $cart->orderSiteId ?? $cart->siteId;
        $record->expiryDate = Db::prepareDateForDb($this->_cartExpiry());
        $record->lastUsedAt = Db::prepareDateForDb(new DateTime());
        $record->save(false);

        return $token;
    }

    /**
     * Resolves a cart token to its cart, or null.
     *
     * Returns null for an unknown token, an expired token, a token whose cart has been completed
     * and a token whose cart has vanished. The caller turns that into an error code; this method
     * deliberately does not distinguish "wrong" from "expired" to a *lookup*, because the
     * distinction is only safe to make after the row is found.
     */
    public function getCartByToken(string $token): ?Order
    {
        $record = $this->getCartTokenRecord($token);

        if ($record === null) {
            return null;
        }

        $cart = Order::find()
            ->id((int)$record->orderId)
            ->isCompleted(false)
            ->status(null)
            ->one();

        return $cart instanceof Order ? $cart : null;
    }

    /**
     * The token row for a token, if it exists and has not expired.
     */
    public function getCartTokenRecord(string $token): ?CartTokenRecord
    {
        if ($token === '') {
            return null;
        }

        $record = CartTokenRecord::findOne(['tokenHash' => self::hash($token)]);

        if ($record === null) {
            return null;
        }

        // The `false` is load-bearing: it means "assume UTC", which is what the column holds.
        // `true` would assume the *system* time zone and read a bare UTC column as local time,
        // shifting every expiry by the site's offset — west of UTC that keeps expired tokens
        // working for hours.
        $expiry = DateTimeHelper::toDateTime($record->expiryDate, false);

        if ($expiry === false || $expiry->getTimestamp() <= time()) {
            return null;
        }

        return $record;
    }

    /**
     * Whether the token exists at all, expired or not. Lets the API answer
     * `cart_token_expired` instead of a flat `cart_not_found`, which is the difference between a
     * front end that starts a new cart and one that shows an error.
     */
    public function cartTokenExists(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return CartTokenRecord::find()->where(['tokenHash' => self::hash($token)])->exists();
    }

    /**
     * Pushes a token's expiry back out, if sliding expiry is on.
     */
    public function touchCartToken(CartTokenRecord $record): void
    {
        $settings = Plugin::getInstance()->getSettings();

        $values = ['lastUsedAt' => Db::prepareDateForDb(new DateTime())];

        if ($settings->slidingCartTokens) {
            $values['expiryDate'] = Db::prepareDateForDb($this->_cartExpiry());
        }

        Craft::$app->getDb()->createCommand()
            ->update(Table::CART_TOKENS, $values, ['id' => $record->id])
            ->execute();
    }

    public function revokeCartToken(string $token): bool
    {
        return (bool)Craft::$app->getDb()->createCommand()
            ->delete(Table::CART_TOKENS, ['tokenHash' => self::hash($token)])
            ->execute();
    }

    public function revokeCartTokensForOrder(int $orderId): int
    {
        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::CART_TOKENS, ['orderId' => $orderId])
            ->execute();
    }

    // Customer tokens
    // =========================================================================

    /**
     * Issues an access token and a refresh token for a user.
     *
     * @return array{token: string, refreshToken: string, expiresIn: int, refreshExpiresIn: int}
     */
    public function issueCustomerToken(User $user, ?ApiKey $key = null): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $token = self::CUSTOMER_PREFIX . StringHelper::randomString(48);
        $refresh = self::REFRESH_PREFIX . StringHelper::randomString(48);

        $record = new CustomerTokenRecord();
        $record->tokenHash = self::hash($token);
        $record->refreshHash = self::hash($refresh);
        $record->userId = $user->id;
        $record->keyId = $key?->id;
        $record->expiryDate = Db::prepareDateForDb($this->_in($settings->customerTokenDuration));
        $record->refreshExpiryDate = Db::prepareDateForDb($this->_in($settings->customerRefreshDuration));
        $record->revoked = false;
        $record->lastUsedAt = Db::prepareDateForDb(new DateTime());
        $record->save(false);

        return [
            'token' => $token,
            'refreshToken' => $refresh,
            'expiresIn' => $settings->customerTokenDuration,
            'refreshExpiresIn' => $settings->customerRefreshDuration,
        ];
    }

    /**
     * Resolves an access token to its user, or null.
     */
    public function getUserByCustomerToken(string $token): ?User
    {
        $record = $this->getCustomerTokenRecord($token);

        if ($record === null) {
            return null;
        }

        $user = Craft::$app->getUsers()->getUserById((int)$record->userId);

        return $user !== null && $this->_mayStillActAs($user, $record) ? $user : null;
    }

    public function getCustomerTokenRecord(string $token): ?CustomerTokenRecord
    {
        if ($token === '') {
            return null;
        }

        $record = CustomerTokenRecord::findOne(['tokenHash' => self::hash($token)]);

        if ($record === null || $record->revoked) {
            return null;
        }

        $expiry = DateTimeHelper::toDateTime($record->expiryDate, false);

        if ($expiry === false || $expiry->getTimestamp() <= time()) {
            return null;
        }

        return $record;
    }

    /**
     * Exchanges a refresh token for a new pair, and burns the old one.
     *
     * Rotation is not optional: a refresh token that survives its own use is a permanent
     * credential sitting in a browser.
     *
     * @return array{token: string, refreshToken: string, expiresIn: int, refreshExpiresIn: int}|null
     */
    public function refreshCustomerToken(string $refreshToken, ?ApiKey $key = null): ?array
    {
        if ($refreshToken === '') {
            return null;
        }

        $record = CustomerTokenRecord::findOne(['refreshHash' => self::hash($refreshToken)]);

        if ($record === null || $record->revoked) {
            return null;
        }

        $expiry = DateTimeHelper::toDateTime($record->refreshExpiryDate, false);

        if ($expiry === false || $expiry->getTimestamp() <= time()) {
            return null;
        }

        $user = Craft::$app->getUsers()->getUserById((int)$record->userId);

        if ($user === null || !$this->_mayStillActAs($user, $record)) {
            return null;
        }

        $record->delete();

        return $this->issueCustomerToken($user, $key);
    }

    /**
     * Whether a token row still speaks for its user.
     *
     * A suspended or deactivated account keeps its rows but must stop being an identity. So must
     * one whose password has changed since the token was issued — a reset is what a customer does
     * when they think someone else is in, and a stolen refresh token would otherwise rotate
     * forever. Comparing against `lastPasswordChangeDate` covers every route to a new password —
     * the control panel, a reset email, a front-end form — without listening for each one.
     */
    private function _mayStillActAs(User $user, CustomerTokenRecord $record): bool
    {
        if ($user->suspended || $user->getStatus() !== User::STATUS_ACTIVE || $user->passwordResetRequired) {
            return false;
        }

        // Read from the table, not the element: a user loaded through UserQuery comes back with
        // `lastPasswordChangeDate` unset, even when the column holds a date.
        $changed = (new Query())
            ->select(['lastPasswordChangeDate'])
            ->from(CraftTable::USERS)
            ->where(['id' => $user->id])
            ->scalar();

        $changedAt = $changed ? DateTimeHelper::toDateTime($changed, false) : false;
        $issued = DateTimeHelper::toDateTime($record->dateCreated, false);

        return $changedAt === false
            || $issued === false
            || $issued >= $changedAt;
    }

    public function revokeCustomerToken(string $token): bool
    {
        return (bool)Craft::$app->getDb()->createCommand()
            ->delete(Table::CUSTOMER_TOKENS, ['tokenHash' => self::hash($token)])
            ->execute();
    }

    public function revokeCustomerTokensForUser(int $userId): int
    {
        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::CUSTOMER_TOKENS, ['userId' => $userId])
            ->execute();
    }

    // Housekeeping
    // =========================================================================

    /**
     * Deletes expired tokens. Cart tokens outlive their expiry by a grace period so that a client
     * that reconnects a few minutes late gets `cart_token_expired` — which tells it to start a new
     * cart — rather than a bare `cart_not_found`.
     *
     * @return array{carts: int, customers: int}
     */
    public function purgeExpired(int $graceSeconds = 86400): array
    {
        $db = Craft::$app->getDb();
        $cartCutoff = Db::prepareDateForDb((new DateTime())->sub(new DateInterval('PT' . max(0, $graceSeconds) . 'S')));
        $now = Db::prepareDateForDb(new DateTime());

        $carts = (int)$db->createCommand()
            ->delete(Table::CART_TOKENS, ['<', 'expiryDate', $cartCutoff])
            ->execute();

        // A row is dead once nothing in it can be used. The access token expiring is not that: the
        // refresh token in the same row lives on for weeks, and deleting the row with the access
        // token would sign out every customer idle for more than an hour.
        $customers = (int)$db->createCommand()
            ->delete(Table::CUSTOMER_TOKENS, [
                'or',
                ['and', ['refreshExpiryDate' => null], ['<', 'expiryDate', $now]],
                ['<', 'refreshExpiryDate', $now],
                ['revoked' => true],
            ])
            ->execute();

        return ['carts' => $carts, 'customers' => $customers];
    }

    public function countCartTokens(): int
    {
        return (int)CartTokenRecord::find()->count();
    }

    public function countCustomerTokens(): int
    {
        return (int)CustomerTokenRecord::find()->count();
    }

    private function _cartExpiry(): DateTime
    {
        return $this->_in(Plugin::getInstance()->getSettings()->cartTokenDuration);
    }

    private function _in(int $seconds): DateTime
    {
        return (new DateTime())->add(new DateInterval('PT' . max(60, $seconds) . 'S'));
    }
}
