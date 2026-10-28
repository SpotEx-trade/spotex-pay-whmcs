<?php
/**
 * SpotEx Pay for WHMCS: clients pay invoices in crypto, from any wallet or
 * their SpotEx balance. https://spotex.trade/merchants
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/spotexpay/lib/autoload.php';

use SpotExPay\Whmcs\Handler;
use SpotExPay\Whmcs\Invoices;

function spotexpay_MetaData()
{
    return [
        'DisplayName' => 'SpotEx Pay',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function spotexpay_config()
{
    $system = class_exists('\WHMCS\Config\Setting') ? rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') : '';
    $webhook = ($system !== '' ? $system : 'https://your-whmcs') . '/modules/gateways/callback/spotexpay.php';
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'SpotEx Pay'],
        'apiKey' => [
            'FriendlyName' => 'Secret API key',
            'Type' => 'password',
            'Size' => '70',
            'Description' => 'From SpotEx Merchant Center → Developers: <code>sk_live_…</code> for real payments, <code>sk_test_…</code> to try it out (test payments are never added to an invoice).',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook signing secret',
            'Type' => 'password',
            'Size' => '70',
            'Description' => 'In Merchant Center → Developers, set the webhook to <code>' . htmlspecialchars($webhook, ENT_QUOTES) . '</code> and paste its signing secret here.',
        ],
    ];
}

/** The invoice page's Pay button. */
function spotexpay_link($params)
{
    $invoiceId = (int) ($params['invoiceid'] ?? 0);
    $handler = Handler::forWhmcs($params);
    try {
        $token = $handler->payToken($invoiceId);
    } catch (\InvalidArgumentException $e) {
        return '<p>SpotEx Pay is not set up yet.</p>';
    }
    $message = $handler->isTestMode() ? '<p class="small text-warning">SpotEx Pay is in test mode: payments here are not real.</p>' : '';
    if (($_GET['spotexpay'] ?? '') === 'return' && $invoiceId > 0) {
        if (!function_exists('addInvoicePayment') && defined('ROOTDIR')) {
            require_once ROOTDIR . '/includes/invoicefunctions.php';
        }
        $back = $handler->checkOnReturn($invoiceId) === 'paid'
            ? 'Your payment has arrived. Refresh this page to see the invoice paid.'
            : "If you've paid, this invoice is marked paid as soon as SpotEx confirms it. From a wallet, that can take a few minutes.";
        $message .= '<p class="small">' . htmlspecialchars($back, ENT_QUOTES) . '</p>';
    }
    // The client's own currency, which the invoice is in: $params['currency'] is the converted one when
    // "Convert To For Processing" is set, and SpotEx is sent the invoice's.
    $currency = $handler->invoiceCurrency($invoiceId);
    if (!Invoices::supportsCurrency($currency)) {
        return $message . '<p>' . htmlspecialchars("SpotEx Pay does not take payments in {$currency}.", ENT_QUOTES) . '</p>';
    }
    $action = rtrim((string) ($params['systemurl'] ?? ''), '/') . '/modules/gateways/callback/spotexpay_pay.php';
    $label = (string) ($params['langpaynow'] ?? 'Pay now');
    return $message
        . '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '">'
        . '<input type="hidden" name="invoice" value="' . $invoiceId . '">'
        . '<input type="hidden" name="spotexpay_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">'
        . '<button type="submit" class="btn btn-success">' . htmlspecialchars($label, ENT_QUOTES) . '</button>'
        . '</form>';
}
