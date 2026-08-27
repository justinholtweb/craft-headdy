<?php

namespace justinholtweb\headdy\records;

use craft\db\ActiveRecord;
use justinholtweb\headdy\db\Table;

/**
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string|null $topics
 * @property string|null $secret
 * @property int|null $storeId
 * @property bool $enabled
 */
class WebhookRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::WEBHOOKS;
    }
}
