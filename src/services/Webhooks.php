<?php

namespace justinholtweb\headdy\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use justinholtweb\headdy\db\Table;
use justinholtweb\headdy\models\Webhook;
use justinholtweb\headdy\Plugin;
use justinholtweb\headdy\queue\jobs\SendWebhook;
use justinholtweb\headdy\records\WebhookDeliveryRecord;
use justinholtweb\headdy\records\WebhookRecord;
use yii\base\Component;

/**
 * Outbound webhooks.
 *
 * A headless storefront's back office lives somewhere else — a Slack channel, a fulfilment app, a
 * revalidation hook on the Next.js host. Webhooks are how it hears about an order without polling.
 *
 * Deliveries are queued, never sent inline: an unreachable endpoint must not be able to hold a
 * customer's checkout open while it times out.
 */
class Webhooks extends Component
{
    /**
     * @var Webhook[]|null
     */
    private ?array $_webhooks = null;

    /**
     * @return Webhook[]
     */
    public function getAllWebhooks(): array
    {
        if ($this->_webhooks === null) {
            $this->_webhooks = [];

            foreach (WebhookRecord::find()->orderBy(['name' => SORT_ASC])->all() as $record) {
                $webhook = $this->_toModel($record);
                $this->_webhooks[$webhook->id] = $webhook;
            }
        }

        return $this->_webhooks;
    }

    public function getWebhookById(int $id): ?Webhook
    {
        return $this->getAllWebhooks()[$id] ?? null;
    }

    public function saveWebhook(Webhook $webhook): bool
    {
        if (!$webhook->validate()) {
            return false;
        }

        $record = $webhook->id === null ? new WebhookRecord() : WebhookRecord::findOne($webhook->id);

        if ($record === null) {
            return false;
        }

        if ($webhook->secret === null || $webhook->secret === '') {
            $webhook->secret = Webhook::generateSecret();
        }

        $record->name = $webhook->name;
        $record->url = $webhook->url;
        $record->topics = Json::encode(array_values($webhook->topics));
        $record->secret = $webhook->secret;
        $record->storeId = $webhook->storeId;
        $record->enabled = $webhook->enabled;

        if (!$record->save()) {
            $webhook->addErrors($record->getErrors());
            return false;
        }

        $webhook->id = $record->id;
        $webhook->uid = $record->uid;
        $this->_webhooks = null;

        return true;
    }

    public function deleteWebhookById(int $id): bool
    {
        $record = WebhookRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        $record->delete();
        $this->_webhooks = null;

        return true;
    }

    /**
     * Queues a delivery to every endpoint subscribed to this topic.
     *
     * Silently does nothing on Lite, and nothing when no endpoint wants the topic — callers fire
     * events unconditionally rather than each checking first.
     */
    public function dispatch(string $topic, array $payload, ?int $storeId = null): int
    {
        if (!Plugin::getInstance()->isPro()) {
            return 0;
        }

        $queued = 0;

        foreach ($this->getAllWebhooks() as $webhook) {
            if (!$webhook->wants($topic)) {
                continue;
            }

            if ($webhook->storeId !== null && $storeId !== null && $webhook->storeId !== $storeId) {
                continue;
            }

            Craft::$app->getQueue()->push(new SendWebhook([
                'webhookId' => $webhook->id,
                'topic' => $topic,
                'payload' => $payload,
            ]));

            $queued++;
        }

        return $queued;
    }

    /**
     * Signs a payload.
     *
     * `t=<unix>,v1=<hmac>` over `<unix>.<body>`, the same construction Stripe uses — the timestamp
     * is inside the signed material, so a captured delivery cannot be replayed later against a
     * receiver that checks the age.
     */
    public function sign(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Sends one delivery, synchronously. Called by the queue job, and by the CP "Send test".
     *
     * @return array{success: bool, statusCode: int|null, error: string|null}
     */
    public function deliver(Webhook $webhook, string $topic, array $payload, int $attempt = 1): array
    {
        $body = Json::encode([
            'topic' => $topic,
            'timestamp' => (new DateTime())->format(DATE_ATOM),
            'data' => $payload,
        ]);

        $statusCode = null;
        $error = null;
        $success = false;

        try {
            $response = Craft::createGuzzleClient(['timeout' => 10, 'connect_timeout' => 5])
                ->request('POST', $webhook->url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'Headdy/' . Plugin::getInstance()->getVersion(),
                        'X-Headdy-Topic' => $topic,
                        'X-Headdy-Signature' => $this->sign($body, (string)$webhook->secret),
                    ],
                    'body' => $body,
                    'http_errors' => false,
                ]);

            $statusCode = $response->getStatusCode();
            $success = $statusCode >= 200 && $statusCode < 300;

            if (!$success) {
                $error = 'HTTP ' . $statusCode;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->_recordDelivery($webhook, $topic, $body, $statusCode, $attempt, $success, $error);

        return ['success' => $success, 'statusCode' => $statusCode, 'error' => $error];
    }

    /**
     * @return array<WebhookDeliveryRecord|array>
     */
    public function getDeliveries(int $webhookId, int $limit = 50): array
    {
        return WebhookDeliveryRecord::find()
            ->where(['webhookId' => $webhookId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function pruneDeliveries(int $days = 30): int
    {
        $cutoff = Db::prepareDateForDb((new DateTime())->sub(new DateInterval('P' . max(1, $days) . 'D')));

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::WEBHOOK_DELIVERIES, ['<', 'dateCreated', $cutoff])
            ->execute();
    }

    private function _recordDelivery(
        Webhook $webhook,
        string $topic,
        string $body,
        ?int $statusCode,
        int $attempt,
        bool $success,
        ?string $error,
    ): void {
        try {
            Craft::$app->getDb()->createCommand()
                ->insert(Table::WEBHOOK_DELIVERIES, [
                    'webhookId' => $webhook->id,
                    'topic' => $topic,
                    'payload' => mb_substr($body, 0, 60000),
                    'statusCode' => $statusCode,
                    'attempt' => $attempt,
                    'success' => $success,
                    'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
                    'dateCreated' => Db::prepareDateForDb(new DateTime()),
                    'uid' => StringHelper::UUID(),
                ])
                ->execute();
        } catch (\Throwable $e) {
            Craft::warning('Could not record a Headdy webhook delivery: ' . $e->getMessage(), 'headdy');
        }
    }

    private function _toModel(WebhookRecord $record): Webhook
    {
        $topics = $record->topics;

        if (is_string($topics)) {
            $decoded = Json::decodeIfJson($topics);
            $topics = is_array($decoded) ? $decoded : [];
        }

        return new Webhook([
            'id' => (int)$record->id,
            'name' => (string)$record->name,
            'url' => (string)$record->url,
            'topics' => array_values(array_filter((array)$topics, 'is_string')),
            'secret' => $record->secret,
            'storeId' => $record->storeId !== null ? (int)$record->storeId : null,
            'enabled' => (bool)$record->enabled,
            'uid' => $record->uid,
        ]);
    }
}
