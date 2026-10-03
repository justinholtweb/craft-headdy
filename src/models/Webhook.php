<?php

namespace justinholtweb\headdy\models;

use craft\base\Model;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\headdy\Plugin;

/**
 * An outbound webhook endpoint.
 */
class Webhook extends Model
{
    public const TOPIC_CART_CREATED = 'cart.created';
    public const TOPIC_CART_UPDATED = 'cart.updated';
    public const TOPIC_CART_COMPLETED = 'cart.completed';
    public const TOPIC_ORDER_PAID = 'order.paid';
    public const TOPIC_ORDER_STATUS_CHANGED = 'order.statusChanged';
    public const TOPIC_PAYMENT_FAILED = 'payment.failed';

    public ?int $id = null;
    public string $name = '';
    public string $url = '';
    public array $topics = [];
    public ?string $secret = null;
    public ?int $storeId = null;
    public bool $enabled = true;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @return string[]
     */
    public static function allTopics(): array
    {
        return [
            self::TOPIC_CART_CREATED,
            self::TOPIC_CART_UPDATED,
            self::TOPIC_CART_COMPLETED,
            self::TOPIC_ORDER_PAID,
            self::TOPIC_ORDER_STATUS_CHANGED,
            self::TOPIC_PAYMENT_FAILED,
        ];
    }

    public static function generateSecret(): string
    {
        return 'whsec_' . StringHelper::randomString(40);
    }

    public function rules(): array
    {
        return [
            [['name', 'url'], 'required'],
            [['url'], 'url', 'defaultScheme' => 'https'],
            [['topics'], 'validateTopics'],
            [['url'], 'validateTarget'],
        ];
    }

    /**
     * Refused at save as well as at delivery, so a merchant finds out now rather than from an
     * empty delivery history.
     */
    public function validateTarget(string $attribute): void
    {
        $target = Plugin::getInstance()->getWebhooks()->resolveTarget((string)$this->$attribute, false);

        if (is_string($target)) {
            $this->addError($attribute, $target);
        }
    }

    public function validateTopics(string $attribute): void
    {
        foreach ($this->topics as $topic) {
            if (!in_array($topic, self::allTopics(), true)) {
                $this->addError($attribute, "Unknown topic “{$topic}”.");
            }
        }
    }

    public function wants(string $topic): bool
    {
        return $this->enabled && in_array($topic, $this->topics, true);
    }
}
