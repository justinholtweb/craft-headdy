<?php

namespace justinholtweb\headdy\controllers;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use justinholtweb\headdy\models\Webhook;
use justinholtweb\headdy\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Webhook endpoint management.
 */
class WebhooksController extends Controller
{
    /**
     * @throws ForbiddenHttpException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_WEBHOOKS);

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(Craft::t('headdy', 'Webhooks require Headdy Pro.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('headdy/webhooks/_index', [
            'webhooks' => Plugin::getInstance()->getWebhooks()->getAllWebhooks(),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionEdit(?int $webhookId = null, ?Webhook $webhook = null): Response
    {
        if ($webhook === null) {
            if ($webhookId !== null) {
                $webhook = Plugin::getInstance()->getWebhooks()->getWebhookById($webhookId);

                if ($webhook === null) {
                    throw new NotFoundHttpException('No such webhook.');
                }
            } else {
                $webhook = new Webhook(['topics' => Webhook::allTopics()]);
            }
        }

        return $this->renderTemplate('headdy/webhooks/_edit', [
            'webhook' => $webhook,
            'isNew' => $webhook->id === null,
            'deliveries' => $webhook->id ? Plugin::getInstance()->getWebhooks()->getDeliveries($webhook->id, 20) : [],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $service = Plugin::getInstance()->getWebhooks();
        $webhookId = $this->request->getBodyParam('webhookId');
        $webhook = $webhookId ? $service->getWebhookById((int)$webhookId) : new Webhook();

        if ($webhook === null) {
            throw new NotFoundHttpException('No such webhook.');
        }

        $webhook->name = (string)$this->request->getBodyParam('name', $webhook->name);
        $webhook->url = (string)$this->request->getBodyParam('url', $webhook->url);
        $webhook->topics = array_values((array)$this->request->getBodyParam('topics', []));
        $webhook->enabled = (bool)$this->request->getBodyParam('enabled', true);

        $storeId = $this->request->getBodyParam('storeId');
        $webhook->storeId = $storeId !== null && $storeId !== '' ? (int)$storeId : null;

        if ((bool)$this->request->getBodyParam('rotateSecret')) {
            $webhook->secret = Webhook::generateSecret();
        }

        if (!$service->saveWebhook($webhook)) {
            $this->setFailFlash(Craft::t('headdy', 'Could not save the webhook.'));
            Craft::$app->getUrlManager()->setRouteParams(['webhook' => $webhook]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('headdy', 'Webhook saved.'));

        return $this->redirect('headdy/webhooks');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)$this->request->getRequiredBodyParam('id');

        return $this->asSuccess(data: ['deleted' => Plugin::getInstance()->getWebhooks()->deleteWebhookById($id)]);
    }

    /**
     * Sends a sample payload, so a merchant can confirm the receiver works before an order depends
     * on it.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $service = Plugin::getInstance()->getWebhooks();
        $webhook = $service->getWebhookById((int)$this->request->getRequiredBodyParam('id'));

        if ($webhook === null) {
            throw new NotFoundHttpException('No such webhook.');
        }

        $result = $service->deliver($webhook, 'test', [
            'message' => 'This is a test delivery from Headdy.',
            'site' => Craft::$app->getSites()->getPrimarySite()->getBaseUrl(),
        ]);

        return $result['success']
            ? $this->asSuccess(Craft::t('headdy', 'Delivered ({status}).', ['status' => $result['statusCode']]))
            : $this->asFailure(Craft::t('headdy', 'Failed: {error}', ['error' => $result['error'] ?? 'unknown']));
    }
}
