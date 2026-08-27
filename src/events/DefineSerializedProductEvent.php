<?php

namespace justinholtweb\headdy\events;

use craft\commerce\elements\Product;
use yii\base\Event;

/**
 * Fired once a product has been shaped for the wire.
 */
class DefineSerializedProductEvent extends Event
{
    public Product $product;

    /**
     * @var array The payload. Mutate it; whatever is here when the event returns is what ships.
     *
     * Not `data`: `yii\base\Event` already declares that untyped, and typing an inherited
     * property is a compile-time fatal.
     */
    public array $productInfo = [];
}
