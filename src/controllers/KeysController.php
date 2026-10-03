<?php

namespace justinholtweb\headdy\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * API key management.
 */
class KeysController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE_KEYS);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('headdy/keys/_index', [
            'keys' => Plugin::getInstance()->getKeys()->getAllKeys(),
            'plugin' => Plugin::getInstance(),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionEdit(?int $keyId = null, ?ApiKey $key = null): Response
    {
        if ($key === null) {
            if ($keyId !== null) {
                $key = Plugin::getInstance()->getKeys()->getKeyById($keyId);

                if ($key === null) {
                    throw new NotFoundHttpException('No such API key.');
                }
            } else {
                $key = new ApiKey(['scopes' => ApiKey::defaultScopes()]);
            }
        }

        return $this->renderTemplate('headdy/keys/_edit', [
            'key' => $key,
            'isNew' => $key->id === null,
            'plugin' => Plugin::getInstance(),
            // Only ever non-null on the redirect straight after a save; see actionSave().
            'newSecret' => Craft::$app->getSession()->getFlash('headdy.secret'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $keys = Plugin::getInstance()->getKeys();
        $keyId = $this->request->getBodyParam('keyId');
        $key = $keyId ? $keys->getKeyById((int)$keyId) : new ApiKey();

        if ($key === null) {
            throw new NotFoundHttpException('No such API key.');
        }

        $isNew = $key->id === null;
        $rotate = (bool)$this->request->getBodyParam('rotateSecret');

        $key->name = (string)$this->request->getBodyParam('name', $key->name);
        $key->enabled = (bool)$this->request->getBodyParam('enabled', true);
        $key->scopes = array_values((array)$this->request->getBodyParam('scopes', []));
        $key->origins = \justinholtweb\headdy\models\Settings::normalizeOrigins($this->request->getBodyParam('origins', []));
        $key->rateLimit = (int)$this->request->getBodyParam('rateLimit', 0) ?: null;

        $storeId = $this->request->getBodyParam('storeId');
        $key->storeId = $storeId !== null && $storeId !== '' ? (int)$storeId : null;

        $expiryDate = $this->request->getBodyParam('expiryDate');
        $key->expiryDate = $expiryDate ? (DateTimeHelper::toDateTime($expiryDate) ?: null) : null;

        if (!$keys->saveKey($key, $rotate)) {
            $this->setFailFlash(Craft::t('headdy', 'Could not save the API key.'));
            Craft::$app->getUrlManager()->setRouteParams(['key' => $key]);

            return null;
        }

        // The plaintext secret exists for exactly one moment. Flashed rather than rendered inline
        // because a save is a redirect, and rendering it any other way would mean storing it.
        if ($key->secret !== null) {
            Craft::$app->getSession()->setFlash('headdy.secret', $key->secret);
        }

        $this->setSuccessFlash(Craft::t('headdy', 'API key saved.'));

        return $this->redirect(($isNew || $rotate) ? "headdy/keys/{$key->id}" : 'headdy/keys');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $keyId = (int)$this->request->getRequiredBodyParam('id');

        return $this->asSuccess(data: ['deleted' => Plugin::getInstance()->getKeys()->deleteKeyById($keyId)]);
    }
}
