<?php

namespace justinholtweb\headdy\services;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\helpers\ArrayHelper;
use justinholtweb\headdy\errors\ApiException;
use justinholtweb\headdy\Plugin;
use yii\base\Component;

/**
 * Catalog reads.
 *
 * Craft's GraphQL already exposes products, and a site using it should keep using it — this is here
 * so that a front end does not have to run two clients against two auth schemes just to render a
 * listing next to a cart. The shapes match what {@see Serializer} emits inside a cart, so a product
 * card and a line item agree about what a price looks like.
 */
class Catalog extends Component
{
    /**
     * @param array $params {
     *     @var string|null $search
     *     @var string|null $type      Product type handle
     *     @var int[]|null  $ids
     *     @var string|null $slug
     *     @var string      $orderBy
     *     @var int         $page
     *     @var int         $pageSize
     *     @var int|null    $siteId
     *     @var bool        $availableOnly
     * }
     * @throws ApiException
     */
    public function products(array $params = []): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $serializer = Plugin::getInstance()->getSerializer();

        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = (int)($params['pageSize'] ?? $settings->defaultPageSize);
        $pageSize = max(1, min($pageSize, $settings->maxPageSize));

        $query = Product::find()
            ->status(Product::STATUS_LIVE)
            ->siteId($params['siteId'] ?? null)
            ->limit($pageSize)
            ->offset(($page - 1) * $pageSize);

        if (!empty($params['type'])) {
            $query->type($params['type']);
        }

        if (!empty($params['ids']) && is_array($params['ids'])) {
            $query->id(array_map('intval', $params['ids']));
        }

        if (!empty($params['slug'])) {
            $query->slug($params['slug']);
        }

        if (!empty($params['search'])) {
            $query->search($params['search']);
        }

        $query->orderBy($this->_orderBy($params['orderBy'] ?? null));

        // The count has to come off a clone: `count()` on the query itself would run with the
        // limit and offset already applied and report the page size back as the total.
        $total = (int)(clone $query)->limit(null)->offset(null)->count();

        $currency = $this->_currency($params['siteId'] ?? null);

        $products = array_map(
            fn(Product $product) => $serializer->product($product, $currency),
            $query->all(),
        );

        if (!empty($params['availableOnly'])) {
            $products = array_values(array_filter(
                $products,
                fn(array $p) => ArrayHelper::contains($p['variants'], fn(array $v) => $v['isAvailable']),
            ));
        }

        return [
            'products' => $products,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => (int)ceil($total / $pageSize),
            ],
        ];
    }

    /**
     * @throws ApiException
     */
    public function product(int|string $idOrSlug, ?int $siteId = null): array
    {
        $query = Product::find()->status(Product::STATUS_LIVE)->siteId($siteId);

        if (is_numeric($idOrSlug)) {
            $query->id((int)$idOrSlug);
        } else {
            $query->slug($idOrSlug);
        }

        $product = $query->one();

        if (!$product instanceof Product) {
            throw ApiException::notFound(Craft::t('headdy', 'No product found.'));
        }

        return Plugin::getInstance()->getSerializer()->product($product, $this->_currency($siteId));
    }

    /**
     * A single variant, by ID or SKU.
     *
     * SKU as well as ID because a headless front end fed by a PIM or a spreadsheet knows SKUs and
     * has no reason to know Craft's element IDs.
     *
     * @throws ApiException
     */
    public function variant(int|string $idOrSku, ?int $siteId = null): array
    {
        $query = Variant::find()->status(Variant::STATUS_ENABLED)->siteId($siteId);

        if (is_numeric($idOrSku)) {
            $query->id((int)$idOrSku);
        } else {
            $query->sku($idOrSku);
        }

        $variant = $query->one();

        if (!$variant instanceof Variant) {
            throw ApiException::notFound(
                Craft::t('headdy', 'No variant found.'),
                ApiException::PURCHASABLE_NOT_FOUND,
            );
        }

        return Plugin::getInstance()->getSerializer()->variant($variant, $this->_currency($siteId));
    }

    /**
     * The product types a caller can filter by.
     */
    public function productTypes(): array
    {
        $out = [];

        foreach (Commerce::getInstance()->getProductTypes()->getAllProductTypes() as $type) {
            $out[] = [
                'id' => $type->id,
                'handle' => $type->handle,
                'name' => $type->name,
                // `maxVariants` is null for unlimited; only a cap of exactly one means a single variant.
                'hasVariants' => $type->maxVariants !== 1,
            ];
        }

        return $out;
    }

    /**
     * Only a fixed set of sort orders is accepted.
     *
     * `orderBy` reaches SQL. Passing a caller's string through would be an injection, and allowing
     * arbitrary columns would let a caller sort a public catalog by a private field.
     */
    private function _orderBy(?string $orderBy): array
    {
        return match ($orderBy) {
            'title' => ['title' => SORT_ASC],
            '-title' => ['title' => SORT_DESC],
            'postDate' => ['postDate' => SORT_ASC],
            '-postDate' => ['postDate' => SORT_DESC],
            'id' => ['elements.id' => SORT_ASC],
            '-id' => ['elements.id' => SORT_DESC],
            default => ['postDate' => SORT_DESC],
        };
    }

    private function _currency(?int $siteId): string
    {
        $stores = Commerce::getInstance()->getStores();
        $store = $siteId !== null ? $stores->getStoreBySiteId($siteId) : null;
        $store ??= $stores->getCurrentStore();

        return $store->getCurrency()?->getCode() ?: 'USD';
    }
}
