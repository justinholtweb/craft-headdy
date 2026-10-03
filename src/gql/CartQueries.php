<?php

namespace justinholtweb\headdy\gql;

use craft\gql\base\Query;
use GraphQL\Type\Definition\Type;
use justinholtweb\headdy\gql\types\CartType;
use justinholtweb\headdy\Plugin;

/**
 * Cart and checkout reads, so a front end never has to mix a GraphQL query with a REST fetch just
 * to render a basket next to a product listing.
 */
class CartQueries extends Query
{
    /**
     * @inheritdoc
     */
    public static function getQueries(bool $checkToken = true): array
    {
        return [
            'headdyCart' => [
                'type' => CartType::cart(),
                'description' => 'The cart behind a cart token. Returns null if the token is unknown, expired, or its cart has been completed.',
                'args' => ['cartToken' => Type::nonNull(Type::string())],
                'resolve' => static function($root, array $args) {
                    CartMutations::guard('read');
                    $plugin = Plugin::getInstance();
                    $cart = $plugin->getTokens()->getCartByToken((string)$args['cartToken']);

                    return $cart !== null ? $plugin->getSerializer()->cart($cart, $args['cartToken']) : null;
                },
            ],

            'headdyCheckout' => [
                'type' => CartType::checkout(),
                'description' => 'What is still missing before this cart can be paid for.',
                'args' => ['cartToken' => Type::nonNull(Type::string())],
                'resolve' => static function($root, array $args) {
                    CartMutations::guard('read');
                    $plugin = Plugin::getInstance();
                    $cart = $plugin->getTokens()->getCartByToken((string)$args['cartToken']);

                    return $cart !== null ? $plugin->getCheckout()->state($cart) : null;
                },
            ],
        ];
    }
}
