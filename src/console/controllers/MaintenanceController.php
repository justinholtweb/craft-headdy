<?php

namespace justinholtweb\headdy\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\headdy\Plugin;
use yii\console\ExitCode;

/**
 * Housekeeping, for a cron entry.
 */
class MaintenanceController extends Controller
{
    /**
     * @var int Days of log to keep. Defaults to the plugin setting.
     */
    public int $days = 0;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'prune-log', 'prune-deliveries' => ['days'],
            default => [],
        });
    }

    /**
     * Runs every housekeeping task. This is the one to put on a schedule.
     */
    public function actionIndex(): int
    {
        $this->actionPurgeTokens();
        $this->actionPruneLog();
        $this->actionPruneDeliveries();

        return ExitCode::OK;
    }

    /**
     * Deletes expired cart and customer tokens.
     */
    public function actionPurgeTokens(): int
    {
        $result = Plugin::getInstance()->getTokens()->purgeExpired();

        $this->stdout("Purged {$result['carts']} cart token(s) and {$result['customers']} customer token(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Signs a customer out of every storefront session, by email, username or user ID.
     *
     * Changing the password does this on its own; this is for when the password is fine but a
     * device was lost.
     */
    public function actionRevokeCustomer(string $user): int
    {
        $users = Craft::$app->getUsers();
        $found = ctype_digit($user) ? $users->getUserById((int)$user) : $users->getUserByUsernameOrEmail($user);

        if ($found === null) {
            $this->stderr("No user matches “{$user}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $revoked = Plugin::getInstance()->getTokens()->revokeCustomerTokensForUser((int)$found->id);

        $this->stdout("Revoked $revoked customer token(s) for {$found->email}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Deletes log rows past the retention window.
     */
    public function actionPruneLog(): int
    {
        $deleted = Plugin::getInstance()->getLog()->prune($this->days ?: null);

        $this->stdout("Pruned $deleted log row(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Deletes old webhook delivery records.
     */
    public function actionPruneDeliveries(): int
    {
        $deleted = Plugin::getInstance()->getWebhooks()->pruneDeliveries($this->days ?: 30);

        $this->stdout("Pruned $deleted webhook delivery record(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Prints the configuration check that the Overview screen shows.
     *
     * Exits non-zero if anything is at error level, so it can gate a deploy.
     */
    public function actionCheck(): int
    {
        $checks = Plugin::getInstance()->getDiagnostics()->runChecks();
        $failed = false;

        foreach ($checks as $check) {
            $colour = match ($check['level']) {
                'ok' => Console::FG_GREEN,
                'warning' => Console::FG_YELLOW,
                default => Console::FG_RED,
            };

            $mark = match ($check['level']) {
                'ok' => '✓',
                'warning' => '!',
                default => '✗',
            };

            $this->stdout("  $mark {$check['label']}\n", $colour);

            if (isset($check['detail'])) {
                $this->stdout("    {$check['detail']}\n", Console::FG_GREY);
            }

            $failed = $failed || $check['level'] === 'error';
        }

        $this->stdout("\nAPI root: " . Plugin::getInstance()->getApiUrl() . "\n");

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Prints the route table.
     */
    public function actionRoutes(): int
    {
        foreach (Plugin::getInstance()->getDiagnostics()->routeTable() as $route) {
            $this->stdout(sprintf("  %-16s %s\n", $route['method'], $route['path']));
        }

        return ExitCode::OK;
    }
}
