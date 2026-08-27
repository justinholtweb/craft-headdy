<?php

namespace justinholtweb\headdy\records;

use craft\db\ActiveRecord;
use justinholtweb\headdy\db\Table;

/**
 * @property int $id
 * @property int $webhookId
 * @property string $topic
 * @property string|null $payload
 * @property int|null $statusCode
 * @property int $attempt
 * @property bool $success
 * @property string|null $error
 */
class WebhookDeliveryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::WEBHOOK_DELIVERIES;
    }
}
