<?php

namespace justinholtweb\headdy\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;

/**
 * Headdy's plugin settings.
 *
 * None of these are marked `required`: a required plugin setting makes the settings screen
 * unsaveable on a fresh install before the merchant has had a chance to fill anything in.
 */
class Settings extends Model
{
    public const AUTH_MODE_OPEN = 'open';
    public const AUTH_MODE_PUBLIC_KEY = 'publicKey';
    public const AUTH_MODE_SECRET = 'secret';

    /**
     * @var bool Whether the storefront API answers requests at all.
     */
    public bool $enabled = true;

    /**
     * @var string The URI segment the API is mounted at, with no leading or trailing slash.
     */
    public string $basePath = 'api/storefront';

    /**
     * @var string How callers identify themselves.
     *
     * - `open`: no key at all. Fine behind a private network, reckless on the internet.
     * - `publicKey`: an `X-Headdy-Key` header matched against a key's public half. Safe to ship in
     *   browser JavaScript; it identifies the caller and scopes them, it does not authenticate them.
     * - `secret`: the public half plus a secret half. For server-side callers only — a secret in a
     *   bundled JS file is not a secret.
     */
    public string $authMode = self::AUTH_MODE_PUBLIC_KEY;

    /**
     * @var string[] Origins allowed to call the API from a browser. `*` allows any origin, which
     *               is only safe while `authMode` is not `secret`.
     */
    public array $allowedOrigins = [];

    /**
     * @var bool Whether to send `Access-Control-Allow-Credentials`. Off by default and mutually
     *           exclusive with a `*` origin — Headdy authenticates with a bearer token precisely so
     *           a headless front end never has to send cookies cross-site.
     */
    public bool $allowCredentials = false;

    /**
     * @var int How long a cart token is good for, in seconds. Default 30 days.
     */
    public int $cartTokenDuration = 2592000;

    /**
     * @var bool Whether using a cart token pushes its expiry back out to the full duration.
     */
    public bool $slidingCartTokens = true;

    /**
     * @var int How long a customer access token is good for, in seconds. Default one hour.
     */
    public int $customerTokenDuration = 3600;

    /**
     * @var int How long a customer refresh token is good for, in seconds. Default 30 days.
     */
    public int $customerRefreshDuration = 2592000;

    /**
     * @var bool Whether `POST /customers/sessions` is available at all.
     */
    public bool $allowCustomerLogin = true;

    /**
     * @var bool Whether `POST /customers` may register new users.
     */
    public bool $allowCustomerRegistration = false;

    /**
     * @var string[] URL patterns a caller may hand to a gateway as a return or cancel URL.
     *
     * Craft's own checkout hashes these into the form; a JSON client cannot produce that hash, so
     * Headdy validates them against this list instead. An empty list means off-site gateways
     * cannot be driven from the API — which is the safe default, not an oversight.
     */
    public array $allowedRedirectOrigins = [];

    /**
     * @var int Requests per minute per key, or 0 for no limit.
     */
    public int $rateLimit = 0;

    /**
     * @var bool Whether requests are written to the log.
     */
    public bool $logRequests = true;

    /**
     * @var bool Whether the log also keeps request and response bodies.
     */
    public bool $logBodies = false;

    /**
     * @var int Days of log to keep. 0 keeps everything.
     */
    public int $logRetentionDays = 30;

    /**
     * @var bool Whether the GraphQL cart mutations are registered.
     */
    public bool $graphqlEnabled = true;

    /**
     * @var bool Whether catalog endpoints are served.
     */
    public bool $catalogEnabled = true;

    /**
     * @var int Default page size for collection endpoints.
     */
    public int $defaultPageSize = 24;

    /**
     * @var int Largest page size a caller may ask for.
     */
    public int $maxPageSize = 100;

    /**
     * @var bool Whether validation errors name the offending field.
     */
    public bool $verboseErrors = true;

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['basePath'],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['basePath'], 'string', 'max' => 190],
            [['authMode'], 'in', 'range' => [self::AUTH_MODE_OPEN, self::AUTH_MODE_PUBLIC_KEY, self::AUTH_MODE_SECRET]],
            [
                [
                    'cartTokenDuration',
                    'customerTokenDuration',
                    'customerRefreshDuration',
                    'rateLimit',
                    'logRetentionDays',
                    'defaultPageSize',
                    'maxPageSize',
                ],
                'integer',
                'min' => 0,
            ],
            [['defaultPageSize', 'maxPageSize'], 'integer', 'min' => 1],
            [['basePath'], 'match', 'pattern' => '/^[A-Za-z0-9\-_\/\.\$\{\}]*$/', 'message' => Craft::t('headdy', 'The base path may only contain letters, numbers, slashes, dots, dashes and underscores.')],
            [['allowCredentials'], 'validateCredentialsWithWildcard'],
            [['maxPageSize'], 'compare', 'compareAttribute' => 'defaultPageSize', 'operator' => '>=', 'type' => 'number'],
        ];
    }

    /**
     * `Access-Control-Allow-Credentials: true` alongside `Access-Control-Allow-Origin: *` is
     * rejected by every browser, so the combination is not a preference — it is a broken API.
     */
    public function validateCredentialsWithWildcard(string $attribute): void
    {
        if ($this->allowCredentials && in_array('*', $this->getAllowedOrigins(), true)) {
            $this->addError($attribute, Craft::t('headdy', 'Credentials cannot be allowed while “*” is an allowed origin. Browsers reject that combination.'));
        }
    }

    /**
     * The base path with any surrounding slashes trimmed and any environment variable resolved.
     */
    public function getBasePath(): string
    {
        $parsed = App::parseEnv($this->basePath);

        return trim(is_string($parsed) ? $parsed : '', '/');
    }

    /**
     * Allowed origins, normalised to scheme + host + port with no trailing slash.
     *
     * @return string[]
     */
    public function getAllowedOrigins(): array
    {
        return self::normalizeOrigins($this->allowedOrigins);
    }

    /**
     * @return string[]
     */
    public function getAllowedRedirectOrigins(): array
    {
        return self::normalizeOrigins($this->allowedRedirectOrigins);
    }

    /**
     * Settings screens post editable tables as `[['value' => '…'], …]`; config files and console
     * callers pass a plain list. Both have to land as the same list of origins.
     *
     * @param mixed $origins
     * @return string[]
     */
    public static function normalizeOrigins(mixed $origins): array
    {
        if (is_string($origins)) {
            $origins = preg_split('/[\s,]+/', $origins) ?: [];
        }

        if (!is_array($origins)) {
            return [];
        }

        $out = [];

        foreach ($origins as $origin) {
            if (is_array($origin)) {
                $origin = $origin['value'] ?? $origin['origin'] ?? reset($origin);
            }

            if (!is_string($origin)) {
                continue;
            }

            $parsed = App::parseEnv($origin);
            $origin = is_string($parsed) ? trim($parsed) : '';
            $origin = rtrim($origin, '/');

            if ($origin !== '') {
                $out[] = $origin;
            }
        }

        return array_values(array_unique($out));
    }
}
