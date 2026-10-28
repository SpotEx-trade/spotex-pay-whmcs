<?php
// Plain tests of the module, no WHMCS needed: php tests/run.php

use SpotExPay\Whmcs\ApiError;
use SpotExPay\Whmcs\Client;
use SpotExPay\Whmcs\Handler;
use SpotExPay\Whmcs\Host;
use SpotExPay\Whmcs\Invoices;
use SpotExPay\Whmcs\Signature;

error_reporting(E_ALL);
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

define('WHMCS', true);
require __DIR__ . '/../modules/gateways/spotexpay/lib/autoload.php';

$passed = 0;
$failed = 0;
function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  PASS  $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  FAIL  $name\n        " . get_class($e) . ': ' . $e->getMessage() . ' (line ' . $e->getLine() . ")\n";
        $failed++;
    }
}
function same($a, $b, string $what = ''): void
{
    if ($a !== $b) {
        throw new Exception(($what ? "$what: " : '') . var_export($a, true) . ' !== ' . var_export($b, true));
    }
}
function throws(callable $fn, string $class, string $match = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            throw new Exception("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($match !== '' && strpos($e->getMessage(), $match) === false) {
            throw new Exception("expected a message with '$match', got: " . $e->getMessage());
        }
        return;
    }
    throw new Exception("expected $class, nothing was thrown");
}
function sign(string $body, string $secret, ?int $t = null): string
{
    $t = $t ?? time();
    return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
}

/** WHMCS in memory: invoices, the session table, payments, the gateway log and locks. */
final class FakeHost implements Host
{
    public $invoices = [];
    public $currencies = [];
    public $sessions = [];
    public $payments = [];
    public $logs = [];
    public $busy = false;
    public $held = [];

    public function invoice(int $invoiceId): ?array
    {
        return $this->invoices[$invoiceId] ?? null;
    }

    public function currencyCode(int $userId): string
    {
        return $this->currencies[$userId] ?? 'USD';
    }

    public function systemUrl(): string
    {
        return 'https://billing.example.com/';
    }

    public function session(int $invoiceId): ?array
    {
        return $this->sessions[$invoiceId] ?? null;
    }

    public function saveSession(int $invoiceId, array $session): void
    {
        if (!isset($this->held[$invoiceId])) {
            throw new Exception('saved without the lock');
        }
        $this->sessions[$invoiceId] = $session;
    }

    public function lock(int $invoiceId): bool
    {
        if ($this->busy) {
            return false;
        }
        $this->held[$invoiceId] = true;
        return true;
    }

    public function unlock(int $invoiceId): void
    {
        unset($this->held[$invoiceId]);
    }

    public function recorded(int $invoiceId, string $transactionId): bool
    {
        if (in_array($transactionId, $this->sessions[$invoiceId]['booked'] ?? [], true)) {
            return true;
        }
        foreach ($this->payments as $payment) {
            if ($payment[1] === $transactionId) {
                return true;
            }
        }
        return false;
    }

    public function addPayment(int $invoiceId, string $transactionId, string $amount): void
    {
        if (!isset($this->held[$invoiceId])) {
            throw new Exception('paid without the lock');
        }
        $this->payments[] = [$invoiceId, $transactionId, $amount];
        $this->sessions[$invoiceId]['booked'][] = $transactionId;
        // As WHMCS does: paid in full, the invoice is Paid.
        $this->invoices[$invoiceId]['status'] = 'Paid';
        $this->invoices[$invoiceId]['balance'] = '0.00';
    }

    public function log(string $status, array $data): void
    {
        $this->logs[] = [$status, $data];
    }
}

/** The SpotEx API in memory: answers by method and path, and keeps what it was asked. */
final class FakeApi
{
    public $seen = [];
    public $routes = [];

    public function transport(): callable
    {
        return function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
            $this->seen[] = [$method, $url, $headers, $body, $timeout];
            $path = substr($url, strlen(Client::BASE_URL));
            $answer = $this->routes[$method . ' ' . $path] ?? [404, '{"error":{"code":"not_found","message":"Not found."}}'];
            if (is_callable($answer)) {
                $answer = $answer($headers, $body);
            }
            return [$answer[0], is_string($answer[1]) ? $answer[1] : json_encode($answer[1])];
        };
    }

    public function created(): array
    {
        return array_values(array_filter($this->seen, function ($s) {
            return $s[0] === 'POST';
        }));
    }
}

function setup(string $key = 'sk_live_abc'): array
{
    $host = new FakeHost();
    $host->invoices[7] = ['status' => 'Unpaid', 'balance' => '25.00', 'userid' => 3, 'invoicenum' => 'INV/2026 7'];
    $host->currencies[3] = 'EUR';
    $api = new FakeApi();
    $n = 0;
    $api->routes['POST /checkout-sessions'] = function (array $headers, ?string $body) use (&$n) {
        $n++;
        return [201, session("cs_$n", json_decode($body, true))];
    };
    return [$host, $api, new Handler($host, $key, 'whsec_test', $api->transport())];
}

/** A new session as SpotEx answers: open, for what it was asked. */
function session(string $id, array $asked): array
{
    return ['id' => $id, 'url' => "https://spotex.trade/m/$id", 'status' => 'open', 'order_id' => $asked['order_id'], 'price' => $asked['price'], 'currency' => $asked['currency']];
}

const SITE = 'https://billing.example.com/';

function paidEvent(string $session, array $over = [], bool $live = true): array
{
    return [
        'id' => 'evt_1',
        'type' => 'checkout.paid',
        'livemode' => $live,
        'data' => ['checkout' => array_merge([
            'id' => 'co_1', 'session_id' => $session, 'order_id' => '7', 'status' => 'paid',
            'price' => '25.00', 'currency' => 'EUR', 'coin' => 'USDT', 'amount' => '27.1', 'received' => '27.1',
        ], $over)],
    ];
}

echo "Rules\n";

check('a balance becomes a price with its currency\'s decimals, never rounded down', function () {
    same(Invoices::price('25', 'EUR'), '25.00');
    same(Invoices::price('25.5', 'USD'), '25.50');
    same(Invoices::price('0025.10', 'GBP'), '25.10');
    same(Invoices::price('2500.00', 'JPY'), '2500', 'WHMCS keeps two places even for yen');
    throws(function () {
        Invoices::price('2500.50', 'JPY');
    }, InvalidArgumentException::class, 'more decimals');
    throws(function () {
        Invoices::price('25.001', 'EUR');
    }, InvalidArgumentException::class, 'more decimals');
    throws(function () {
        Invoices::price('0.00', 'EUR');
    }, InvalidArgumentException::class, 'Nothing is left');
    throws(function () {
        Invoices::price('-5', 'EUR');
    }, InvalidArgumentException::class);
});

check('the session key is the invoice, its balance and the try, in SpotEx\'s key alphabet', function () {
    $key = Invoices::idempotencyKey(SITE, 7, '25.00', 'EUR', 1);
    same(preg_match('/\A[A-Za-z0-9_-]{8,100}\z/', $key . '-n'), 1);
    same(Invoices::idempotencyKey(SITE, 7, '25.00', 'EUR', 1), $key, 'the same asked again');
    same($key === Invoices::idempotencyKey(SITE, 7, '20.00', 'EUR', 1), false, 'a new balance');
    same($key === Invoices::idempotencyKey(SITE, 7, '25.00', 'EUR', 2), false, 'a new try');
    same($key === Invoices::idempotencyKey('https://other.example/', 7, '25.00', 'EUR', 1), false, 'another WHMCS on the same account');
    $s = ['status' => 'open', 'order_id' => '7', 'price' => '25.00', 'currency' => 'EUR'];
    same(Invoices::sessionIsOurs($s, 7, '25.00', 'EUR'), true);
    same(Invoices::sessionIsOurs(['order_id' => '8'] + $s, 7, '25.00', 'EUR'), false, 'another invoice\'s');
    same(Invoices::sessionIsOurs(['status' => 'paid'] + $s, 7, '25.00', 'EUR'), false, 'paid already');
    same(Invoices::sessionIsOurs(['price' => '9.99'] + $s, 7, '25.00', 'EUR'), false);
    same(Invoices::itemRef('INV/2026 7'), 'INV-2026-7');
    same(Invoices::itemRef('///'), 'invoice');
});

check('the Pay button\'s token is the invoice\'s own, and there is none without a key', function () {
    same(Invoices::payToken(7, 'sk_live_a') === Invoices::payToken(8, 'sk_live_a'), false);
    same(Invoices::payToken(7, 'sk_live_a') === Invoices::payToken(7, 'sk_live_b'), false);
    throws(function () {
        Invoices::payToken(7, '');
    }, InvalidArgumentException::class);
});

check('signatures: the right secret only, never an empty one, never stale', function () {
    $body = '{"type":"checkout.paid"}';
    same(Signature::constructEvent($body, sign($body, 's3'), 's3')['type'], 'checkout.paid');
    throws(function () use ($body) {
        Signature::constructEvent($body, sign($body, 'other'), 's3');
    }, UnexpectedValueException::class, 'does not match');
    throws(function () use ($body) {
        Signature::constructEvent($body, sign($body, ''), '');
    }, InvalidArgumentException::class);
    throws(function () use ($body) {
        Signature::constructEvent($body, sign($body, 's3', time() - 400), 's3');
    }, UnexpectedValueException::class, 'too old');
    throws(function () use ($body) {
        Signature::constructEvent($body, 'v1=abc', 's3');
    }, UnexpectedValueException::class);
    throws(function () {
        Signature::constructEvent('[1', sign('[1', 's3'), 's3');
    }, UnexpectedValueException::class, 'JSON');
});

check('a webhook counts only for the invoice\'s own session, mode, currency and amount', function () {
    $kept = ['session_id' => 'cs_2', 'price' => '25.00', 'currency' => 'EUR', 'test' => false, 'earlier' => [
        ['id' => 'cs_1', 'price' => '30.00', 'currency' => 'EUR', 'test' => false],
        ['id' => 'cs_0', 'price' => '25.00', 'currency' => 'EUR', 'test' => false],
        ['id' => 'cs_t', 'price' => '25.00', 'currency' => 'EUR', 'test' => true],
    ]];
    same(Invoices::eventAction(paidEvent('cs_2'), $kept)[0], 'pay');
    same(Invoices::eventAction(paidEvent('cs_2'), $kept)[2], '25.00');
    same(Invoices::eventAction(paidEvent('cs_9'), $kept)[0], 'ignore', 'another session with the same order id');
    same(Invoices::eventAction(paidEvent('cs_1', ['price' => '30.00']), $kept)[0], 'pay', 'an earlier session: settle() decides against the balance now');
    same(Invoices::eventAction(paidEvent('cs_1', ['price' => '30.00']), $kept)[2], '30.00', 'at the price it was sent for');
    same(Invoices::eventAction(paidEvent('cs_0'), $kept)[0], 'pay');
    same(Invoices::eventAction(paidEvent('cs_t', [], true), $kept)[0], 'ignore', 'live money on an earlier test session');
    same(Invoices::eventAction(paidEvent('cs_t', [], false), $kept)[0], 'test', 'and test money on it is a test');
    same(Invoices::eventAction(paidEvent('cs_2', ['accepted_by' => 'auto']), $kept)[0], 'ignore', 'released by SpotEx, whatever the event says');
    same(Invoices::eventAction(['type' => 'checkout.released'] + paidEvent('cs_2', ['status' => 'released']), $kept)[0], 'note');
    same(Invoices::eventAction(['type' => 'checkout.extra'] + paidEvent('cs_2'), $kept)[0], 'note');
    $held = Invoices::eventAction(['type' => 'checkout.partial'] + paidEvent('cs_2', ['duplicate' => true]), $kept);
    same(strpos($held[1], 'second payment') !== false, true, 'a held second payment is called that');
    same(Invoices::eventAction(paidEvent('cs_2', [], false), $kept)[0], 'ignore', 'a test event on a live invoice');
    same(Invoices::eventAction(paidEvent('cs_2', [], false), ['test' => true] + $kept)[0], 'test');
    same(Invoices::eventAction(paidEvent('cs_2', ['currency' => 'USD']), $kept)[0], 'note');
    same(Invoices::eventAction(paidEvent('cs_2', ['price' => '20.00']), $kept)[0], 'note');
    same(Invoices::eventAction(paidEvent('cs_2', ['price' => '25']), $kept)[0], 'pay', '25 is 25.00');
    same(Invoices::eventAction(paidEvent('cs_2', ['duplicate' => true]), $kept)[0], 'note');
    same(Invoices::eventAction(paidEvent('cs_2', ['status' => 'pending']), $kept)[0], 'ignore');
    same(Invoices::eventAction(paidEvent('cs_2', ['id' => '']), $kept)[0], 'ignore', 'no transaction id to book');
    same(Invoices::eventAction(['type' => 'checkout.partial'] + paidEvent('cs_2'), $kept)[0], 'note');
    same(Invoices::eventAction(['type' => 'payout.withdrawal.sent'] + paidEvent('cs_2'), $kept)[0], 'ignore');
});

check('a payment is added once, only while the invoice still asks for exactly it', function () {
    $pay = ['pay', 'Paid.', '25.00'];
    same(Invoices::settle($pay, ['recorded' => false, 'status' => 'Unpaid', 'balance' => '25.00'])[0], 'add');
    same(Invoices::settle($pay, ['recorded' => false, 'status' => 'Unpaid', 'balance' => '25'])[0], 'add');
    same(Invoices::settle($pay, ['recorded' => true, 'status' => 'Paid', 'balance' => '0.00'])[0], 'ignore', 'added already');
    same(Invoices::settle($pay, ['recorded' => false, 'status' => 'Paid', 'balance' => '0.00'])[0], 'note', 'paid some other way');
    same(strpos(Invoices::settle($pay, ['recorded' => false, 'status' => 'Paid', 'balance' => '0.00'])[1], 'second payment') !== false, true);
    same(Invoices::settle($pay, ['recorded' => false, 'status' => 'Cancelled', 'balance' => '25.00'])[0], 'note');
    same(Invoices::settle($pay, ['recorded' => false, 'status' => 'Unpaid', 'balance' => '15.00'])[0], 'note', 'the balance changed');
    same(Invoices::settle(['note', 'x'], ['recorded' => false, 'status' => 'Unpaid', 'balance' => '25.00']), ['note', 'x']);
});

echo "\nPaying an invoice\n";

check('Pay makes a session for the balance, in the account\'s currency, and keeps it', function () {
    [$host, $api, $handler] = setup();
    $url = $handler->startPayment(7);
    same($url, 'https://spotex.trade/m/cs_1');
    [$method, $sent, $headers, $body] = $api->created()[0];
    same($sent, Client::BASE_URL . '/checkout-sessions');
    same($headers['Authorization'], 'Bearer sk_live_abc');
    same($headers['Idempotency-Key'], Invoices::idempotencyKey(SITE, 7, '25.00', 'EUR', 1));
    same(json_decode($body, true), [
        'price' => '25.00', 'currency' => 'EUR', 'item' => 'Invoice INV-2026-7', 'order_id' => '7',
        'return_url' => 'https://billing.example.com/viewinvoice.php?id=7&spotexpay=return',
    ]);
    same($host->sessions[7]['session_id'], 'cs_1');
    same($host->sessions[7]['test'], false);
    same($host->held, [], 'the lock is let go');
});

check('pressed again: the same open page; once it expires, a new one, the old kept as earlier', function () {
    [$host, $api, $handler] = setup();
    $handler->startPayment(7);
    $api->routes['GET /checkout-sessions/cs_1'] = [200, ['id' => 'cs_1', 'status' => 'open']];
    same($handler->startPayment(7), 'https://spotex.trade/m/cs_1');
    same(count($api->created()), 1);
    $api->routes['GET /checkout-sessions/cs_1'] = [200, ['id' => 'cs_1', 'status' => 'expired']];
    same($handler->startPayment(7), 'https://spotex.trade/m/cs_2');
    same($api->created()[1][2]['Idempotency-Key'], Invoices::idempotencyKey(SITE, 7, '25.00', 'EUR', 2));
    same($host->sessions[7]['earlier'], [['id' => 'cs_1', 'price' => '25.00', 'currency' => 'EUR', 'test' => false]]);
});

check('a changed balance gets a new session, never the old one\'s page', function () {
    [$host, $api, $handler] = setup();
    $handler->startPayment(7);
    $api->routes['GET /checkout-sessions/cs_1'] = [200, ['id' => 'cs_1', 'status' => 'open']];
    $host->invoices[7]['balance'] = '15.00';
    same($handler->startPayment(7), 'https://spotex.trade/m/cs_2');
    same(json_decode($api->created()[1][3], true)['price'], '15.00');
});

check('refused: a paid invoice, an unsupported currency, a key SpotEx turns down', function () {
    [$host, $api, $handler] = setup();
    $host->invoices[7]['status'] = 'Paid';
    throws(function () use ($handler) {
        $handler->startPayment(7);
    }, InvalidArgumentException::class, 'not waiting');
    $host->invoices[7]['status'] = 'Unpaid';
    $host->currencies[3] = 'XAF';
    throws(function () use ($handler) {
        $handler->startPayment(7);
    }, InvalidArgumentException::class, 'XAF');
    $host->currencies[3] = 'EUR';
    $api->routes['POST /checkout-sessions'] = [401, ['error' => ['code' => 'unauthorized', 'message' => 'That key is not valid.']]];
    throws(function () use ($handler) {
        $handler->startPayment(7);
    }, ApiError::class, 'not valid');
    same($host->held, [], 'the lock is let go after an error too');
    same(isset($host->sessions[7]), false);
});

check('a WHMCS off the merchant\'s website: made again without the way back', function () {
    [$host, $api, $handler] = setup();
    $api->routes['POST /checkout-sessions'] = function (array $headers, ?string $body) {
        return isset(json_decode($body, true)['return_url'])
            ? [400, ['error' => ['code' => 'invalid_request', 'message' => 'return_url: must be on your website, shop.example.']]]
            : [201, session('cs_n', json_decode($body, true))];
    };
    same($handler->startPayment(7), 'https://spotex.trade/m/cs_n');
    same(substr($api->created()[1][2]['Idempotency-Key'], -2), '-n');
});

check('a payment page anywhere but SpotEx is never followed', function () {
    [$host, $api, $handler] = setup();
    $api->routes['POST /checkout-sessions'] = function (array $headers, ?string $body) {
        return [201, ['url' => 'https://spotex.trade.evil.example/m/cs_x'] + session('cs_x', json_decode($body, true))];
    };
    throws(function () use ($handler) {
        $handler->startPayment(7);
    }, ApiError::class, 'without a payment page');
});

echo "\nWebhooks and the way back\n";

check('the signed paid webhook adds the payment once, however often it comes', function () {
    [$host, $api, $handler] = setup();
    $handler->startPayment(7);
    $body = json_encode(paidEvent('cs_1'));
    same($handler->handleWebhook($body, sign($body, 'whsec_test')), true);
    same($host->payments, [[7, 'co_1', '25.00']]);
    same($host->invoices[7]['status'], 'Paid');
    same($handler->handleWebhook($body, sign($body, 'whsec_test')), true);
    same(count($host->payments), 1, 'sent again: not added again');
    same($host->held, []);
});

check('unsigned, or for another site\'s order or session: nothing is added', function () {
    [$host, $api, $handler] = setup();
    $handler->startPayment(7);
    $body = json_encode(paidEvent('cs_1'));
    throws(function () use ($handler, $body) {
        $handler->handleWebhook($body, sign($body, 'wrong'));
    }, UnexpectedValueException::class);
    $other = json_encode(paidEvent('cs_1', ['order_id' => 'wc-15']));
    same($handler->handleWebhook($other, sign($other, 'whsec_test')), true);
    $never = json_encode(paidEvent('cs_1', ['order_id' => '8']));
    same($handler->handleWebhook($never, sign($never, 'whsec_test')), true, 'an invoice never sent from here');
    $foreign = json_encode(paidEvent('cs_public_link'));
    same($handler->handleWebhook($foreign, sign($foreign, 'whsec_test')), true, 'a payment link with order_ref 7');
    same($host->payments, []);
});

check('a test key\'s payment is logged, never added', function () {
    [$host, $api, $handler] = setup('sk_test_abc');
    $handler->startPayment(7);
    same($host->sessions[7]['test'], true);
    $body = json_encode(paidEvent('cs_1', [], false));
    $handler->handleWebhook($body, sign($body, 'whsec_test'));
    same($host->payments, []);
    same($host->logs[count($host->logs) - 1][0], 'Test payment');
    $live = json_encode(paidEvent('cs_1'));
    $handler->handleWebhook($live, sign($live, 'whsec_test'));
    same($host->payments, [], 'nor a live event on a test invoice');
});

check('paid after the balance changed, or after it was paid another way: logged, not added', function () {
    [$host, $api, $handler] = setup();
    $handler->startPayment(7);
    $host->invoices[7]['balance'] = '10.00';
    $body = json_encode(paidEvent('cs_1'));
    $handler->handleWebhook($body, sign($body, 'whsec_test'));
    $host->invoices[7]['status'] = 'Paid';
    $handler->handleWebhook($body, sign($body, 'whsec_test'));
    same($host->payments, []);
    same(array_map(function ($l) {
        return $l[0];
    }, array_slice($host->logs, -2)), ['Note', 'Note']);
});

check('the invoice held by another step: answered so SpotEx sends it again', function () {
    [$host, $api, $handler] = setup();
    $handler->startPayment(7);
    $host->busy = true;
    $body = json_encode(paidEvent('cs_1'));
    same($handler->handleWebhook($body, sign($body, 'whsec_test')), false);
    same($host->payments, []);
    $host->busy = false;
    same($handler->handleWebhook($body, sign($body, 'whsec_test')), true);
    same(count($host->payments), 1);
});

check('back from paying before the webhook: SpotEx is asked, and the payment added once', function () {
    [$host, $api, $handler] = setup();
    $handler->startPayment(7);
    $api->routes['GET /checkout-sessions/cs_1'] = [200, ['id' => 'cs_1', 'status' => 'open']];
    same($handler->checkOnReturn(7), 'waiting');
    $api->routes['GET /checkout-sessions/cs_1'] = [200, ['id' => 'cs_1', 'status' => 'paid', 'checkout_id' => 'co_1']];
    $api->routes['GET /checkouts/co_1'] = [200, paidEvent('cs_1')['data']['checkout'] + ['livemode' => true]];
    same($handler->checkOnReturn(7), 'paid');
    same($host->payments, [[7, 'co_1', '25.00']]);
    $body = json_encode(paidEvent('cs_1'));
    $handler->handleWebhook($body, sign($body, 'whsec_test'));
    same(count($host->payments), 1, 'the webhook after it adds nothing');
    same($api->seen[count($api->seen) - 1][4], 10, 'a short wait while the client waits');
});

check('Pay pressed again after the last page was paid, before the webhook: the payment is added, not asked for again', function () {
    [$host, $api, $handler] = setup();
    $handler->startPayment(7);
    $api->routes['GET /checkout-sessions/cs_1'] = [200, ['id' => 'cs_1', 'status' => 'paid', 'checkout_id' => 'co_1']];
    $api->routes['GET /checkouts/co_1'] = [200, paidEvent('cs_1')['data']['checkout'] + ['livemode' => true]];
    same($handler->startPayment(7), 'https://billing.example.com/viewinvoice.php?id=7', 'back to the invoice, paid');
    same($host->payments, [[7, 'co_1', '25.00']]);
    same(count($api->created()), 1, 'no second page');
    same($host->held, []);
});

check('a page that ran out while its payment was confirming: paid later, it is still added; a second one is logged', function () {
    [$host, $api, $handler] = setup();
    $handler->startPayment(7);
    $api->routes['GET /checkout-sessions/cs_1'] = [200, ['id' => 'cs_1', 'status' => 'expired']];
    same($handler->startPayment(7), 'https://spotex.trade/m/cs_2');
    $late = json_encode(paidEvent('cs_1'));
    $handler->handleWebhook($late, sign($late, 'whsec_test'));
    same($host->payments, [[7, 'co_1', '25.00']], 'the confirmed payment on the earlier page');
    $again = json_encode(paidEvent('cs_2', ['id' => 'co_2']));
    $handler->handleWebhook($again, sign($again, 'whsec_test'));
    same(count($host->payments), 1, 'the client paid both: the second is not added');
    same(strpos($host->logs[count($host->logs) - 1][1]['message'], 'second payment') !== false, true);
});

check('a key that hands back another invoice\'s session (a copy of this WHMCS) is never used', function () {
    [$host, $api, $handler] = setup();
    $calls = 0;
    $api->routes['POST /checkout-sessions'] = function (array $headers, ?string $body) use (&$calls) {
        $calls++;
        $asked = json_decode($body, true);
        return [201, $calls === 1 ? session('cs_theirs', ['order_id' => '7', 'price' => '9.99'] + $asked) : session('cs_ours', $asked)];
    };
    same($handler->startPayment(7), 'https://spotex.trade/m/cs_ours');
    same($host->sessions[7]['attempt'], 2, 'the next try\'s key');
    $api->routes['POST /checkout-sessions'] = [201, ['id' => 'cs_paid', 'url' => 'https://spotex.trade/m/cs_paid', 'status' => 'paid', 'order_id' => '7', 'price' => '25.00', 'currency' => 'EUR']];
    $api->routes['GET /checkout-sessions/cs_ours'] = [200, ['id' => 'cs_ours', 'status' => 'expired']];
    throws(function () use ($handler) {
        $handler->startPayment(7);
    }, ApiError::class, 'without a payment page');
});

check('added once, never again: not after the transaction is deleted, nor for a release dressed as a payment', function () {
    [$host, $api, $handler] = setup();
    $handler->startPayment(7);
    $body = json_encode(paidEvent('cs_1'));
    $handler->handleWebhook($body, sign($body, 'whsec_test'));
    // Refunded in SpotEx; the merchant deletes the transaction and opens the invoice again.
    $host->payments = [];
    $host->invoices[7]['status'] = 'Unpaid';
    $host->invoices[7]['balance'] = '25.00';
    $handler->handleWebhook($body, sign($body, 'whsec_test'));
    same($host->payments, [], 'the module remembers what it added');
    [$host, $api, $handler] = setup();
    $handler->startPayment(7);
    $auto = json_encode(paidEvent('cs_1', ['accepted_by' => 'auto']));
    $handler->handleWebhook($auto, sign($auto, 'whsec_test'));
    $released = json_encode(['type' => 'checkout.released'] + paidEvent('cs_1', ['status' => 'released', 'accepted_by' => 'auto']));
    $handler->handleWebhook($released, sign($released, 'whsec_test'));
    same($host->payments, []);
    same($host->logs[count($host->logs) - 1][0], 'Note');
});

check('back from paying, but the balance changed meanwhile: not told it arrived', function () {
    [$host, $api, $handler] = setup();
    $handler->startPayment(7);
    $host->invoices[7]['balance'] = '10.00';
    $api->routes['GET /checkout-sessions/cs_1'] = [200, ['id' => 'cs_1', 'status' => 'paid', 'checkout_id' => 'co_1']];
    $api->routes['GET /checkouts/co_1'] = [200, paidEvent('cs_1')['data']['checkout'] + ['livemode' => true]];
    same($handler->checkOnReturn(7), 'waiting');
    same($host->payments, []);
});

check('the API client: the key, JSON, and errors as ApiError', function () {
    $api = new FakeApi();
    $api->routes['GET /checkout-sessions/cs%2F1'] = [200, ['id' => 'cs/1']];
    $api->routes['GET /checkouts/nope'] = [404, ['error' => ['code' => 'not_found', 'message' => 'No.']]];
    $api->routes['GET /checkouts/html'] = [200, '<html>'];
    $client = new Client('sk_test_x', $api->transport());
    same($client->isTestMode(), true);
    same($client->retrieveSession('cs/1')['id'], 'cs/1');
    same($api->seen[0][2]['User-Agent'], 'spotex-pay-whmcs/' . Client::VERSION);
    same($api->seen[0][4], 30, 'the default timeout');
    throws(function () use ($client) {
        $client->retrieveCheckout('nope');
    }, ApiError::class, 'No.');
    throws(function () use ($client) {
        $client->retrieveCheckout('html');
    }, ApiError::class, 'not JSON');
});

echo $failed === 0 ? "\n$passed passed, 0 failed\n" : "\n$passed passed, $failed FAILED\n";
exit($failed === 0 ? 0 : 1);
