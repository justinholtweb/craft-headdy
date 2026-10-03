<?php

namespace justinholtweb\headdy\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\headdy\Plugin;

/**
 * Delivers one webhook.
 *
 * Failures throw, so Craft's queue applies its own retry and backoff rather than this job growing
 * a second, worse retry loop of its own.
 */
class SendWebhook extends BaseJob
{
    public ?int $webhookId = null;
    public string $topic = '';
    public array $payload = [];
    public ?string $deliveryId = null;

    public function execute($queue): void
    {
        $webhook = Plugin::getInstance()->getWebhooks()->getWebhookById((int)$this->webhookId);

        // The endpoint was deleted between queueing and running. Nothing to deliver and nothing to
        // retry — a throw here would keep a dead job cycling forever.
        if ($webhook === null) {
            return;
        }

        $result = Plugin::getInstance()->getWebhooks()->deliver($webhook, $this->topic, $this->payload, 1, $this->deliveryId);

        if (!$result['success']) {
            throw new \RuntimeException(sprintf(
                'Webhook “%s” failed: %s',
                $webhook->name,
                $result['error'] ?? 'unknown error',
            ));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('headdy', 'Sending the “{topic}” webhook', ['topic' => $this->topic]);
    }
}
