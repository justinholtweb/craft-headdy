<?php

namespace justinholtweb\headdy\services;

use Craft;
use justinholtweb\headdy\models\Settings;
use justinholtweb\headdy\Plugin;
use yii\base\Component;

/**
 * Configuration checks and the route table.
 *
 * Most of what goes wrong with a headless integration is configuration, not code — a base path
 * that collides with a route, an origin that was never allowed, an off-site gateway with no
 * permitted return URL. Every one of those produces a confusing failure in somebody else's
 * JavaScript, a day later, in a different timezone. These checks find them here instead, and the
 * console command exits non-zero on an error so a deploy can be gated on them.
 */
class Diagnostics extends Component
{
    /**
     * The route table, split into method and path.
     *
     * Done here rather than in Twig: the verb is baked into the rule key as `"POST carts"`, and
     * unpicking that in a template needs a regex Twig does not have.
     *
     * @return array[] Each: method, path
     */
    public function routeTable(): array
    {
        $plugin = Plugin::getInstance();
        $prefix = '/' . $plugin->getSettings()->getBasePath() . '/' . Plugin::API_VERSION;
        $out = [];

        foreach (array_keys($plugin->_apiRules()) as $pattern) {
            if (preg_match('/^([A-Z,]+)\\s+(.*)$/', $pattern, $matches)) {
                $method = str_replace(',', ', ', $matches[1]);
                $path = $matches[2];
            } else {
                $method = 'GET';
                $path = $pattern;
            }

            $out[] = [
                'method' => $method,
                'path' => rtrim($prefix . '/' . $path, '/'),
            ];
        }

        return $out;
    }

    /**
     * @return array[] Each: level (ok|warning|error), label, detail
     */
    public function runChecks(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $checks = [];

        $checks[] = Plugin::commerceIsReady()
            ? ['level' => 'ok', 'label' => Craft::t('headdy', 'Craft Commerce is installed and enabled.')]
            : ['level' => 'error', 'label' => Craft::t('headdy', 'Craft Commerce is not available.'), 'detail' => Craft::t('headdy', 'Every endpoint returns 503 until Commerce is enabled.')];

        $checks[] = $settings->enabled
            ? ['level' => 'ok', 'label' => Craft::t('headdy', 'The API is switched on.')]
            : ['level' => 'warning', 'label' => Craft::t('headdy', 'The API is switched off.'), 'detail' => Craft::t('headdy', 'Every endpoint returns 503 with the code “api_disabled”.')];

        $checks[] = $settings->getBasePath() !== ''
            ? ['level' => 'ok', 'label' => Craft::t('headdy', 'Mounted at /{path}/{version}', ['path' => $settings->getBasePath(), 'version' => Plugin::API_VERSION])]
            : ['level' => 'error', 'label' => Craft::t('headdy', 'No base path is set, so no routes are registered.')];

        $keys = $plugin->getKeys()->getAllKeys();
        $usableKeys = array_filter($keys, fn($key) => $key->isUsable());

        if ($settings->authMode === Settings::AUTH_MODE_OPEN) {
            $checks[] = [
                'level' => 'warning',
                'label' => Craft::t('headdy', 'The API accepts unauthenticated requests.'),
                'detail' => Craft::t('headdy', 'Anyone who finds the URL can create carts. Switch to a public key unless this site is on a private network.'),
            ];
        } elseif (!$usableKeys) {
            $checks[] = [
                'level' => 'error',
                'label' => Craft::t('headdy', 'No usable API key exists.'),
                'detail' => Craft::t('headdy', 'Every request will be rejected with 401 until a key is created.'),
            ];
        } else {
            $checks[] = ['level' => 'ok', 'label' => Craft::t('headdy', '{count} usable API key(s).', ['count' => count($usableKeys)])];
        }

        if (!$settings->getAllowedOrigins()) {
            $checks[] = [
                'level' => 'warning',
                'label' => Craft::t('headdy', 'Any browser origin may call the API.'),
                'detail' => Craft::t('headdy', 'Safe — the token, not CORS, is what protects this API, and no cookie is ever sent — but naming your front end’s origins is one less way for a leaked key to be used.'),
            ];
        } else {
            $checks[] = ['level' => 'ok', 'label' => Craft::t('headdy', 'Allowed origins: {origins}', ['origins' => implode(', ', $settings->getAllowedOrigins())])];
        }

        if (!$settings->getAllowedRedirectOrigins()) {
            $checks[] = [
                'level' => 'warning',
                'label' => Craft::t('headdy', 'No redirect origins are allowed.'),
                'detail' => Craft::t('headdy', 'Off-site gateways cannot be used from the API until a return URL origin is allowed. On-site gateways are unaffected.'),
            ];
        } else {
            $checks[] = ['level' => 'ok', 'label' => Craft::t('headdy', 'Redirect origins: {origins}', ['origins' => implode(', ', $settings->getAllowedRedirectOrigins())])];
        }

        if ($settings->authMode === Settings::AUTH_MODE_SECRET && !$settings->getAllowedOrigins()) {
            $checks[] = [
                'level' => 'warning',
                'label' => Craft::t('headdy', 'Secret authentication with no origin list.'),
                'detail' => Craft::t('headdy', 'A secret cannot be shipped in browser JavaScript. If your front end calls this API from the browser, use public key mode instead.'),
            ];
        }

        if (Plugin::commerceIsReady()) {
            $gateways = \craft\commerce\Plugin::getInstance()->getGateways()->getAllCustomerEnabledGateways();

            $checks[] = count($gateways)
                ? ['level' => 'ok', 'label' => Craft::t('headdy', '{count} customer-enabled payment gateway(s).', ['count' => count($gateways)])]
                : ['level' => 'warning', 'label' => Craft::t('headdy', 'No customer-enabled payment gateway.'), 'detail' => Craft::t('headdy', 'Carts can be built but not paid for.')];
        }

        if (!$plugin->isPro()) {
            $checks[] = [
                'level' => 'ok',
                'label' => Craft::t('headdy', 'Running the Lite edition.'),
                'detail' => Craft::t('headdy', 'Customer accounts, GraphQL mutations, webhooks and the request log need Pro.'),
            ];
        }

        return $checks;
    }
}
