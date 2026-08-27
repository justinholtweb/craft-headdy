<?php

namespace justinholtweb\headdy\controllers;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use justinholtweb\headdy\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * The request log.
 */
class LogController extends Controller
{
    /**
     * @throws ForbiddenHttpException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW_LOG);

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(Craft::t('headdy', 'The request log requires Headdy Pro.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $log = Plugin::getInstance()->getLog();
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $pageSize = 50;
        $failuresOnly = (bool)$this->request->getQueryParam('failuresOnly');

        $criteria = ['failuresOnly' => $failuresOnly];

        return $this->renderTemplate('headdy/log/_index', [
            'entries' => $log->getEntries($criteria, $pageSize, ($page - 1) * $pageSize),
            'total' => $log->getTotal($criteria),
            'summary' => $log->getSummary(),
            'page' => $page,
            'pageSize' => $pageSize,
            'failuresOnly' => $failuresOnly,
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionDetail(int $entryId): Response
    {
        $entry = Plugin::getInstance()->getLog()->getEntryById($entryId);

        if ($entry === null) {
            throw new NotFoundHttpException('No such log entry.');
        }

        return $this->renderTemplate('headdy/log/_detail', ['entry' => $entry]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return $this->asSuccess(data: ['deleted' => Plugin::getInstance()->getLog()->clear()]);
    }
}
