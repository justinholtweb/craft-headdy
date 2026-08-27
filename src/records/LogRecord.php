<?php

namespace justinholtweb\headdy\records;

use craft\db\ActiveRecord;
use justinholtweb\headdy\db\Table;

/**
 * @property int $id
 * @property int|null $keyId
 * @property string $method
 * @property string $path
 * @property int $statusCode
 * @property int|null $durationMs
 * @property string|null $ip
 * @property string|null $origin
 * @property int|null $orderId
 * @property string|null $errorCode
 * @property string|null $request
 * @property string|null $response
 */
class LogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOG;
    }
}
