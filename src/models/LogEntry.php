<?php

namespace justinholtweb\headdy\models;

use craft\base\Model;
use DateTime;

/**
 * One row of the request log.
 */
class LogEntry extends Model
{
    public ?int $id = null;
    public ?int $keyId = null;
    public ?string $keyName = null;
    public string $method = 'GET';
    public string $path = '';
    public int $statusCode = 200;
    public ?int $durationMs = null;
    public ?string $ip = null;
    public ?string $origin = null;
    public ?int $orderId = null;
    public ?string $errorCode = null;
    public ?string $request = null;
    public ?string $response = null;
    public ?DateTime $dateCreated = null;

    public function isFailure(): bool
    {
        return $this->statusCode >= 400;
    }

    /**
     * The pretty-printed request body, if one was kept and it parses.
     */
    public function getPrettyRequest(): ?string
    {
        return self::pretty($this->request);
    }

    public function getPrettyResponse(): ?string
    {
        return self::pretty($this->response);
    }

    private static function pretty(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        if ($decoded === null) {
            return $json;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
