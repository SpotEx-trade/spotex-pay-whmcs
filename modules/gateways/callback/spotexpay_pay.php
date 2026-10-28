<?php
/**
 * Where an invoice's Pay button goes: makes (or finds) the invoice's SpotEx
 * checkout session for what is left to pay, and sends the client to it.
 */

// As WHMCS's own sample gateway callback loads it.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../spotexpay/lib/autoload.php';

use SpotExPay\Whmcs\ApiError;
use SpotExPay\Whmcs\Handler;

$invoiceId = (int) ($_POST['invoice'] ?? 0);
$back = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . ($invoiceId > 0 ? '/viewinvoice.php?id=' . $invoiceId : '/clientarea.php');

function spotexpay_stop(int $status, string $message, string $back)
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>SpotEx Pay</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem;line-height:1.5">'
        . '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
        . '<p><a href="' . htmlspecialchars($back, ENT_QUOTES) . '">Back to the invoice</a></p></body></html>';
    exit;
}

$gatewayParams = getGatewayVariables('spotexpay');
if (empty($gatewayParams['type'])) {
    spotexpay_stop(503, 'SpotEx Pay is not switched on.', $back);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    spotexpay_stop(405, 'Go back to the invoice and press Pay.', $back);
}
$handler = Handler::forWhmcs($gatewayParams);
try {
    $expected = $handler->payToken($invoiceId);
} catch (\InvalidArgumentException $e) {
    spotexpay_stop(503, 'SpotEx Pay is not set up yet.', $back);
}
if ($invoiceId <= 0 || !hash_equals($expected, (string) ($_POST['spotexpay_token'] ?? ''))) {
    spotexpay_stop(403, 'This payment link is out of date. Go back to the invoice and press Pay again.', $back);
}

try {
    $url = $handler->startPayment($invoiceId);
} catch (ApiError $e) {
    // What SpotEx said is for the merchant (the gateway log), not the client: it can name the server's IP address.
    logTransaction((string) ($gatewayParams['name'] ?? 'SpotEx Pay'), ['invoice' => $invoiceId, 'error' => $e->errorCode . ': ' . $e->getMessage()], 'Could not start');
    spotexpay_stop(502, $e->errorCode === 'busy' ? $e->getMessage() : 'The crypto payment could not be started. Try again in a few minutes, or choose another way to pay.', $back);
} catch (\InvalidArgumentException $e) {
    spotexpay_stop(400, $e->getMessage(), $back);
}

header('Location: ' . $url, true, 303);
exit;
