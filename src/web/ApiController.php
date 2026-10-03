<?php

namespace justinholtweb\headdy\web;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\Json;
use craft\web\Controller;
use craft\web\Response;
use justinholtweb\headdy\errors\ApiException;
use justinholtweb\headdy\models\Settings;
use justinholtweb\headdy\Plugin;
use Throwable;
use yii\base\Action;
use yii\base\InvalidRouteException;
use yii\web\BadRequestHttpException;

/**
 * The base every storefront endpoint extends.
 *
 * ## What it deliberately does *not* do
 *
 * - **No CSRF token.** A CSRF token defends a session cookie the browser attaches automatically.
 *   Headdy has no cookie: the credential is a bearer token the client chooses to send, which is
 *   the same defence CSRF provides. Demanding a token here would only mean an extra round trip to
 *   fetch one, which is exactly the friction that makes headless Commerce painful today.
 * - **No login.** `Craft::$app->getUser()` stays anonymous for the whole request even when a
 *   customer token was supplied — see {@see \justinholtweb\headdy\services\RequestContext}.
 * - **No session.** Nothing here touches the session, so no `PHPSESSID` is issued and the
 *   responses are safe for a CDN to treat as uncacheable-but-cookieless.
 *
 * ## What it does do
 *
 * Resolves CORS, parses the JSON body, authenticates the key, applies the rate limit, resolves the
 * cart and customer tokens into the request context, and turns every {@see ApiException} into the
 * one documented error envelope. Endpoints below it are almost entirely free of plumbing.
 */
abstract class ApiController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = true;

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    /**
     * @var array|null The decoded JSON request body.
     */
    private ?array $_body = null;

    /**
     * @var float
     */
    private float $_startedAt = 0.0;

    /**
     * @var string|null
     */
    private ?string $_allowOrigin = null;

    /**
     * The scope this controller's actions need, if any.
     */
    protected function requiredScope(?string $actionId = null): ?string
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function runAction($id, $params = []): mixed
    {
        $this->_startedAt = microtime(true);

        try {
            return parent::runAction($id, $params);
        } catch (ApiException $e) {
            return $this->failure($e);
        } catch (BadRequestHttpException | InvalidRouteException $e) {
            return $this->failure(new ApiException(ApiException::INVALID_REQUEST, $e->getMessage(), 400));
        } catch (\yii\web\NotFoundHttpException $e) {
            return $this->failure(ApiException::notFound($e->getMessage() ?: Craft::t('headdy', 'Not found.')));
        } catch (\yii\web\ForbiddenHttpException $e) {
            return $this->failure(ApiException::forbidden($e->getMessage()));
        } catch (Throwable $e) {
            Craft::$app->getErrorHandler()->logException($e);

            // The message is only echoed back when Craft is in dev mode; on production the caller
            // gets a code and nothing that describes the internals.
            $devMode = Craft::$app->getConfig()->getGeneral()->devMode;

            return $this->failure(new ApiException(
                'server_error',
                $devMode ? $e->getMessage() : Craft::t('headdy', 'Something went wrong.'),
                500,
            ));
        }
    }

    /**
     * @inheritdoc
     * @throws ApiException
     */
    public function beforeAction($action): bool
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $plugin->getRequestContext()->markPublic();
        $this->_resolveOrigin($settings);

        // Preflight is answered before anything else: a browser sends no key, no token and no body
        // with an OPTIONS request, so authenticating it would fail every CORS check in existence.
        if ($this->request->getIsOptions()) {
            Craft::$app->end(0, $this->preflight());
        }

        if (!$settings->enabled) {
            throw new ApiException(ApiException::DISABLED, Craft::t('headdy', 'The storefront API is turned off.'), 503);
        }

        if (!Plugin::commerceIsReady()) {
            throw new ApiException(
                ApiException::COMMERCE_UNAVAILABLE,
                Craft::t('headdy', 'Craft Commerce is not available.'),
                503,
            );
        }

        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->_authenticate($settings);
        $this->_enforceRateLimit();
        $this->_authorize($action);
        $this->_resolveCustomer();
        $this->_resolveCart();

        return true;
    }

    // Request reading
    // =========================================================================

    /**
     * The decoded JSON body.
     *
     * Parsed here rather than through `getBodyParams()` because Craft registers no JSON parser by
     * default — a site would have to add one to `config/app.php` for `getBodyParams()` to see a
     * JSON body at all, and an API that only works after the merchant edits `app.php` is not an
     * API. Form-encoded bodies still work, so an existing Twig form can post here unchanged.
     *
     * @throws ApiException
     */
    protected function body(): array
    {
        if ($this->_body !== null) {
            return $this->_body;
        }

        $raw = $this->request->getRawBody();

        if (trim($raw) === '') {
            return $this->_body = $this->request->getBodyParams();
        }

        if (!str_contains((string)$this->request->getContentType(), 'json')) {
            return $this->_body = $this->request->getBodyParams();
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new ApiException(
                ApiException::INVALID_JSON,
                Craft::t('headdy', 'The request body is not valid JSON: {error}', ['error' => json_last_error_msg()]),
                400,
            );
        }

        return $this->_body = $decoded;
    }

    /**
     * A value from the JSON body, or a query param, or a default.
     *
     * @throws ApiException
     */
    protected function param(string $name, mixed $default = null): mixed
    {
        $body = $this->body();

        if (array_key_exists($name, $body)) {
            return $body[$name];
        }

        return $this->request->getQueryParam($name, $default);
    }

    /**
     * Whether a key was supplied at all — the difference between "clear this" and "leave it".
     *
     * @throws ApiException
     */
    protected function hasParam(string $name): bool
    {
        return array_key_exists($name, $this->body()) || $this->request->getQueryParam($name) !== null;
    }

    /**
     * The cart this request is about, from the token.
     *
     * @throws ApiException
     */
    protected function requireCart(): Order
    {
        $cart = Plugin::getInstance()->getRequestContext()->getCart();

        if ($cart === null) {
            $token = $this->cartToken();

            if ($token !== null && Plugin::getInstance()->getTokens()->cartTokenExists($token)) {
                throw new ApiException(
                    ApiException::CART_TOKEN_EXPIRED,
                    Craft::t('headdy', 'That cart token has expired. Start a new cart.'),
                    401,
                );
            }

            throw ApiException::notFound(
                Craft::t('headdy', 'No cart. Send a valid cart token, or create one first.'),
                ApiException::CART_NOT_FOUND,
            );
        }

        return $cart;
    }

    /**
     * @throws ApiException
     */
    protected function requireCustomer(): \craft\elements\User
    {
        $customer = Plugin::getInstance()->getRequestContext()->getCustomer();

        if ($customer === null) {
            throw ApiException::unauthorized(
                Craft::t('headdy', 'A customer token is required for this endpoint.'),
                ApiException::CUSTOMER_TOKEN_EXPIRED,
            );
        }

        return $customer;
    }

    /**
     * @throws ApiException
     */
    protected function requirePro(): void
    {
        if (!Plugin::getInstance()->isPro()) {
            throw ApiException::forbidden(
                Craft::t('headdy', 'This endpoint requires Headdy Pro.'),
                ApiException::PRO_REQUIRED,
            );
        }
    }

    protected function cartToken(): ?string
    {
        return $this->_bearer('X-Headdy-Cart', \justinholtweb\headdy\services\Tokens::CART_PREFIX);
    }

    protected function customerToken(): ?string
    {
        return $this->_bearer('X-Headdy-Customer', \justinholtweb\headdy\services\Tokens::CUSTOMER_PREFIX);
    }

    // Responses
    // =========================================================================

    protected function success(array $data = [], int $statusCode = 200): Response
    {
        return $this->respond($data + ['success' => true], $statusCode);
    }

    protected function failure(ApiException $e): Response
    {
        return $this->respond($e->toArray() + ['success' => false], $e->statusCode, $e->errorCode);
    }

    protected function respond(array $data, int $statusCode = 200, ?string $errorCode = null): Response
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_JSON;
        $response->setStatusCode($statusCode);
        $response->data = $data;

        $this->applyCorsHeaders($response);

        // A storefront response is per-token and never shared. Saying so explicitly stops a CDN or
        // a browser cache from serving one shopper's cart to the next.
        $response->getHeaders()
            ->set('Cache-Control', 'no-store, private')
            ->set('X-Content-Type-Options', 'nosniff')
            ->set('X-Headdy-Version', Plugin::getInstance()->getVersion());

        $this->_log($statusCode, $errorCode, $data);

        return $response;
    }

    protected function preflight(): Response
    {
        $response = Craft::$app->getResponse();
        $response->setStatusCode(204);
        $response->format = Response::FORMAT_RAW;
        $response->content = '';

        $this->applyCorsHeaders($response);

        $response->getHeaders()
            ->set('Access-Control-Allow-Methods', 'GET, POST, PATCH, PUT, DELETE, OPTIONS')
            ->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Headdy-Key, X-Headdy-Secret, X-Headdy-Cart, X-Headdy-Customer, X-Headdy-Store, X-Headdy-Site')
            ->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    protected function applyCorsHeaders(\yii\web\Response $response): void
    {
        $headers = $response->getHeaders();

        // Always, whether or not an origin matched: the response body genuinely does vary by
        // origin, and a cache that misses this will hand a rejected origin's response to an
        // allowed one.
        $headers->set('Vary', 'Origin');

        if ($this->_allowOrigin === null) {
            return;
        }

        $headers->set('Access-Control-Allow-Origin', $this->_allowOrigin);
        $headers->set('Access-Control-Expose-Headers', 'X-Headdy-Version, X-Headdy-RateLimit-Remaining, Retry-After');

        if ($this->_allowOrigin !== '*' && Plugin::getInstance()->getSettings()->allowCredentials) {
            $headers->set('Access-Control-Allow-Credentials', 'true');
        }
    }

    // Internals
    // =========================================================================

    private function _resolveOrigin(Settings $settings): void
    {
        $origin = $this->request->getHeaders()->get('Origin');
        Plugin::getInstance()->getRequestContext()->setOrigin($origin);

        if ($origin === null || $origin === '') {
            // No Origin header means a server-to-server call. CORS has nothing to say about it.
            return;
        }

        $origin = rtrim($origin, '/');
        $allowed = $settings->getAllowedOrigins();

        // An empty list reflects whatever asked. CORS is not what protects this API — the bearer
        // token is, and no cookie is ever sent — so a locked-down default would only mean every
        // new install starts broken. The diagnostics screen flags it as worth tightening.
        if (!$allowed) {
            $this->_allowOrigin = $origin;
            return;
        }

        if (in_array('*', $allowed, true)) {
            $this->_allowOrigin = $settings->allowCredentials ? $origin : '*';
            return;
        }

        foreach ($allowed as $candidate) {
            if (strcasecmp($candidate, $origin) === 0) {
                $this->_allowOrigin = $origin;
                return;
            }
        }
    }

    /**
     * @throws ApiException
     */
    private function _authenticate(Settings $settings): void
    {
        if ($settings->authMode === Settings::AUTH_MODE_OPEN) {
            return;
        }

        $publicKey = (string)($this->request->getHeaders()->get('X-Headdy-Key') ?? '');

        if ($publicKey === '') {
            throw ApiException::unauthorized(Craft::t('headdy', 'An API key is required. Send it in the X-Headdy-Key header.'));
        }

        $key = Plugin::getInstance()->getKeys()->getKeyByPublicKey($publicKey);

        if ($key === null || !$key->isUsable()) {
            throw ApiException::unauthorized(Craft::t('headdy', 'That API key is not valid.'));
        }

        if ($settings->authMode === Settings::AUTH_MODE_SECRET) {
            $secret = (string)($this->request->getHeaders()->get('X-Headdy-Secret') ?? '');

            if ($secret === '' || !Plugin::getInstance()->getKeys()->verifySecret($key, $secret)) {
                throw ApiException::unauthorized(Craft::t('headdy', 'That API key secret is not valid.'));
            }
        }

        // A key may narrow the plugin-wide origin list, never widen it.
        $origin = Plugin::getInstance()->getRequestContext()->getOrigin();

        if ($origin !== null && $origin !== '' && !$key->allowsOrigin($origin)) {
            throw ApiException::forbidden(Craft::t('headdy', 'That API key may not be used from this origin.'));
        }

        Plugin::getInstance()->getRequestContext()->setKey($key);
        Plugin::getInstance()->getKeys()->touch($key);
    }

    /**
     * @throws ApiException
     */
    private function _enforceRateLimit(): void
    {
        $context = Plugin::getInstance()->getRequestContext();
        $key = $context->getKey();
        $limit = $key->rateLimit ?? Plugin::getInstance()->getSettings()->rateLimit;

        if (!$limit) {
            return;
        }

        $identity = $key !== null ? 'k' . $key->id : 'ip' . $this->request->getUserIP();
        $remaining = RateLimiter::hit($identity, $limit);

        Craft::$app->getResponse()->getHeaders()->set('X-Headdy-RateLimit-Remaining', (string)max(0, $remaining));

        if ($remaining < 0) {
            Craft::$app->getResponse()->getHeaders()->set('Retry-After', '60');

            throw new ApiException(
                ApiException::RATE_LIMITED,
                Craft::t('headdy', 'Too many requests. Try again shortly.'),
                429,
            );
        }
    }

    /**
     * @throws ApiException
     */
    private function _authorize(Action $action): void
    {
        $scope = $this->requiredScope($action->id);

        if ($scope === null) {
            return;
        }

        if (!Plugin::getInstance()->getRequestContext()->hasScope($scope)) {
            throw ApiException::forbidden(
                Craft::t('headdy', 'This API key does not have the “{scope}” scope.', ['scope' => $scope]),
            );
        }
    }

    private function _resolveCustomer(): void
    {
        $token = $this->customerToken();

        if ($token === null) {
            return;
        }

        $user = Plugin::getInstance()->getTokens()->getUserByCustomerToken($token);
        Plugin::getInstance()->getRequestContext()->setCustomer($user);
    }

    private function _resolveCart(): void
    {
        $token = $this->cartToken();

        if ($token === null) {
            return;
        }

        $tokens = Plugin::getInstance()->getTokens();
        $record = $tokens->getCartTokenRecord($token);

        if ($record === null) {
            return;
        }

        $cart = $tokens->getCartByToken($token);

        if ($cart === null) {
            return;
        }

        // A key tied to one store only ever sees that store's carts, whichever key issued the
        // token. Treated as not found rather than forbidden: from this key's side, it isn't there.
        $keyStoreId = Plugin::getInstance()->getRequestContext()->getKey()?->storeId;

        if ($keyStoreId !== null && (int)$cart->storeId !== $keyStoreId) {
            return;
        }

        $tokens->touchCartToken($record);
        Plugin::getInstance()->getRequestContext()->setCart($cart, $token, $record);
    }

    /**
     * Reads a token from its own header, or from `Authorization: Bearer …`.
     *
     * The bearer header is shared by both token kinds, so the prefix decides which one it is —
     * that is the whole reason cart and customer tokens carry different prefixes.
     */
    private function _bearer(string $header, string $prefix): ?string
    {
        $value = $this->request->getHeaders()->get($header);

        if (is_string($value) && $value !== '') {
            return trim($value);
        }

        $auth = (string)($this->request->getHeaders()->get('Authorization') ?? '');

        if (stripos($auth, 'bearer ') === 0) {
            $candidate = trim(substr($auth, 7));

            if (str_starts_with($candidate, $prefix)) {
                return $candidate;
            }
        }

        return null;
    }

    private function _log(int $statusCode, ?string $errorCode, array $data): void
    {
        $context = Plugin::getInstance()->getRequestContext();

        Plugin::getInstance()->getLog()->record([
            'keyId' => $context->getKey()?->id,
            'method' => $this->request->getMethod(),
            'path' => $this->request->getFullPath(),
            'statusCode' => $statusCode,
            'durationMs' => $this->_startedAt > 0 ? (int)round((microtime(true) - $this->_startedAt) * 1000) : null,
            'ip' => $this->request->getUserIP(),
            'origin' => $context->getOrigin(),
            'orderId' => $context->getCart()?->id,
            'errorCode' => $errorCode,
            'request' => $this->_body !== null ? Json::encode($this->_body) : null,
            'response' => Json::encode($data),
        ]);
    }
}
