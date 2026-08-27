<?php

namespace justinholtweb\headdy\records;

use craft\db\ActiveRecord;
use justinholtweb\headdy\db\Table;

/**
 * @property int $id
 * @property string $name
 * @property string $publicKey
 * @property string|null $secretHash
 * @property string|null $scopes
 * @property string|null $origins
 * @property int|null $storeId
 * @property int|null $rateLimit
 * @property bool $enabled
 * @property string|null $lastUsedAt
 * @property string|null $expiryDate
 */
class ApiKeyRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::API_KEYS;
    }
}
