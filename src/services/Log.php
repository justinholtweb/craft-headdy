<?php

namespace justinholtweb\headdy\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use DateInterval;
use DateTime;
use justinholtweb\headdy\db\Table;
use justinholtweb\headdy\models\LogEntry;
use justinholtweb\headdy\Plugin;
use yii\base\Component;

/**
 * The request log.
 *
 * The point of this is not auditing, it is debugging somebody else's front end. When an agency's
 * Next.js build says "the cart endpoint returns 422" and nobody can reproduce it, this is where the
 * actual request body is.
 *
 * Bodies are off by default: they contain addresses and email addresses, and a log that quietly
 * accumulates personal data is a liability rather than a feature. Payment fields are stripped even
 * when bodies are on.
 */
class Log extends Component
{
    /**
     * Keys whose values never reach the log, at any nesting depth.
     */
    private const REDACT = [
        'password', 'newpassword', 'currentpassword',
        'number', 'cvv', 'cvc', 'securitycode', 'expiry', 'expirymonth', 'expiryyear',
        'token', 'refreshtoken', 'accesstoken', 'secret', 'apikey', 'authorization',
        'paymentform', 'firstname', 'lastname', 'fullname',
    ];

    public function record(array $attributes): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->logRequests || !Plugin::getInstance()->isPro()) {
            return;
        }

        if (!$settings->logBodies) {
            unset($attributes['request'], $attributes['response']);
        }

        try {
            Craft::$app->getDb()->createCommand()
                ->insert(Table::LOG, [
                    'keyId' => $attributes['keyId'] ?? null,
                    'method' => substr((string)($attributes['method'] ?? 'GET'), 0, 10),
                    'path' => substr((string)($attributes['path'] ?? ''), 0, 255),
                    'statusCode' => (int)($attributes['statusCode'] ?? 200),
                    'durationMs' => $attributes['durationMs'] ?? null,
                    'ip' => $attributes['ip'] ?? null,
                    'origin' => $attributes['origin'] ?? null,
                    'orderId' => $attributes['orderId'] ?? null,
                    'errorCode' => $attributes['errorCode'] ?? null,
                    'request' => isset($attributes['request']) ? $this->redact($attributes['request']) : null,
                    'response' => isset($attributes['response']) ? $this->redact($attributes['response']) : null,
                    'dateCreated' => Db::prepareDateForDb(new DateTime()),
                    'uid' => \craft\helpers\StringHelper::UUID(),
                ])
                ->execute();
        } catch (\Throwable $e) {
            // Logging must never be able to fail a request that otherwise succeeded.
            Craft::warning('Could not write a Headdy log row: ' . $e->getMessage(), 'headdy');
        }
    }

    /**
     * Replaces sensitive values with `[redacted]`, recursively.
     *
     * @param array|string|null $payload
     */
    public function redact(mixed $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        if (is_string($payload)) {
            $decoded = Json::decodeIfJson($payload);
            $payload = is_array($decoded) ? $decoded : ['_raw' => mb_substr($payload, 0, 4000)];
        }

        if (!is_array($payload)) {
            return null;
        }

        return Json::encode($this->_redactArray($payload));
    }

    private function _redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT, true)) {
                $data[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->_redactArray($value);
            }
        }

        return $data;
    }

    /**
     * @return LogEntry[]
     */
    public function getEntries(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $query = $this->_query()->limit($limit)->offset($offset);

        if (!empty($criteria['keyId'])) {
            $query->andWhere(['l.keyId' => $criteria['keyId']]);
        }

        if (!empty($criteria['failuresOnly'])) {
            $query->andWhere(['>=', 'l.statusCode', 400]);
        }

        if (!empty($criteria['path'])) {
            $query->andWhere(['like', 'l.path', $criteria['path']]);
        }

        return array_map(fn(array $row) => $this->_toModel($row), $query->all());
    }

    public function getEntryById(int $id): ?LogEntry
    {
        $row = $this->_query()->andWhere(['l.id' => $id])->one();

        return $row ? $this->_toModel($row) : null;
    }

    public function getTotal(array $criteria = []): int
    {
        $query = (new Query())->from(['l' => Table::LOG]);

        if (!empty($criteria['failuresOnly'])) {
            $query->andWhere(['>=', 'l.statusCode', 400]);
        }

        return (int)$query->count();
    }

    /**
     * Counts by status class over a window, for the CP dashboard.
     *
     * @return array{total: int, ok: int, clientErrors: int, serverErrors: int, avgDurationMs: int}
     */
    public function getSummary(int $hours = 24): array
    {
        $since = Db::prepareDateForDb((new DateTime())->sub(new DateInterval('PT' . max(1, $hours) . 'H')));

        $rows = (new Query())
            ->select(['statusCode', 'durationMs'])
            ->from(Table::LOG)
            ->where(['>=', 'dateCreated', $since])
            ->all();

        $total = count($rows);
        $ok = 0;
        $clientErrors = 0;
        $serverErrors = 0;
        $durations = [];

        foreach ($rows as $row) {
            $code = (int)$row['statusCode'];

            if ($code >= 500) {
                $serverErrors++;
            } elseif ($code >= 400) {
                $clientErrors++;
            } else {
                $ok++;
            }

            if ($row['durationMs'] !== null) {
                $durations[] = (int)$row['durationMs'];
            }
        }

        return [
            'total' => $total,
            'ok' => $ok,
            'clientErrors' => $clientErrors,
            'serverErrors' => $serverErrors,
            'avgDurationMs' => $durations ? (int)round(array_sum($durations) / count($durations)) : 0,
        ];
    }

    /**
     * Deletes rows older than the retention window. Returns the number removed.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = Db::prepareDateForDb((new DateTime())->sub(new DateInterval('P' . $days . 'D')));

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::LOG, ['<', 'dateCreated', $cutoff])
            ->execute();
    }

    public function clear(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }

    private function _query(): Query
    {
        return (new Query())
            ->select([
                'l.id', 'l.keyId', 'l.method', 'l.path', 'l.statusCode', 'l.durationMs',
                'l.ip', 'l.origin', 'l.orderId', 'l.errorCode', 'l.request', 'l.response', 'l.dateCreated',
                'keyName' => 'k.name',
            ])
            ->from(['l' => Table::LOG])
            ->leftJoin(['k' => Table::API_KEYS], '[[k.id]] = [[l.keyId]]')
            ->orderBy(['l.dateCreated' => SORT_DESC, 'l.id' => SORT_DESC]);
    }

    private function _toModel(array $row): LogEntry
    {
        $row['dateCreated'] = !empty($row['dateCreated'])
            ? (DateTimeHelper::toDateTime($row['dateCreated'], false) ?: null)
            : null;

        $row['id'] = (int)$row['id'];
        $row['statusCode'] = (int)$row['statusCode'];
        $row['durationMs'] = $row['durationMs'] !== null ? (int)$row['durationMs'] : null;
        $row['keyId'] = $row['keyId'] !== null ? (int)$row['keyId'] : null;
        $row['orderId'] = $row['orderId'] !== null ? (int)$row['orderId'] : null;

        return new LogEntry($row);
    }
}
