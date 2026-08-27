<?php

namespace justinholtweb\headdy;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\events\ModelEvent;
use craft\events\RegisterGqlMutationsEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use justinholtweb\headdy\gql\CartMutations;
use justinholtweb\headdy\gql\CartQueries;
use justinholtweb\headdy\models\Settings;
use justinholtweb\headdy\models\Webhook;
use justinholtweb\headdy\services\Carts;
use justinholtweb\headdy\services\Catalog;
use justinholtweb\headdy\services\Checkout;
use justinholtweb\headdy\services\Customers;
use justinholtweb\headdy\services\Diagnostics;
use justinholtweb\headdy\services\Keys;
use justinholtweb\headdy\services\Log;
use justinholtweb\headdy\services\Payments;
use justinholtweb\headdy\services\RequestContext;
use justinholtweb\headdy\services\Serializer;
use justinholtweb\headdy\services\Tokens;
use justinholtweb\headdy\services\Webhooks;
use yii\base\Event;

/**
 * Headdy — a headless storefront API for Craft Commerce.
 *
 * @property-read Carts $carts
 * @property-read Catalog $catalog
 * @property-read Checkout $checkout
 * @property-read Customers $customers
 * @property-read Diagnostics $diagnostics
 * @property-read Keys $keys
 * @property-read Log $log
 * @property-read Payments $payments
 * @property-read RequestContext $requestContext
 * @property-read Serializer $serializer
 * @property-read Tokens $tokens
 * @property-read Webhooks $webhooks
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'headdy';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    /**
     * The API's own version, independent of the plugin's.
     *
     * It appears in every URL. A breaking change to a response shape means a `v2` mounted alongside
     * `v1`, not a silent edit — that is the entire promise a versioned API makes, and the reason a
     * front-end team will build against this instead of against Commerce's internals.
     */
    public const API_VERSION = 'v1';

    public const PERMISSION_MANAGE_KEYS = 'headdy-manageKeys';
    public const PERMISSION_VIEW_LOG = 'headdy-viewLog';
    public const PERMISSION_MANAGE_WEBHOOKS = 'headdy-manageWebhooks';

    /**
     * @var bool Whether the GraphQL handlers have been attached this process.
     */
    private bool $_graphqlRegistered = false;

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'carts' => ['class' => Carts::class],
                'catalog' => ['class' => Catalog::class],
                'checkout' => ['class' => Checkout::class],
                'customers' => ['class' => Customers::class],
                'diagnostics' => ['class' => Diagnostics::class],
                'keys' => ['class' => Keys::class],
                'log' => ['class' => Log::class],
                'payments' => ['class' => Payments::class],
                'requestContext' => ['class' => RequestContext::class],
                'serializer' => ['class' => Serializer::class],
                'tokens' => ['class' => Tokens::class],
                'webhooks' => ['class' => Webhooks::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerCpRoutes();
        $this->_registerPermissions();

        // Everything below reaches into Commerce. The plugin can be installed while Commerce is
        // disabled or mid-upgrade, and a fatal in `init()` takes the whole control panel with it.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerApiRoutes();
        $this->_registerOrderStatusWebhook();

        if ($this->isPro() && $this->getSettings()->graphqlEnabled) {
            $this->registerGraphql();
        }
    }

    /**
     * Whether Commerce is installed and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getCarts(): Carts
    {
        return $this->get('carts');
    }

    public function getCatalog(): Catalog
    {
        return $this->get('catalog');
    }

    public function getCheckout(): Checkout
    {
        return $this->get('checkout');
    }

    public function getCustomers(): Customers
    {
        return $this->get('customers');
    }

    public function getDiagnostics(): Diagnostics
    {
        return $this->get('diagnostics');
    }

    public function getKeys(): Keys
    {
        return $this->get('keys');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    public function getPayments(): Payments
    {
        return $this->get('payments');
    }

    public function getRequestContext(): RequestContext
    {
        return $this->get('requestContext');
    }

    public function getSerializer(): Serializer
    {
        return $this->get('serializer');
    }

    public function getTokens(): Tokens
    {
        return $this->get('tokens');
    }

    public function getWebhooks(): Webhooks
    {
        return $this->get('webhooks');
    }

    /**
     * The API's root URL, for the CP and the docs to print.
     */
    public function getApiUrl(): string
    {
        return rtrim(Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '/', '/')
            . '/' . $this->getSettings()->getBasePath()
            . '/' . self::API_VERSION;
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('headdy/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('headdy', 'Headdy');

        $user = Craft::$app->getUser();
        $subNav = [];

        $subNav['overview'] = [
            'label' => Craft::t('headdy', 'Overview'),
            'url' => 'headdy',
        ];

        if ($user->checkPermission(self::PERMISSION_MANAGE_KEYS)) {
            $subNav['keys'] = [
                'label' => Craft::t('headdy', 'API keys'),
                'url' => 'headdy/keys',
            ];
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_MANAGE_WEBHOOKS)) {
            $subNav['webhooks'] = [
                'label' => Craft::t('headdy', 'Webhooks'),
                'url' => 'headdy/webhooks',
            ];
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_VIEW_LOG)) {
            $subNav['log'] = [
                'label' => Craft::t('headdy', 'Request log'),
                'url' => 'headdy/log',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('headdy', 'Settings'),
                'url' => 'settings/plugins/headdy',
            ];
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    // Wiring
    // =========================================================================

    /**
     * Mounts the REST API under the configured base path.
     *
     * Rules are registered even when the API is switched off, and the controller answers 503. A
     * disabled API that 404s is indistinguishable from a misconfigured base path, and that is the
     * first thing anyone gets wrong.
     */
    private function _registerApiRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $base = $this->getSettings()->getBasePath();

                if ($base === '') {
                    return;
                }

                $prefix = $base . '/' . self::API_VERSION;

                foreach ($this->_apiRules() as $pattern => $route) {
                    // Verb-prefixed patterns ("POST carts") have to keep the verb in front of the
                    // path when the prefix is spliced in.
                    if (preg_match('/^([A-Z,]+)\s+(.*)$/', $pattern, $matches)) {
                        $event->rules[$matches[1] . ' ' . rtrim($prefix . '/' . $matches[2], '/')] = $route;
                        continue;
                    }

                    $event->rules[rtrim($prefix . '/' . $pattern, '/')] = $route;
                }

                // One catch-all so a browser preflight to any path under the API reaches a
                // controller that can answer it. Without this, OPTIONS falls through to Craft's
                // 404 handler, which sends no CORS headers and makes every cross-origin call fail
                // with a message that does not mention CORS.
                $event->rules['OPTIONS ' . $prefix . '/<path:.*>'] = 'headdy/api/store/index';
                $event->rules['OPTIONS ' . $prefix] = 'headdy/api/store/index';
            }
        );
    }

    /**
     * The route table.
     *
     * Kept as data rather than scattered across controllers so the whole API surface can be read
     * in one place — and so the docs, the diagnostics screen and the tests can enumerate it.
     *
     * @return array<string, string>
     */
    public function _apiRules(): array
    {
        return [
            '' => 'headdy/api/store/index',
            'store' => 'headdy/api/store/config',
            'stores' => 'headdy/api/store/stores',

            'POST carts' => 'headdy/api/carts/create',
            'GET carts/current' => 'headdy/api/carts/get',
            'PATCH,POST carts/current' => 'headdy/api/carts/update',
            'DELETE carts/current' => 'headdy/api/carts/delete',
            'POST carts/current/items' => 'headdy/api/carts/add-item',
            'DELETE carts/current/items' => 'headdy/api/carts/clear',
            'PATCH,PUT carts/current/items/<lineItemId:[^\/]+>' => 'headdy/api/carts/update-item',
            'DELETE carts/current/items/<lineItemId:[^\/]+>' => 'headdy/api/carts/remove-item',
            'PUT,POST carts/current/addresses' => 'headdy/api/carts/set-addresses',
            'PUT,POST carts/current/email' => 'headdy/api/carts/set-email',
            'PUT,POST carts/current/coupon' => 'headdy/api/carts/set-coupon',
            'PUT,POST carts/current/shipping-method' => 'headdy/api/carts/set-shipping-method',
            'GET carts/current/shipping-methods' => 'headdy/api/carts/shipping-methods',
            'POST carts/current/attach' => 'headdy/api/carts/attach',

            'GET checkout' => 'headdy/api/checkout/state',
            'POST checkout/pay' => 'headdy/api/checkout/pay',
            'POST checkout/complete' => 'headdy/api/checkout/complete',
            'POST,GET checkout/complete-payment' => 'headdy/api/checkout/complete-payment',

            'GET products' => 'headdy/api/catalog/products',
            'GET products/<idOrSlug:[^\/]+>' => 'headdy/api/catalog/product',
            'GET variants/<idOrSku:[^\/]+>' => 'headdy/api/catalog/variant',
            'GET product-types' => 'headdy/api/catalog/product-types',

            'POST customers' => 'headdy/api/customers/register',
            'POST customers/sessions' => 'headdy/api/customers/login',
            'POST customers/sessions/refresh' => 'headdy/api/customers/refresh',
            'DELETE customers/sessions' => 'headdy/api/customers/logout',
            'GET customers/me' => 'headdy/api/customers/me',
            'GET customers/me/orders' => 'headdy/api/customers/orders',
            'GET customers/me/orders/<number:[^\/]+>' => 'headdy/api/customers/order',
            'GET customers/me/addresses' => 'headdy/api/customers/addresses',
            'POST customers/me/addresses' => 'headdy/api/customers/save-address',
            'PATCH,PUT customers/me/addresses/<addressId:\d+>' => 'headdy/api/customers/save-address',
            'DELETE customers/me/addresses/<addressId:\d+>' => 'headdy/api/customers/delete-address',
            'GET customers/me/payment-sources' => 'headdy/api/customers/payment-sources',
            'GET,POST orders/lookup' => 'headdy/api/customers/lookup',
        ];
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['headdy'] = 'headdy/overview/index';
                $event->rules['headdy/keys'] = 'headdy/keys/index';
                $event->rules['headdy/keys/new'] = 'headdy/keys/edit';
                $event->rules['headdy/keys/<keyId:\d+>'] = 'headdy/keys/edit';
                $event->rules['headdy/webhooks'] = 'headdy/webhooks/index';
                $event->rules['headdy/webhooks/new'] = 'headdy/webhooks/edit';
                $event->rules['headdy/webhooks/<webhookId:\d+>'] = 'headdy/webhooks/edit';
                $event->rules['headdy/log'] = 'headdy/log/index';
                $event->rules['headdy/log/<entryId:\d+>'] = 'headdy/log/detail';
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('headdy', 'Headdy'),
                    'permissions' => [
                        self::PERMISSION_MANAGE_KEYS => [
                            'label' => Craft::t('headdy', 'Manage API keys'),
                        ],
                        self::PERMISSION_MANAGE_WEBHOOKS => [
                            'label' => Craft::t('headdy', 'Manage webhooks'),
                        ],
                        self::PERMISSION_VIEW_LOG => [
                            'label' => Craft::t('headdy', 'View the request log'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Fires the order-status webhook.
     *
     * On the element save rather than Commerce's own status event because the status can change
     * through a bulk action, a console command or a plugin, and only the save is common to all of
     * them.
     */
    private function _registerOrderStatusWebhook(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Order $order */
                $order = $event->sender;

                if (!$order->isCompleted || $order->propagating) {
                    return;
                }

                $this->getWebhooks()->dispatch(Webhook::TOPIC_ORDER_STATUS_CHANGED, [
                    'order' => $this->getSerializer()->cart($order),
                ], $order->storeId);
            }
        );
    }

    /**
     * Registers the GraphQL cart mutations.
     *
     * These are the thing Commerce has never shipped — there is an open discussion
     * (craftcms/commerce#2350) that has been open for years. Every mutation resolves through
     * {@see Carts::update()}, the same method the REST endpoints use, so the two transports cannot
     * drift apart.
     */
    public function registerGraphql(): void
    {
        // Guarded: `init()` calls this once, but a console run that switches edition mid-process
        // calls it again to pick the handlers up, and two handlers would register every mutation
        // twice.
        if ($this->_graphqlRegistered) {
            return;
        }

        $this->_graphqlRegistered = true;

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_MUTATIONS,
            static function(RegisterGqlMutationsEvent $event) {
                $event->mutations = array_merge($event->mutations, CartMutations::getMutations());
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            static function(RegisterGqlQueriesEvent $event) {
                $event->queries = array_merge($event->queries, CartQueries::getQueries());
            }
        );
    }
}
