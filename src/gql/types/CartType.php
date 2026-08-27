<?php

namespace justinholtweb\headdy\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * The GraphQL shape of a cart.
 *
 * Field-for-field the same as the REST payload — the resolvers are literally
 * `fn($cart, $args, $ctx, $info) => $cart[$info->fieldName]` over the array
 * {@see \justinholtweb\headdy\services\Serializer::cart()} already built. Two transports, one
 * shape; if they ever disagree it is a bug in one resolver, not a second serializer.
 */
abstract class CartType
{
    public static function money(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('HeaddyMoney', fn() => new ObjectType([
            'name' => 'HeaddyMoney',
            'description' => 'An amount of money. `amount` is a decimal string, `minorUnits` the integer a payment processor wants.',
            'fields' => [
                'amount' => Type::string(),
                'minorUnits' => Type::int(),
                'currency' => Type::string(),
                'formatted' => Type::string(),
            ],
            'resolveField' => self::arrayResolver(),
        ]));
    }

    public static function address(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('HeaddyAddress', fn() => new ObjectType([
            'name' => 'HeaddyAddress',
            'fields' => [
                'id' => Type::int(),
                'fullName' => Type::string(),
                'firstName' => Type::string(),
                'lastName' => Type::string(),
                'organization' => Type::string(),
                'addressLine1' => Type::string(),
                'addressLine2' => Type::string(),
                'addressLine3' => Type::string(),
                'locality' => Type::string(),
                'dependentLocality' => Type::string(),
                'administrativeArea' => Type::string(),
                'postalCode' => Type::string(),
                'sortingCode' => Type::string(),
                'countryCode' => Type::string(),
                'label' => Type::string(),
            ],
            'resolveField' => self::arrayResolver(),
        ]));
    }

    public static function adjustment(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('HeaddyAdjustment', fn() => new ObjectType([
            'name' => 'HeaddyAdjustment',
            'fields' => [
                'id' => Type::int(),
                'type' => Type::string(),
                'name' => Type::string(),
                'description' => Type::string(),
                'amount' => self::money(),
                'included' => Type::boolean(),
                'lineItemId' => Type::int(),
            ],
            'resolveField' => self::arrayResolver(),
        ]));
    }

    public static function lineItem(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('HeaddyLineItem', fn() => new ObjectType([
            'name' => 'HeaddyLineItem',
            'fields' => [
                'id' => Type::int(),
                'uid' => Type::string(),
                'purchasableId' => Type::int(),
                'sku' => Type::string(),
                'description' => Type::string(),
                'qty' => Type::int(),
                'note' => Type::string(),
                'price' => self::money(),
                'salePrice' => self::money(),
                'promotionalPrice' => self::money(),
                'subtotal' => self::money(),
                'total' => self::money(),
                'onPromotion' => Type::boolean(),
                'hasFreeShipping' => Type::boolean(),
                'isShippable' => Type::boolean(),
                'isTaxable' => Type::boolean(),
                'adjustments' => Type::listOf(self::adjustment()),
            ],
            'resolveField' => self::arrayResolver(),
        ]));
    }

    public static function totals(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('HeaddyTotals', fn() => new ObjectType([
            'name' => 'HeaddyTotals',
            'fields' => [
                'itemSubtotal' => self::money(),
                'itemTotal' => self::money(),
                'shipping' => self::money(),
                'discount' => self::money(),
                'tax' => self::money(),
                'taxIncluded' => self::money(),
                'total' => self::money(),
                'totalPrice' => self::money(),
                'totalPaid' => self::money(),
                'outstandingBalance' => self::money(),
            ],
            'resolveField' => self::arrayResolver(),
        ]));
    }

    public static function shippingMethod(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('HeaddyShippingMethod', fn() => new ObjectType([
            'name' => 'HeaddyShippingMethod',
            'fields' => [
                'handle' => Type::string(),
                'name' => Type::string(),
                'price' => self::money(),
                'matchesOrder' => Type::boolean(),
            ],
            'resolveField' => self::arrayResolver(),
        ]));
    }

    public static function notice(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('HeaddyNotice', fn() => new ObjectType([
            'name' => 'HeaddyNotice',
            'fields' => [
                'type' => Type::string(),
                'attribute' => Type::string(),
                'message' => Type::string(),
            ],
            'resolveField' => self::arrayResolver(),
        ]));
    }

    public static function cart(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('HeaddyCart', fn() => new ObjectType([
            'name' => 'HeaddyCart',
            'description' => 'A Craft Commerce cart or completed order.',
            'fields' => [
                'id' => Type::int(),
                'number' => Type::string(),
                'reference' => Type::string(),
                // Returned on creation and after a mutation that rotates it. Hold it in memory and
                // send it back as the `cartToken` argument on every subsequent call.
                'token' => Type::string(),
                'isCompleted' => Type::boolean(),
                'isPaid' => Type::boolean(),
                'isEmpty' => Type::boolean(),
                'email' => Type::string(),
                'currency' => Type::string(),
                'couponCode' => Type::string(),
                'message' => Type::string(),
                'totalQty' => Type::int(),
                'dateOrdered' => Type::string(),
                'datePaid' => Type::string(),
                'lineItems' => Type::listOf(self::lineItem()),
                'adjustments' => Type::listOf(self::adjustment()),
                'totals' => self::totals(),
                'shippingAddress' => self::address(),
                'billingAddress' => self::address(),
                'shippingMethod' => self::shippingMethod(),
                'gatewayId' => Type::int(),
                'notices' => Type::listOf(self::notice()),
            ],
            'resolveField' => self::arrayResolver(),
        ]));
    }

    public static function checkout(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('HeaddyCheckout', fn() => new ObjectType([
            'name' => 'HeaddyCheckout',
            'fields' => [
                'ready' => Type::boolean(),
                'missing' => Type::listOf(Type::string()),
                'requiresPayment' => Type::boolean(),
                'amountDue' => self::money(),
                'availableShippingMethods' => Type::listOf(self::shippingMethod()),
            ],
            'resolveField' => self::arrayResolver(),
        ]));
    }

    public static function addressInput(): \GraphQL\Type\Definition\InputObjectType
    {
        return GqlEntityRegistry::getOrCreate('HeaddyAddressInput', fn() => new \GraphQL\Type\Definition\InputObjectType([
            'name' => 'HeaddyAddressInput',
            'fields' => [
                'fullName' => Type::string(),
                'firstName' => Type::string(),
                'lastName' => Type::string(),
                'organization' => Type::string(),
                'addressLine1' => Type::string(),
                'addressLine2' => Type::string(),
                'addressLine3' => Type::string(),
                'locality' => Type::string(),
                'dependentLocality' => Type::string(),
                'administrativeArea' => Type::string(),
                'postalCode' => Type::string(),
                'sortingCode' => Type::string(),
                'countryCode' => Type::string(),
                'label' => Type::string(),
            ],
        ]));
    }

    public static function lineItemInput(): \GraphQL\Type\Definition\InputObjectType
    {
        return GqlEntityRegistry::getOrCreate('HeaddyLineItemInput', fn() => new \GraphQL\Type\Definition\InputObjectType([
            'name' => 'HeaddyLineItemInput',
            'fields' => [
                'purchasableId' => Type::nonNull(Type::int()),
                'qty' => Type::int(),
                'note' => Type::string(),
                // A JSON-encoded object. GraphQL has no native map type, and inventing one input
                // type per store's option keys is not possible from a plugin.
                'options' => Type::string(),
            ],
        ]));
    }

    /**
     * The serializer hands back plain arrays, and graphql-php's default resolver only reads
     * properties and `get*()` methods off objects — without this every field would resolve to null.
     */
    public static function arrayResolver(): callable
    {
        return static function($source, array $args, $context, \GraphQL\Type\Definition\ResolveInfo $info) {
            return is_array($source) ? ($source[$info->fieldName] ?? null) : null;
        };
    }
}
