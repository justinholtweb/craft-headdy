<?php

namespace justinholtweb\headdy\controllers;

use craft\web\Controller;
use justinholtweb\headdy\Plugin;
use yii\web\Response;

/**
 * The overview screen: is this API going to work, and where is it.
 */
class OverviewController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-headdy');

        $plugin = Plugin::getInstance();
        $diagnostics = $plugin->getDiagnostics();

        return $this->renderTemplate('headdy/overview', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'checks' => $diagnostics->runChecks(),
            'routes' => $diagnostics->routeTable(),
            'apiUrl' => $plugin->getApiUrl(),
            'keys' => $plugin->getKeys()->getAllKeys(),
            'summary' => $plugin->isPro() ? $plugin->getLog()->getSummary() : null,
        ]);
    }
}
