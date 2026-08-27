<?php

namespace justinholtweb\headdy\errors;

use Exception;
use Throwable;

/**
 * A failure a caller is meant to read.
 *
 * Every error the API returns carries a stable machine-readable `code`. Consumers branch on the
 * code, never on the message: the message is translated and may be reworded, the code is contract.
 */
class ApiException extends Exception
{
    public const INVALID_REQUEST = 'invalid_request';
    public const INVALID_JSON = 'invalid_json';
    public const UNAUTHORIZED = 'unauthorized';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';
    public const RATE_LIMITED = 'rate_limited';
    public const DISABLED = 'api_disabled';
    public const COMMERCE_UNAVAILABLE = 'commerce_unavailable';
    public const PRO_REQUIRED = 'pro_required';

    public const CART_NOT_FOUND = 'cart_not_found';
    public const CART_TOKEN_EXPIRED = 'cart_token_expired';
    public const CART_COMPLETED = 'cart_completed';
    public const CART_LOCKED = 'cart_locked';
    public const CART_INVALID = 'cart_invalid';
    public const LINE_ITEM_NOT_FOUND = 'line_item_not_found';
    public const PURCHASABLE_NOT_FOUND = 'purchasable_not_found';
    public const PURCHASABLE_UNAVAILABLE = 'purchasable_unavailable';
    public const COUPON_INVALID = 'coupon_invalid';
    public const SHIPPING_METHOD_UNAVAILABLE = 'shipping_method_unavailable';

    public const CHECKOUT_INCOMPLETE = 'checkout_incomplete';
    public const PAYMENT_FAILED = 'payment_failed';
    public const PAYMENT_GATEWAY_UNAVAILABLE = 'payment_gateway_unavailable';
    public const PAYMENT_AMOUNT_CHANGED = 'payment_amount_changed';
    public const REDIRECT_NOT_ALLOWED = 'redirect_not_allowed';
    public const TRANSACTION_NOT_FOUND = 'transaction_not_found';

    public const CUSTOMER_LOGIN_FAILED = 'customer_login_failed';
    public const CUSTOMER_TOKEN_EXPIRED = 'customer_token_expired';
    public const CUSTOMER_REGISTRATION_DISABLED = 'customer_registration_disabled';
    public const CUSTOMER_EXISTS = 'customer_exists';

    /**
     * @var string The stable error code.
     */
    public string $errorCode;

    /**
     * @var int The HTTP status to answer with.
     */
    public int $statusCode;

    /**
     * @var array<string, string[]> Field-keyed validation messages, if any.
     */
    public array $errors;

    /**
     * @var array<string, mixed> Anything else worth handing back — the cart as it stands after a
     *                           failed mutation, for instance.
     */
    public array $data;

    public function __construct(
        string $errorCode,
        string $message,
        int $statusCode = 400,
        array $errors = [],
        array $data = [],
        ?Throwable $previous = null,
    ) {
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        $this->errors = $errors;
        $this->data = $data;

        parent::__construct($message, 0, $previous);
    }

    public static function notFound(string $message, string $code = self::NOT_FOUND): self
    {
        return new self($code, $message, 404);
    }

    public static function unauthorized(string $message, string $code = self::UNAUTHORIZED): self
    {
        return new self($code, $message, 401);
    }

    public static function forbidden(string $message, string $code = self::FORBIDDEN): self
    {
        return new self($code, $message, 403);
    }

    public static function invalid(string $message, array $errors = [], string $code = self::INVALID_REQUEST): self
    {
        return new self($code, $message, 422, $errors);
    }

    /**
     * The wire shape. Kept in one place so no endpoint can invent its own.
     */
    public function toArray(): array
    {
        $body = [
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ];

        if ($this->errors) {
            $body['error']['errors'] = $this->errors;
        }

        foreach ($this->data as $key => $value) {
            $body[$key] = $value;
        }

        return $body;
    }
}
