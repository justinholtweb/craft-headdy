<?php
/**
 * Seeds the plugin-testing install with believable Headdy data so the control-panel screens can be
 * screenshotted for the marketing page and the Plugin Store promos.
 *
 *   docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-headdy/promos/seed-demo.php
 *   docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-headdy/promos/seed-demo.php --teardown
 *
 * The build switches Headdy to Pro, creates three API keys and a webhook endpoint, then drives the
 * real API over HTTP with those keys, so the request log holds real rows. Everything it creates —
 * and the edition it found — is written to a state file, and --teardown puts all of it back.
 */

$root = getcwd();
require $root . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\db\Query;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\models\Webhook;
use justinholtweb\headdy\Plugin;

const STATE = CRAFT_BASE_PATH . '/storage/runtime/headdy-promo-state.json';

$plugin = Plugin::getInstance();
$plugins = Craft::$app->getPlugins();

function syncConfigVersion(): void
{
    $stored = (new Query())->select(['configVersion'])->from(craft\db\Table::INFO)->scalar();
    if ($stored) {
        Craft::$app->getInfo()->configVersion = $stored;
    }
}

function switchEdition(string $edition): void
{
    syncConfigVersion();
    Craft::$app->getPlugins()->switchEdition(Plugin::HANDLE, $edition);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

// ---------------------------------------------------------------- teardown --
if (in_array('--teardown', $argv, true)) {
    if (!is_file(STATE)) {
        echo "No state file — nothing to tear down.\n";
        exit(0);
    }
    $state = json_decode(file_get_contents(STATE), true);
    $db = Craft::$app->getDb();

    $logIds = (new Query())->select('id')->from('{{%headdy_log}}')->where(['>', 'id', $state['maxLogId']])->column();
    $db->createCommand()->delete('{{%headdy_log}}', ['id' => $logIds])->execute();
    echo 'Log rows removed: ' . count($logIds) . "\n";

    foreach ($state['webhooks'] as $id) {
        // An enabled endpoint picks up every order status change the shared harness makes — other
        // plugins' suites included — and each failed delivery leaves a failed queue job behind.
        $db->createCommand()->delete('{{%headdy_webhookdeliveries}}', ['webhookId' => $id])->execute();
        $jobs = $db->createCommand()->delete('{{%queue}}', ['and',
            ['like', 'job', 'SendWebhook'],
            ['like', 'job', 's:9:"webhookId";i:' . $id . ';'],
        ])->execute();
        echo "Webhook queue jobs removed: $jobs\n";
        $plugin->getWebhooks()->deleteWebhookById($id);
    }
    foreach ($state['keys'] as $id) {
        $plugin->getKeys()->deleteKeyById($id);
    }

    // Every cart the API made during the build. Hard-deleted; the token rows cascade.
    $orderIds = (new Query())->select('id')->from('{{%commerce_orders}}')->where(['>', 'id', $state['maxOrderId']])->column();
    foreach ($orderIds as $id) {
        $order = Order::find()->id($id)->status(null)->trashed(null)->one();
        if ($order) {
            Craft::$app->getElements()->deleteElement($order, true);
        }
    }
    echo 'Carts removed: ' . count($orderIds) . "\n";

    $current = $plugins->getPluginInfo(Plugin::HANDLE)['edition'] ?? null;
    if ($current !== $state['edition']) {
        switchEdition($state['edition']);
        echo "Edition restored to {$state['edition']}.\n";
    }

    unlink(STATE);
    echo "Done.\n";
    exit(0);
}

function saveState(array $state): void
{
    file_put_contents(STATE, json_encode($state));
}

// --traffic re-runs only the HTTP traffic against the keys an earlier build made — for when
// something else in the shared harness has cleared the request log in the meantime.
$trafficOnly = in_array('--traffic', $argv, true);

// ------------------------------------------------------------------- build --
if ($trafficOnly) {
    $state = json_decode((string)@file_get_contents(STATE), true)
        ?: throw new RuntimeException('No build in place. Run without --traffic first.');
    $keys = [];
    foreach ($state['keys'] as $id) {
        $key = $plugin->getKeys()->getKeyById($id);
        $keys[$key->name] = $key;
    }
    goto traffic;
}

if (is_file(STATE)) {
    fwrite(STDERR, "A previous build is still in place. Run --teardown first.\n");
    exit(1);
}

$state = [
    'edition' => $plugins->getPluginInfo(Plugin::HANDLE)['edition'] ?? Plugin::EDITION_LITE,
    'maxLogId' => (int)(new Query())->from('{{%headdy_log}}')->max('id'),
    'maxOrderId' => (int)(new Query())->from('{{%commerce_orders}}')->max('id'),
    'keys' => [],
    'webhooks' => [],
];
// Written before anything is created, so a crash half way still leaves a teardown that works.
file_put_contents(STATE, json_encode($state));

if ($state['edition'] !== Plugin::EDITION_PRO) {
    switchEdition(Plugin::EDITION_PRO);
    echo "Switched to Pro (was {$state['edition']}).\n";
}

$keys = [];
foreach ([
    ['Next.js storefront', ApiKey::allScopes(), ['https://shop.rowangray.test'], null],
    ['Mobile app', ['catalog:read', 'cart:read', 'cart:write', 'checkout', 'payment'], [], 120],
    ['Inventory kiosk', ['catalog:read'], [], 30],
] as [$name, $scopes, $origins, $limit]) {
    $key = new ApiKey(['name' => $name, 'scopes' => $scopes, 'origins' => $origins, 'rateLimit' => $limit]);
    if (!$plugin->getKeys()->saveKey($key)) {
        throw new RuntimeException("key $name: " . json_encode($key->getErrors()));
    }
    $state['keys'][] = $key->id;
    saveState($state);
    $keys[$name] = $key;
    echo "Key: $name ({$key->publicKey})\n";
}

$hook = new Webhook([
    'name' => 'Fulfilment service',
    'url' => 'https://fulfilment.rowangray.test/hooks/headdy',
    'topics' => ['order.paid', 'order.statusChanged', 'payment.failed'],
    // Saved switched off: the harness is shared, and an enabled endpoint receives every order
    // status change any test suite makes. Switch it on only for the moment of its screenshot.
    'enabled' => false,
]);
if (!$plugin->getWebhooks()->saveWebhook($hook)) {
    throw new RuntimeException('webhook: ' . json_encode($hook->getErrors()));
}
$state['webhooks'][] = $hook->id;
saveState($state);
echo "Webhook: {$hook->name}\n";

traffic:

// ------------------------------------------------------- real API traffic --
function api(string $method, string $path, ?array $body, array $headers): array
{
    $ch = curl_init(Plugin::getInstance()->getApiUrl() . $path);
    $h = ['Accept: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $h,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$raw, true) ?: [];
    printf("  %-6s %-40s %d %s\n", $method, $path, $status, $json['error']['code'] ?? '');
    return [$status, $json];
}

$web = ['X-Headdy-Key' => $keys['Next.js storefront']->publicKey, 'Origin' => 'https://shop.rowangray.test'];
$mobile = ['X-Headdy-Key' => $keys['Mobile app']->publicKey];
$kiosk = ['X-Headdy-Key' => $keys['Inventory kiosk']->publicKey];

$small = 216; // TP Widget - Small, already in the harness
$large = 217; // TP Widget - Large

echo "Traffic…\n";
api('GET', '/', null, $web);
api('GET', '/products?pageSize=12', null, $web);
api('GET', '/variants/TP-WIDGET-S', null, $kiosk);

[, $res] = api('POST', '/carts', ['items' => [['purchasableId' => $small, 'qty' => 2]]], $web);
$cart = $res['cart']['token'] ?? null;
if ($cart) {
    $c = $web + ['X-Headdy-Cart' => $cart];
    api('POST', '/carts/current/items', ['purchasableId' => $large, 'qty' => 1], $c);
    api('GET', '/carts/current', null, $c);
    api('GET', '/checkout', null, $c);
    api('PUT', '/carts/current/coupon', ['couponCode' => 'SPRING10'], $c);
    api('PUT', '/carts/current/email', ['email' => 'ada@rowangray.test'], $c);
    api('GET', '/checkout', null, $c);
    api('PUT', '/carts/current/shipping-method', ['shippingMethodHandle' => 'overnight'], $c);
    api('POST', '/checkout/pay', ['gatewayId' => 1, 'returnUrl' => 'https://elsewhere.example/thanks'], $c);
}

[, $res] = api('POST', '/carts', ['items' => [['purchasableId' => $large, 'qty' => 1]]], $mobile);
$cart2 = $res['cart']['token'] ?? null;
if ($cart2) {
    $c = $mobile + ['X-Headdy-Cart' => $cart2];
    api('GET', '/carts/current/shipping-methods', null, $c);
    api('GET', '/checkout', null, $c);
}

api('POST', '/carts', ['items' => [['purchasableId' => $small, 'qty' => 1]]], $kiosk);    // 403: no cart scope
api('GET', '/carts/current', null, $web + ['X-Headdy-Cart' => 'hdc_expiredOrUnknown']);  // 404
api('GET', '/store', null, ['X-Headdy-Key' => 'hd_pk_notARealKey']);                     // 401
api('GET', '/products?search=widget', null, $mobile);

echo "Built. Take the screenshots, then run with --teardown.\n";
