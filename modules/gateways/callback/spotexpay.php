<?php
/**
 * SpotEx Pay's webhook: https://your-whmcs/modules/gateways/callback/spotexpay.php
 * Signed events only; a payment is added to an invoice once, and only for the
 * checkout that invoice was sent to.
 */

// As WHMCS's own sample gateway callback loads it.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../spotexpay/lib/autoload.php';

use SpotExPay\Whmcs\Handler;

$gatewayParams = getGatewayVariables('spotexpay');
if (empty($gatewayParams['type'])) {
    http_response_code(503);
    exit('SpotEx Pay is not activated.');
}

try {
    $handled = Handler::forWhmcs($gatewayParams)->handleWebhook((string) file_get_contents('php://input'), $_SERVER['HTTP_SPOTEX_SIGNATURE'] ?? null);
} catch (\InvalidArgumentException | \UnexpectedValueException $e) {
    logTransaction((string) ($gatewayParams['name'] ?? 'SpotEx Pay'), ['error' => $e->getMessage()], 'Webhook refused');
    http_response_code(400);
    exit('bad signature');
}

// 503 when another step held the invoice too long: SpotEx sends it again.
http_response_code($handled ? 200 : 503);
echo $handled ? 'ok' : 'busy';
