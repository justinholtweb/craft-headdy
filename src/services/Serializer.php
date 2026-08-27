<?php

namespace justinholtweb\headdy\services;

use Craft;
use craft\commerce\base\Purchasable;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\Address as CommerceAddress;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\helpers\PaymentForm;
use craft\commerce\models\ShippingMethodOption;
use craft\commerce\Plugin as Commerce;
use craft\elements\Address;
use craft\elements\User;
use craft\helpers\StringHelper;
use justinholtweb\headdy\events\DefineSerializedCartEvent;
use justinholtweb\headdy\events\DefineSerializedProductEvent;
use justinholtweb\headdy\helpers\Money;
use justinholtweb\headdy\Plugin;
use yii\base\Component;

/**
 * The wire format.
 *
 * Commerce's own Ajax responses are `$order->toArray()` — the element's internal attributes, which
 * change between releases, mix camelCase columns with computed getters, and hand back bare floats
 * for money. That is fine for a Twig site posting to itself and unusable as a published contract.
 *
 * Everything Headdy emits is shaped here, by hand. Two rules:
 *
 * 1. **No endpoint builds its own payload.** If a shape appears in two responses it is one method
 *    here, so a cart in a checkout response can never disagree with a cart in a cart response.
 * 2. **Money is always an object** — see {@see Money}. There are no bare float amounts anywhere in
 *    the API.
 */
class Serializer extends Component
{
    /**
     * @event DefineSerializedCartEvent Fired after a cart is serialized, for adding custom keys.
     */
    public const EVENT_DEFINE_SERIALIZED_CART = 'defineSerializedCart';

    /**
     * @event DefineSerializedProductEvent Fired after a product is serialized.
     */
    public const EVENT_DEFINE_SERIALIZED_PRODUCT = 'defineSerializedProduct';

    /**
     * The ISO code to price an order in.
     *
     * `Store::getCurrency()` hands back a `\Money\Currency` object, not a string — passing it
     * straight into a payload serializes as `{}`.
     */
    public static function currencyFor(Order $order): string
    {
        if ($order->currency) {
            return $order->currency;
        }

        try {
            $code = $order->getStore()->getCurrency()?->getCode();
        } catch (\Throwable) {
            $code = null;
        }

        return $code ?: Commerce::getInstance()->getStores()->getCurrentStore()->getCurrency()?->getCode() ?: 'USD';
    }

    /**
     * A cart or an order.
     *
     * The same shape either way — `isCompleted` is what tells them apart. A front end that renders
     * a cart summary can render an order confirmation with the same component.
     */
    public function cart(Order $order, ?string $token = null): array
    {
        $currency = self::currencyFor($order);

        $data = [
            'id' => $order->id,
            'number' => $order->number,
            'reference' => $order->reference,
            'token' => $token,
            'isCompleted' => (bool)$order->isCompleted,
            'isPaid' => (bool)$order->getIsPaid(),
            'isEmpty' => (bool)$order->getIsEmpty(),
            'dateOrdered' => $this->date($order->dateOrdered),
            'datePaid' => $this->date($order->datePaid),
            'dateUpdated' => $this->date($order->dateUpdated),
            'email' => $order->getEmail(),
            'currency' => $currency,
            'paymentCurrency' => $order->paymentCurrency,
            'storeId' => $order->storeId,
            'siteId' => $order->orderSiteId,
            'couponCode' => $order->couponCode,
            'orderStatus' => $this->orderStatus($order),
            'message' => $order->message,
            'totalQty' => (int)$order->getTotalQty(),
            'lineItems' => array_map(fn(LineItem $item) => $this->lineItem($item, $currency), $order->getLineItems()),
            'adjustments' => array_map(fn(OrderAdjustment $adj) => $this->adjustment($adj, $currency), $order->getAdjustments() ?? []),
            'totals' => $this->totals($order, $currency),
            'shippingAddress' => $this->address($order->getShippingAddress()),
            'billingAddress' => $this->address($order->getBillingAddress()),
            'shippingMethod' => $this->selectedShippingMethod($order, $currency),
            'gatewayId' => $order->gatewayId,
            'paymentSourceId' => $order->paymentSourceId,
            'customer' => $this->customerStub($order->getCustomer()),
            'notices' => $this->notices($order),
        ];

        $event = new DefineSerializedCartEvent(['order' => $order, 'cartInfo' => $data]);
        $this->trigger(self::EVENT_DEFINE_SERIALIZED_CART, $event);

        return $event->cartInfo;
    }

    /**
     * Every money figure a checkout needs, in one object.
     *
     * `outstandingBalance` is the number to charge. `total` is what the order costs. On a partly
     * paid order they differ, and a front end that shows `total` at the payment step overcharges.
     */
    public function totals(Order $order, string $currency): array
    {
        return [
            'itemSubtotal' => Money::format($order->getItemSubtotal(), $currency),
            'itemTotal' => Money::format($order->getItemTotal(), $currency),
            'shipping' => Money::format($order->getTotalShippingCost(), $currency),
            'discount' => Money::format($order->getTotalDiscount(), $currency),
            'tax' => Money::format($order->getTotalTax(), $currency),
            'taxIncluded' => Money::format($order->getTotalTaxIncluded(), $currency),
            'total' => Money::format($order->getTotal(), $currency),
            'totalPrice' => Money::format($order->getTotalPrice(), $currency),
            'totalPaid' => Money::format($order->getTotalPaid(), $currency),
            'outstandingBalance' => Money::format($order->getOutstandingBalance(), $currency),
        ];
    }

    public function lineItem(LineItem $item, string $currency): array
    {
        $purchasable = $item->getPurchasable();

        return [
            'id' => $item->id,
            'uid' => $item->uid,
            'purchasableId' => $item->purchasableId,
            'sku' => $item->getSku(),
            'description' => $item->getDescription(),
            'qty' => (int)$item->qty,
            'note' => $item->note,
            'privateNote' => null,
            'options' => $item->getOptions(),
            'optionsSignature' => $item->getOptionsSignature(),
            'price' => Money::format($item->getPrice(), $currency),
            'promotionalPrice' => $item->getPromotionalPrice() !== null
                ? Money::format($item->getPromotionalPrice(), $currency)
                : null,
            'salePrice' => Money::format($item->getSalePrice(), $currency),
            'subtotal' => Money::format($item->getSubtotal(), $currency),
            'total' => Money::format($item->getTotal(), $currency),
            'onPromotion' => (bool)$item->getOnPromotion(),
            'hasFreeShipping' => (bool)$item->getHasFreeShipping(),
            'isShippable' => (bool)$item->getIsShippable(),
            'isTaxable' => (bool)$item->getIsTaxable(),
            'adjustments' => array_map(
                fn(OrderAdjustment $adj) => $this->adjustment($adj, $currency),
                $item->getAdjustments(),
            ),
            'purchasable' => $purchasable instanceof PurchasableInterface
                ? $this->purchasableStub($purchasable, $currency)
                : null,
        ];
    }

    public function adjustment(OrderAdjustment $adjustment, string $currency): array
    {
        return [
            'id' => $adjustment->id,
            'type' => $adjustment->type,
            'name' => $adjustment->name,
            'description' => $adjustment->description,
            'amount' => Money::format($adjustment->amount, $currency),
            'included' => (bool)$adjustment->included,
            'lineItemId' => $adjustment->lineItemId,
            'sourceSnapshot' => null,
        ];
    }

    /**
     * An address, flattened out of Craft's Address element.
     *
     * Field names are the CLDR/`commerceguys/addressing` names Craft itself stores — the same names
     * a caller must POST back. Renaming them to something friendlier would mean a client that reads
     * an address cannot write it back unchanged.
     */
    public function address(Address|CommerceAddress|null $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'id' => $address->id ?? null,
            'fullName' => $address->fullName ?? null,
            'firstName' => $address->firstName ?? null,
            'lastName' => $address->lastName ?? null,
            'organization' => $address->organization ?? null,
            'organizationTaxId' => $address->organizationTaxId ?? null,
            'addressLine1' => $address->addressLine1 ?? null,
            'addressLine2' => $address->addressLine2 ?? null,
            'addressLine3' => $address->addressLine3 ?? null,
            'locality' => $address->locality ?? null,
            'dependentLocality' => $address->dependentLocality ?? null,
            'administrativeArea' => $address->administrativeArea ?? null,
            'postalCode' => $address->postalCode ?? null,
            'sortingCode' => $address->sortingCode ?? null,
            'countryCode' => $address->countryCode ?? null,
            'label' => $address->title ?? null,
        ];
    }

    /**
     * @param ShippingMethodOption[] $options
     */
    public function shippingMethodOptions(array $options, string $currency): array
    {
        $out = [];

        foreach ($options as $handle => $option) {
            $out[] = [
                'handle' => is_string($handle) ? $handle : $option->getHandle(),
                'name' => $option->getName(),
                'price' => Money::format($option->getPrice(), $currency),
                'matchesOrder' => (bool)$option->matchesOrder,
            ];
        }

        return $out;
    }

    public function selectedShippingMethod(Order $order, string $currency): ?array
    {
        if (!$order->shippingMethodHandle) {
            return null;
        }

        return [
            'handle' => $order->shippingMethodHandle,
            'name' => $order->shippingMethodName ?: $order->shippingMethodHandle,
            'price' => Money::format($order->getTotalShippingCost(), $currency),
        ];
    }

    /**
     * A gateway, as a front end needs to see it.
     *
     * `paymentFormParamName` is the key the gateway's own payment form fields must be nested under
     * when posting a payment. Commerce derives it from the gateway handle and a client has no other
     * way to know it, so it ships in the payload rather than in the documentation.
     */
    public function gateway(\craft\commerce\base\GatewayInterface $gateway): array
    {
        return [
            'id' => $gateway->id,
            'handle' => $gateway->handle,
            // `name` is a plain property on Commerce's GatewayTrait, not a getter — `getName()`
            // does not exist and reaching for it fatals with "Calling unknown method".
            'name' => $gateway->name,
            'type' => $gateway::displayName(),
            'supportsPaymentSources' => $gateway->supportsPaymentSources(),
            'supportsPartialPayment' => $gateway->supportsPartialPayment(),
            'paymentFormParamName' => PaymentForm::getPaymentFormParamName($gateway->handle),
        ];
    }

    /**
     * A full product with its variants.
     */
    public function product(Product $product, string $currency): array
    {
        $data = [
            'id' => $product->id,
            'uid' => $product->uid,
            'title' => $product->title,
            'slug' => $product->slug,
            'url' => $product->getUrl(),
            'status' => $product->getStatus(),
            'productTypeHandle' => $product->getType()->handle,
            'postDate' => $this->date($product->postDate),
            'expiryDate' => $this->date($product->expiryDate),
            'defaultVariantId' => $product->defaultVariantId,
            'priceRange' => $this->priceRange($product, $currency),
            'variants' => array_map(
                fn(Variant $variant) => $this->variant($variant, $currency),
                $product->getVariants()->all(),
            ),
        ];

        $event = new DefineSerializedProductEvent(['product' => $product, 'productInfo' => $data]);
        $this->trigger(self::EVENT_DEFINE_SERIALIZED_PRODUCT, $event);

        return $event->productInfo;
    }

    public function variant(Variant $variant, string $currency): array
    {
        return [
            'id' => $variant->id,
            'uid' => $variant->uid,
            'productId' => $variant->getOwnerId(),
            'title' => $variant->title,
            'sku' => $variant->getSku(),
            'isDefault' => (bool)$variant->isDefault,
            'price' => $variant->getPrice() !== null ? Money::format($variant->getPrice(), $currency) : null,
            'promotionalPrice' => $variant->getPromotionalPrice() !== null
                ? Money::format($variant->getPromotionalPrice(), $currency)
                : null,
            'salePrice' => $variant->getSalePrice() !== null ? Money::format($variant->getSalePrice(), $currency) : null,
            'onPromotion' => (bool)$variant->getOnPromotion(),
            'isAvailable' => (bool)$variant->getIsAvailable(),
            'hasStock' => (bool)$variant->hasStock(),
            'stock' => $variant->inventoryTracked ? (int)$variant->getStock() : null,
            'inventoryTracked' => (bool)$variant->inventoryTracked,
            'minQty' => $variant->minQty,
            'maxQty' => $variant->maxQty,
            'width' => $variant->width,
            'height' => $variant->height,
            'length' => $variant->length,
            'weight' => $variant->weight,
        ];
    }

    /**
     * The lowest and highest price across a product's available variants — what a listing page
     * shows as "from $x". Computed here rather than by the client so a 40-variant product does not
     * have to be shipped whole just to render a price.
     */
    public function priceRange(Product $product, string $currency): ?array
    {
        $prices = [];

        foreach ($product->getVariants()->all() as $variant) {
            $price = $variant->getSalePrice() ?? $variant->getPrice();

            if ($price !== null) {
                $prices[] = (float)$price;
            }
        }

        if (!$prices) {
            return null;
        }

        return [
            'min' => Money::format(min($prices), $currency),
            'max' => Money::format(max($prices), $currency),
        ];
    }

    /**
     * The thin purchasable summary carried inside a line item — enough to render a cart row without
     * a second request, not the whole element.
     */
    public function purchasableStub(PurchasableInterface $purchasable, string $currency): array
    {
        $data = [
            'id' => $purchasable->getId(),
            'sku' => $purchasable->getSku(),
            'description' => $purchasable->getDescription(),
            'type' => get_class($purchasable),
            'price' => $purchasable->getPrice() !== null ? Money::format($purchasable->getPrice(), $currency) : null,
        ];

        if ($purchasable instanceof Variant) {
            $product = $purchasable->getOwner();

            $data['productId'] = $product?->id;
            $data['productTitle'] = $product?->title;
            $data['url'] = $product instanceof Product ? $product->getUrl() : null;
        }

        if ($purchasable instanceof Purchasable) {
            $data['isAvailable'] = $purchasable->getIsAvailable();
        }

        return $data;
    }

    public function customerStub(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'uid' => $user->uid,
            'email' => $user->email,
            'fullName' => $user->fullName,
            'firstName' => $user->firstName,
            'lastName' => $user->lastName,
            'isGuest' => !$user->getIsCredentialed(),
        ];
    }

    /**
     * Commerce order notices — "this item's price changed", "your coupon expired". A headless cart
     * that drops these silently reprices the customer's basket with no explanation.
     */
    public function notices(Order $order): array
    {
        $out = [];

        foreach ($order->getNotices() as $notice) {
            $out[] = [
                'type' => $notice->type,
                'attribute' => $notice->attribute,
                'message' => $notice->message,
            ];
        }

        return $out;
    }

    public function orderStatus(Order $order): ?array
    {
        $status = $order->getOrderStatus();

        if ($status === null) {
            return null;
        }

        return [
            'id' => $status->id,
            'handle' => $status->handle,
            'name' => $status->name,
            'color' => $status->color,
        ];
    }

    public function transaction(\craft\commerce\models\Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'hash' => $transaction->hash,
            'reference' => $transaction->reference,
            'type' => $transaction->type,
            'status' => $transaction->status,
            'amount' => Money::format($transaction->amount, $transaction->currency),
            'paymentAmount' => Money::format($transaction->paymentAmount, $transaction->paymentCurrency),
            'gatewayId' => $transaction->gatewayId,
            'message' => $transaction->message,
            'code' => $transaction->code,
            'dateCreated' => $this->date($transaction->dateCreated),
        ];
    }

    /**
     * Dates are ISO 8601 with an offset, always. A bare `Y-m-d H:i:s` forces every consumer to
     * guess a time zone, and they guess differently.
     */
    public function date(mixed $date): ?string
    {
        if (!$date instanceof \DateTimeInterface) {
            return null;
        }

        return $date->format(\DateTimeInterface::ATOM);
    }

    /**
     * Validation errors, keyed by field.
     *
     * Craft nests order errors under `shippingAddress.postalCode`-style keys already; this only
     * flattens the message arrays and drops anything the caller cannot act on.
     *
     * @return array<string, string[]>
     */
    public function errors(\yii\base\Model $model): array
    {
        $out = [];

        foreach ($model->getErrors() as $attribute => $messages) {
            $attribute = StringHelper::removeLeft($attribute, 'field:');
            $out[$attribute] = array_values(array_unique((array)$messages));
        }

        return $out;
    }

    /**
     * Whether verbose errors are on. Off, the API still says *that* something failed and with what
     * code — it just stops naming fields, which is the right default for a public endpoint that
     * could otherwise be used to probe the shape of a store's data.
     */
    public function shouldExposeErrors(): bool
    {
        return Plugin::getInstance()->getSettings()->verboseErrors;
    }
}
