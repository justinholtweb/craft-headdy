<?php

namespace justinholtweb\headdy\events;

use craft\commerce\elements\Order;
use yii\base\Event;

/**
 * Fired once a cart has been shaped for the wire, so a site module can add its own keys without
 * forking the serializer.
 */
class DefineSerializedCartEvent extends Event
{
    /**
     * @var Order The cart or order being serialized.
     */
    public Order $order;

    /**
     * @var array The payload. Mutate it; whatever is here when the event returns is what ships.
     *
     * Named `cartInfo` rather than `data` because `yii\base\Event` already declares an untyped
     * `$data`, and PHP will not let a subclass add a type to an inherited property — the class
     * fatals at compile time, not at use.
     */
    public array $cartInfo = [];
}
