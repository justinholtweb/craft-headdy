<?php

namespace justinholtweb\headdy\db;

/**
 * Headdy's database tables.
 *
 * Nothing here lives in project config. API keys, cart tokens and webhook endpoints are all
 * secrets or environment-specific URLs: syncing them through YAML would leak a production key
 * into a developer's checkout and overwrite it on the next apply.
 */
abstract class Table
{
    public const API_KEYS = '{{%headdy_apikeys}}';
    public const CART_TOKENS = '{{%headdy_carttokens}}';
    public const CUSTOMER_TOKENS = '{{%headdy_customertokens}}';
    public const LOG = '{{%headdy_log}}';
    public const WEBHOOKS = '{{%headdy_webhooks}}';
    public const WEBHOOK_DELIVERIES = '{{%headdy_webhookdeliveries}}';
}
