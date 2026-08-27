<?php

namespace justinholtweb\headdy\records;

use craft\db\ActiveRecord;
use justinholtweb\headdy\db\Table;

/**
 * @property int $id
 * @property string $tokenHash
 * @property int $orderId
 * @property int|null $keyId
 * @property int|null $storeId
 * @property int|null $siteId
 * @property string $expiryDate
 * @property string|null $lastUsedAt
 */
class CartTokenRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CART_TOKENS;
    }
}
