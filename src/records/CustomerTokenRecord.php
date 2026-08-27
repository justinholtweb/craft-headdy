<?php

namespace justinholtweb\headdy\records;

use craft\db\ActiveRecord;
use justinholtweb\headdy\db\Table;

/**
 * @property int $id
 * @property string $tokenHash
 * @property string|null $refreshHash
 * @property int $userId
 * @property int|null $keyId
 * @property string $expiryDate
 * @property string|null $refreshExpiryDate
 * @property bool $revoked
 * @property string|null $lastUsedAt
 */
class CustomerTokenRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CUSTOMER_TOKENS;
    }
}
