<?php

namespace justinholtweb\headdy\controllers\api;

use Craft;
use craft\web\Response;
use justinholtweb\headdy\errors\ApiException;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\Plugin;
use justinholtweb\headdy\web\ApiController;

/**
 * Catalog reads.
 */
class CatalogController extends ApiController
{
    protected function requiredScope(?string $actionId = null): ?string
    {
        return ApiKey::SCOPE_CATALOG_READ;
    }

    /**
     * @throws ApiException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->getSettings()->catalogEnabled) {
            throw ApiException::forbidden(Craft::t('headdy', 'The catalog endpoints are turned off.'));
        }

        return true;
    }

    /**
     * `GET /products`
     */
    public function actionProducts(): Response
    {
        $params = [
            'search' => $this->request->getQueryParam('search'),
            'type' => $this->request->getQueryParam('type'),
            'slug' => $this->request->getQueryParam('slug'),
            'orderBy' => $this->request->getQueryParam('orderBy'),
            'page' => (int)$this->request->getQueryParam('page', 1),
            'pageSize' => (int)$this->request->getQueryParam('pageSize', 0) ?: null,
            'siteId' => $this->_siteId(),
            'availableOnly' => (bool)$this->request->getQueryParam('availableOnly'),
        ];

        $ids = $this->request->getQueryParam('ids');

        if (is_string($ids) && $ids !== '') {
            $params['ids'] = array_map('intval', explode(',', $ids));
        }

        return $this->success(Plugin::getInstance()->getCatalog()->products(array_filter(
            $params,
            fn($v) => $v !== null,
        )));
    }

    /**
     * `GET /products/<idOrSlug>`
     */
    public function actionProduct(string $idOrSlug): Response
    {
        return $this->success([
            'product' => Plugin::getInstance()->getCatalog()->product($idOrSlug, $this->_siteId()),
        ]);
    }

    /**
     * `GET /variants/<idOrSku>`
     */
    public function actionVariant(string $idOrSku): Response
    {
        return $this->success([
            'variant' => Plugin::getInstance()->getCatalog()->variant($idOrSku, $this->_siteId()),
        ]);
    }

    /**
     * `GET /product-types`
     */
    public function actionProductTypes(): Response
    {
        return $this->success(['productTypes' => Plugin::getInstance()->getCatalog()->productTypes()]);
    }

    private function _siteId(): ?int
    {
        $value = $this->request->getQueryParam('site') ?? $this->request->getHeaders()->get('X-Headdy-Site');

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value)
            ? (int)$value
            : Craft::$app->getSites()->getSiteByHandle((string)$value)?->id;
    }
}
