<?php

namespace justinholtweb\headdy\services;

use craft\commerce\elements\Order;
use craft\elements\User;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\records\CartTokenRecord;
use yii\base\Component;

/**
 * Who is calling, for the length of one request.
 *
 * The API deliberately does not log anyone in. `Craft::$app->getUser()` stays anonymous for every
 * API request — there is no session, no cookie and no identity switch — so a plugin listening on an
 * element save sees exactly what it would see for a guest, and a bug here cannot escalate into a
 * control-panel session.
 *
 * Everything that needs to know "is this caller authenticated, and as whom" reads it from here
 * instead. Populated by {@see \justinholtweb\headdy\web\ApiController::beforeAction()} and never
 * written from anywhere else.
 */
class RequestContext extends Component
{
    private ?ApiKey $_key = null;
    private ?User $_customer = null;
    private ?Order $_cart = null;
    private ?CartTokenRecord $_cartTokenRecord = null;
    private ?string $_cartToken = null;
    private ?string $_origin = null;

    public function setKey(?ApiKey $key): void
    {
        $this->_key = $key;
    }

    public function getKey(): ?ApiKey
    {
        return $this->_key;
    }

    /**
     * The user a customer token resolved to, or null for an anonymous caller.
     */
    public function setCustomer(?User $customer): void
    {
        $this->_customer = $customer;
    }

    public function getCustomer(): ?User
    {
        return $this->_customer;
    }

    public function setCart(?Order $cart, ?string $token = null, ?CartTokenRecord $record = null): void
    {
        $this->_cart = $cart;
        $this->_cartToken = $token;
        $this->_cartTokenRecord = $record;
    }

    public function getCart(): ?Order
    {
        return $this->_cart;
    }

    public function getCartToken(): ?string
    {
        return $this->_cartToken;
    }

    public function getCartTokenRecord(): ?CartTokenRecord
    {
        return $this->_cartTokenRecord;
    }

    public function setOrigin(?string $origin): void
    {
        $this->_origin = $origin;
    }

    public function getOrigin(): ?string
    {
        return $this->_origin;
    }

    /**
     * Whether the caller has a scope. With no key at all — `authMode: open` — everything is
     * permitted, because there is nothing to scope.
     */
    public function hasScope(string $scope): bool
    {
        return $this->_key === null || $this->_key->hasScope($scope);
    }

    /**
     * Clears everything. Only the test suite needs this; a real request gets a fresh component.
     */
    public function reset(): void
    {
        $this->_key = null;
        $this->_customer = null;
        $this->_cart = null;
        $this->_cartToken = null;
        $this->_cartTokenRecord = null;
        $this->_origin = null;
    }
}
