<?php

namespace justinholtweb\headdy\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craft\commerce\db\Table as CommerceTable;
use justinholtweb\headdy\db\Table;

/**
 * Headdy's install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // A first install that creates the tables and then fails on something later — an
        // out-of-date project config is the usual culprit — leaves the tables behind, and the
        // retry would otherwise die on "table already exists" with no way forward but manual SQL.
        if ($this->_tablesExist()) {
            return true;
        }

        $this->_createTables();
        $this->_createIndexes();
        $this->_addForeignKeys();

        return true;
    }

    /**
     * Whether a previous run already built the schema.
     */
    private function _tablesExist(): bool
    {
        return $this->db->tableExists(Table::API_KEYS)
            && $this->db->tableExists(Table::CART_TOKENS)
            && $this->db->tableExists(Table::CUSTOMER_TOKENS)
            && $this->db->tableExists(Table::LOG)
            && $this->db->tableExists(Table::WEBHOOKS)
            && $this->db->tableExists(Table::WEBHOOK_DELIVERIES);
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Deliveries before webhooks, tokens before keys: a child table with a live FK cannot be
        // dropped after its parent.
        $this->dropTableIfExists(Table::WEBHOOK_DELIVERIES);
        $this->dropTableIfExists(Table::WEBHOOKS);
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::CUSTOMER_TOKENS);
        $this->dropTableIfExists(Table::CART_TOKENS);
        $this->dropTableIfExists(Table::API_KEYS);

        return true;
    }

    private function _createTables(): void
    {
        $this->createTable(Table::API_KEYS, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            // The public half is sent in the clear on every request; the secret half is only ever
            // stored as a hash, so a database dump does not hand over the ability to call the API.
            'publicKey' => $this->string(64)->notNull(),
            'secretHash' => $this->string(255),
            'scopes' => $this->text(),
            'origins' => $this->text(),
            'storeId' => $this->integer(),
            'rateLimit' => $this->integer(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'lastUsedAt' => $this->dateTime(),
            'expiryDate' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CART_TOKENS, [
            'id' => $this->primaryKey(),
            'tokenHash' => $this->string(64)->notNull(),
            'orderId' => $this->integer()->notNull(),
            'keyId' => $this->integer(),
            'storeId' => $this->integer(),
            'siteId' => $this->integer(),
            'expiryDate' => $this->dateTime()->notNull(),
            'lastUsedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CUSTOMER_TOKENS, [
            'id' => $this->primaryKey(),
            'tokenHash' => $this->string(64)->notNull(),
            'refreshHash' => $this->string(64),
            'userId' => $this->integer()->notNull(),
            'keyId' => $this->integer(),
            'expiryDate' => $this->dateTime()->notNull(),
            'refreshExpiryDate' => $this->dateTime(),
            'revoked' => $this->boolean()->notNull()->defaultValue(false),
            'lastUsedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'keyId' => $this->integer(),
            'method' => $this->string(10)->notNull(),
            'path' => $this->string(255)->notNull(),
            'statusCode' => $this->integer()->notNull(),
            'durationMs' => $this->integer(),
            'ip' => $this->string(45),
            'origin' => $this->string(255),
            'orderId' => $this->integer(),
            'errorCode' => $this->string(64),
            'request' => $this->mediumText(),
            'response' => $this->mediumText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::WEBHOOKS, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'url' => $this->string(500)->notNull(),
            'topics' => $this->text(),
            'secret' => $this->string(255),
            'storeId' => $this->integer(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::WEBHOOK_DELIVERIES, [
            'id' => $this->primaryKey(),
            'webhookId' => $this->integer()->notNull(),
            'topic' => $this->string(64)->notNull(),
            'payload' => $this->mediumText(),
            'statusCode' => $this->integer(),
            'attempt' => $this->integer()->notNull()->defaultValue(1),
            'success' => $this->boolean()->notNull()->defaultValue(false),
            'error' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function _createIndexes(): void
    {
        // The unique indexes on the hashes are the lookup path *and* the collision guarantee: a
        // token is only ever resolved by hash, never by scanning.
        $this->createIndex(null, Table::API_KEYS, ['publicKey'], true);
        $this->createIndex(null, Table::API_KEYS, ['enabled']);

        $this->createIndex(null, Table::CART_TOKENS, ['tokenHash'], true);
        $this->createIndex(null, Table::CART_TOKENS, ['orderId']);
        $this->createIndex(null, Table::CART_TOKENS, ['expiryDate']);

        $this->createIndex(null, Table::CUSTOMER_TOKENS, ['tokenHash'], true);
        $this->createIndex(null, Table::CUSTOMER_TOKENS, ['refreshHash']);
        $this->createIndex(null, Table::CUSTOMER_TOKENS, ['userId']);
        $this->createIndex(null, Table::CUSTOMER_TOKENS, ['expiryDate']);

        $this->createIndex(null, Table::LOG, ['dateCreated']);
        $this->createIndex(null, Table::LOG, ['keyId']);
        $this->createIndex(null, Table::LOG, ['statusCode']);

        $this->createIndex(null, Table::WEBHOOK_DELIVERIES, ['webhookId']);
        $this->createIndex(null, Table::WEBHOOK_DELIVERIES, ['dateCreated']);
    }

    private function _addForeignKeys(): void
    {
        // A deleted cart takes its tokens with it — a token pointing at a missing order is not a
        // recoverable state, it is a 404 waiting to happen on every request.
        $this->addForeignKey(null, Table::CART_TOKENS, ['orderId'], CommerceTable::ORDERS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::CART_TOKENS, ['keyId'], Table::API_KEYS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::CUSTOMER_TOKENS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::CUSTOMER_TOKENS, ['keyId'], Table::API_KEYS, ['id'], 'SET NULL', null);

        // The log deliberately survives its key: "which key made this call" is exactly the question
        // you ask after revoking one.
        $this->addForeignKey(null, Table::LOG, ['keyId'], Table::API_KEYS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::WEBHOOK_DELIVERIES, ['webhookId'], Table::WEBHOOKS, ['id'], 'CASCADE', null);
    }
}
