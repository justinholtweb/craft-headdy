<?php

namespace justinholtweb\headdy\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\Plugin;
use yii\console\ExitCode;

/**
 * Manage API keys from the command line — for a deploy script that has to provision a key it can
 * then feed straight into the front end's environment.
 */
class KeysController extends Controller
{
    /**
     * @var string A name for the new key.
     */
    public string $name = 'Storefront';

    /**
     * @var string Comma-separated scopes. Defaults to the storefront set.
     */
    public string $scopes = '';

    /**
     * @var string Comma-separated allowed origins.
     */
    public string $origins = '';

    /**
     * @var int Requests per minute, or 0 for the plugin-wide setting.
     */
    public int $rateLimit = 0;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'create' => ['name', 'scopes', 'origins', 'rateLimit'],
            default => [],
        });
    }

    /**
     * Lists every API key.
     */
    public function actionIndex(): int
    {
        $keys = Plugin::getInstance()->getKeys()->getAllKeys();

        if (!$keys) {
            $this->stdout("No API keys.\n");
            return ExitCode::OK;
        }

        foreach ($keys as $key) {
            $status = !$key->enabled ? 'disabled' : ($key->isExpired() ? 'expired' : 'active');
            $this->stdout(sprintf("%-24s %-42s %-9s %s\n",
                mb_substr($key->name, 0, 23),
                $key->publicKey,
                $status,
                implode(',', $key->scopes),
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Creates a key and prints both halves.
     *
     * The secret is printed here and nowhere else — only its hash is stored. Capture it from the
     * output or rotate the key.
     */
    public function actionCreate(): int
    {
        $key = new ApiKey([
            'name' => $this->name,
            'scopes' => $this->scopes !== ''
                ? array_map('trim', explode(',', $this->scopes))
                : ApiKey::defaultScopes(),
            'origins' => $this->origins !== ''
                ? array_map('trim', explode(',', $this->origins))
                : [],
            'rateLimit' => $this->rateLimit ?: null,
        ]);

        if (!Plugin::getInstance()->getKeys()->saveKey($key)) {
            $this->stderr("Could not create the key:\n", Console::FG_RED);

            foreach ($key->getFirstErrors() as $attribute => $message) {
                $this->stderr("  $attribute: $message\n", Console::FG_RED);
            }

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Created “{$key->name}”.\n\n", Console::FG_GREEN);
        $this->stdout("  Public key: {$key->publicKey}\n");
        $this->stdout("  Secret:     {$key->secret}\n\n");
        $this->stdout("The secret is not stored in plaintext and cannot be shown again.\n", Console::FG_YELLOW);

        return ExitCode::OK;
    }

    /**
     * Rotates a key's secret.
     *
     * @param string $publicKey The key's public half.
     */
    public function actionRotate(string $publicKey): int
    {
        $keys = Plugin::getInstance()->getKeys();
        $key = $keys->getKeyByPublicKey($publicKey);

        if ($key === null) {
            $this->stderr("No key with that public half.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if (!$keys->saveKey($key, true)) {
            $this->stderr("Could not rotate the key.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("  Secret: {$key->secret}\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Deletes a key.
     *
     * @param string $publicKey The key's public half.
     */
    public function actionDelete(string $publicKey): int
    {
        $keys = Plugin::getInstance()->getKeys();
        $key = $keys->getKeyByPublicKey($publicKey);

        if ($key === null) {
            $this->stderr("No key with that public half.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if ($this->interactive && !$this->confirm("Delete “{$key->name}”? Anything using it stops working immediately.")) {
            return ExitCode::OK;
        }

        $keys->deleteKeyById($key->id);
        $this->stdout("Deleted.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
