<?php

namespace justinholtweb\headdy\controllers\api;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Response;
use justinholtweb\headdy\Plugin;
use justinholtweb\headdy\web\ApiController;

/**
 * Store configuration.
 *
 * A front end needs the store's currency, its address-format requirements and its checkout rules
 * before it can render a single form. Without an endpoint like this, that configuration ends up
 * hard-coded in the JavaScript and drifts out of sync with the control panel the day someone
 * changes it.
 */
class StoreController extends ApiController
{
    /**
     * `GET /` — a discovery document.
     *
     * Deliberately answerable with no key even in `publicKey` mode is *not* the case: it still
     * needs a key. It exists so a client can confirm its key, base path and version line up before
     * blaming its own code.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        return $this->success([
            'name' => 'Headdy Storefront API',
            'version' => $plugin->getVersion(),
            'edition' => $plugin->edition,
            'apiVersion' => Plugin::API_VERSION,
            'basePath' => '/' . $settings->getBasePath() . '/' . Plugin::API_VERSION,
            'commerceVersion' => Commerce::getInstance()->getVersion(),
            'features' => [
                'catalog' => $settings->catalogEnabled,
                'customers' => $plugin->isPro() && $settings->allowCustomerLogin,
                'registration' => $plugin->isPro() && $settings->allowCustomerRegistration,
                'graphql' => $plugin->isPro() && $settings->graphqlEnabled,
                'webhooks' => $plugin->isPro(),
            ],
        ]);
    }

    /**
     * `GET /store` — currency, gateways, sites and checkout rules.
     */
    public function actionConfig(): Response
    {
        $commerce = Commerce::getInstance();
        $serializer = Plugin::getInstance()->getSerializer();

        $storeId = $this->request->getQueryParam('storeId');
        $store = $storeId !== null
            ? $commerce->getStores()->getStoreById((int)$storeId)
            : $commerce->getStores()->getCurrentStore();

        if ($store === null) {
            throw \justinholtweb\headdy\errors\ApiException::notFound(Craft::t('headdy', 'Unknown store.'));
        }

        return $this->success([
            'store' => [
                'id' => $store->id,
                'handle' => $store->handle,
                'name' => $store->getName(),
                'currency' => $store->getCurrency()?->getCode(),
                // Through the settings model: `Store::getCountries()` is deprecated in Commerce 5
                // and logs to the deprecator on every single API call.
                'countries' => $store->getSettings()->getCountries(),
                'requiresShippingAddress' => (bool)$store->getRequireShippingAddressAtCheckout(),
                'requiresBillingAddress' => (bool)$store->getRequireBillingAddressAtCheckout(),
                'requiresShippingMethod' => (bool)$store->getRequireShippingMethodSelectionAtCheckout(),
                'allowsEmptyCart' => (bool)$store->getAllowEmptyCartOnCheckout(),
                'allowsCheckoutWithoutPayment' => (bool)$store->getAllowCheckoutWithoutPayment(),
                'allowsPartialPayment' => (bool)$store->getAllowPartialPaymentOnCheckout(),
            ],
            'gateways' => array_map(
                fn($gateway) => $serializer->gateway($gateway),
                $commerce->getGateways()->getAllCustomerEnabledGateways()->all(),
            ),
            'sites' => array_map(
                fn($site) => ['id' => $site->id, 'handle' => $site->handle, 'name' => $site->getName(), 'language' => $site->language],
                Craft::$app->getSites()->getAllSites(),
            ),
        ]);
    }

    /**
     * `GET /stores` — every store, for a multi-store build.
     */
    public function actionStores(): Response
    {
        $out = [];

        foreach (Commerce::getInstance()->getStores()->getAllStores() as $store) {
            $out[] = [
                'id' => $store->id,
                'handle' => $store->handle,
                'name' => $store->getName(),
                'currency' => $store->getCurrency()?->getCode(),
                'primary' => (bool)$store->primary,
            ];
        }

        return $this->success(['stores' => $out]);
    }
}
