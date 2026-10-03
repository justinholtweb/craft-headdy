<?php

namespace justinholtweb\headdy\services;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\records\ApiKeyRecord;
use yii\base\Component;

/**
 * API keys.
 *
 * The one invariant: a secret is hashed on the way in and compared with `password_verify()` on the
 * way out. There is no code path that reads a secret back, which is why `save()` returns the
 * plaintext once and never again.
 */
class Keys extends Component
{
    /**
     * @var array<int, ApiKey>|null
     */
    private ?array $_keys = null;

    /**
     * @return ApiKey[]
     */
    public function getAllKeys(): array
    {
        if ($this->_keys === null) {
            $this->_keys = [];

            foreach (ApiKeyRecord::find()->orderBy(['name' => SORT_ASC])->all() as $record) {
                $key = $this->_toModel($record);
                $this->_keys[$key->id] = $key;
            }
        }

        return $this->_keys;
    }

    public function getKeyById(int $id): ?ApiKey
    {
        return $this->getAllKeys()[$id] ?? null;
    }

    public function getKeyByPublicKey(string $publicKey): ?ApiKey
    {
        foreach ($this->getAllKeys() as $key) {
            // Constant-time even though the public half is not a secret: it keeps the comparison
            // rules the same everywhere so nobody "optimises" the secret path to match.
            if (hash_equals($key->publicKey, $publicKey)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Whether a plaintext secret matches this key.
     */
    public function verifySecret(ApiKey $key, string $secret): bool
    {
        if ($key->secretHash === null || $key->secretHash === '') {
            return false;
        }

        return Craft::$app->getSecurity()->validatePassword($secret, $key->secretHash);
    }

    /**
     * Saves a key. On a new key — or when `$rotateSecret` is set — a fresh secret is generated and
     * left on the returned model's `secret` property. That is the only time it exists in plaintext.
     */
    public function saveKey(ApiKey $key, bool $rotateSecret = false): bool
    {
        if (!$key->validate()) {
            return false;
        }

        $isNew = $key->id === null;
        $record = $isNew ? new ApiKeyRecord() : ApiKeyRecord::findOne($key->id);

        if ($record === null) {
            return false;
        }

        if ($isNew && $key->publicKey === '') {
            $key->publicKey = ApiKey::generatePublicKey();
        }

        if ($isNew || $rotateSecret) {
            $key->secret = ApiKey::generateSecret();
            $key->secretHash = Craft::$app->getSecurity()->hashPassword($key->secret);
        }

        $record->name = $key->name;
        $record->publicKey = $key->publicKey;
        $record->secretHash = $key->secretHash;
        $record->scopes = Json::encode(array_values($key->scopes));
        $record->origins = Json::encode(array_values($key->origins));
        $record->storeId = $key->storeId;
        $record->rateLimit = $key->rateLimit;
        $record->enabled = $key->enabled;
        $record->expiryDate = Db::prepareDateForDb($key->expiryDate);

        if (!$record->save()) {
            $key->addErrors($record->getErrors());
            return false;
        }

        $key->id = $record->id;
        $key->uid = $record->uid;
        $this->_keys = null;

        return true;
    }

    public function deleteKeyById(int $id): bool
    {
        $record = ApiKeyRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        $record->delete();
        $this->_keys = null;

        return true;
    }

    /**
     * Records that a key was used.
     *
     * Written straight to the table rather than through `saveKey()`: this fires on every single API
     * request, and a full model save would rewrite the secret hash and bump `dateUpdated` for what
     * is really just a timestamp.
     */
    public function touch(ApiKey $key): void
    {
        if ($key->id === null) {
            return;
        }

        Craft::$app->getDb()->createCommand()
            ->update(
                ApiKeyRecord::tableName(),
                ['lastUsedAt' => Db::prepareDateForDb(new DateTime())],
                ['id' => $key->id],
            )
            ->execute();

        $key->lastUsedAt = new DateTime();
    }

    private function _toModel(ApiKeyRecord $record): ApiKey
    {
        return new ApiKey([
            'id' => (int)$record->id,
            'name' => (string)$record->name,
            'publicKey' => (string)$record->publicKey,
            'secretHash' => $record->secretHash,
            'scopes' => $this->_decodeList($record->scopes),
            'origins' => $this->_decodeList($record->origins),
            'storeId' => $record->storeId !== null ? (int)$record->storeId : null,
            'rateLimit' => $record->rateLimit !== null ? (int)$record->rateLimit : null,
            'enabled' => (bool)$record->enabled,
            // Date columns come back as bare UTC strings; `new DateTime()` would read them in the
            // app's time zone and silently shift every timestamp.
            'lastUsedAt' => self::_date($record->lastUsedAt),
            'expiryDate' => self::_date($record->expiryDate),
            'dateCreated' => self::_date($record->dateCreated),
            'dateUpdated' => self::_date($record->dateUpdated),
            'uid' => $record->uid,
        ]);
    }

    /**
     * A UTC database string as a DateTime, or null.
     */
    private static function _date(mixed $value): ?DateTime
    {
        if (empty($value)) {
            return null;
        }

        return DateTimeHelper::toDateTime($value, false) ?: null;
    }

    /**
     * @return string[]
     */
    private function _decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = Json::decodeIfJson($value);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
