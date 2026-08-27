<?php

namespace justinholtweb\headdy\models;

use craft\base\Model;
use craft\helpers\StringHelper;
use DateTime;

/**
 * An API key.
 *
 * A key has two halves. The public half identifies the caller and is safe to compile into browser
 * JavaScript. The secret half authenticates it and is shown exactly once, at creation: only its
 * hash is stored, so nobody — including an attacker with the database — can read it back.
 */
class ApiKey extends Model
{
    public const SCOPE_CATALOG_READ = 'catalog:read';
    public const SCOPE_CART_READ = 'cart:read';
    public const SCOPE_CART_WRITE = 'cart:write';
    public const SCOPE_CHECKOUT = 'checkout';
    public const SCOPE_PAYMENT = 'payment';
    public const SCOPE_CUSTOMER_READ = 'customer:read';
    public const SCOPE_CUSTOMER_WRITE = 'customer:write';

    public const PUBLIC_PREFIX = 'hd_pk_';
    public const SECRET_PREFIX = 'hd_sk_';

    public ?int $id = null;
    public string $name = '';
    public string $publicKey = '';
    public ?string $secretHash = null;
    public array $scopes = [];
    public array $origins = [];
    public ?int $storeId = null;
    public ?int $rateLimit = null;
    public bool $enabled = true;
    public ?DateTime $lastUsedAt = null;
    public ?DateTime $expiryDate = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @var string|null The plaintext secret. Populated only on the request that created the key —
     *                  never read back out of the database, because it is not stored.
     */
    public ?string $secret = null;

    /**
     * Every scope, in the order the settings screen shows them.
     *
     * @return string[]
     */
    public static function allScopes(): array
    {
        return [
            self::SCOPE_CATALOG_READ,
            self::SCOPE_CART_READ,
            self::SCOPE_CART_WRITE,
            self::SCOPE_CHECKOUT,
            self::SCOPE_PAYMENT,
            self::SCOPE_CUSTOMER_READ,
            self::SCOPE_CUSTOMER_WRITE,
        ];
    }

    /**
     * The scopes a brand new key gets: enough to run a storefront, short of touching accounts.
     *
     * @return string[]
     */
    public static function defaultScopes(): array
    {
        return [
            self::SCOPE_CATALOG_READ,
            self::SCOPE_CART_READ,
            self::SCOPE_CART_WRITE,
            self::SCOPE_CHECKOUT,
            self::SCOPE_PAYMENT,
        ];
    }

    public static function generatePublicKey(): string
    {
        return self::PUBLIC_PREFIX . StringHelper::randomString(32);
    }

    public static function generateSecret(): string
    {
        return self::SECRET_PREFIX . StringHelper::randomString(48);
    }

    public function rules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['publicKey'], 'string', 'max' => 64],
            [['rateLimit'], 'integer', 'min' => 0],
            [['scopes'], 'validateScopes'],
        ];
    }

    public function validateScopes(string $attribute): void
    {
        foreach ($this->scopes as $scope) {
            if (!in_array($scope, self::allScopes(), true)) {
                $this->addError($attribute, "Unknown scope “{$scope}”.");
            }
        }
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function isExpired(): bool
    {
        return $this->expiryDate !== null && $this->expiryDate->getTimestamp() <= time();
    }

    public function isUsable(): bool
    {
        return $this->enabled && !$this->isExpired();
    }

    /**
     * Whether this key restricts itself to particular browser origins, over and above the
     * plugin-wide list.
     */
    public function allowsOrigin(string $origin): bool
    {
        if (!$this->origins) {
            return true;
        }

        return in_array('*', $this->origins, true) || in_array(rtrim($origin, '/'), $this->origins, true);
    }
}
