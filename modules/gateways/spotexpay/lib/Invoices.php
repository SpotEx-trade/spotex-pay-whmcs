<?php

namespace SpotExPay\Whmcs;

defined('WHMCS') || exit;

/**
 * The module's rules, apart from WHMCS so they can be tested alone: what
 * SpotEx is asked for an invoice, and what a webhook means for one.
 */
final class Invoices
{
    /** What SpotEx Pay takes prices in: spotex.trade/docs/merchant#currencies */
    const CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD', 'NZD',
        'CNY', 'HKD', 'TWD', 'SGD', 'KRW', 'INR', 'IDR', 'PHP', 'THB', 'MYR', 'VND', 'PKR', 'BDT',
        'AED', 'SAR', 'TRY', 'ILS', 'ZAR', 'NGN', 'BRL', 'MXN',
        'PLN', 'CZK', 'HUF', 'RON', 'SEK', 'NOK', 'DKK', 'UAH',
    ];
    /** Priced in whole units, as SpotEx takes them. */
    const WHOLE_UNITS = ['JPY', 'KRW', 'VND', 'IDR', 'PKR', 'HUF'];

    /** @param string $currency As SpotEx writes it: upper case (WHMCS takes codes as typed). */
    public static function supportsCurrency(string $currency): bool
    {
        return in_array($currency, self::CURRENCIES, true);
    }

    /** The invoice number as SpotEx shows it: letters, digits, - and _, up to 64. */
    public static function itemRef(string $number): string
    {
        $ref = substr(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $number), '-'), 0, 64);
        return $ref !== '' ? $ref : 'invoice';
    }

    /**
     * The invoice's balance as a SpotEx price: with its currency's decimals,
     * and never rounded down — a balance with more decimals than that is refused.
     */
    public static function price(string $amount, string $currency): string
    {
        if (preg_match('/\A(\d+)(?:\.(\d+))?\z/', trim($amount), $m) !== 1) {
            throw new \InvalidArgumentException('The invoice balance is not a number.');
        }
        $whole = ltrim($m[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $m[2] ?? '';
        $places = in_array($currency, self::WHOLE_UNITS, true) ? 0 : 2;
        if (trim(substr($fraction, $places), '0') !== '') {
            throw new \InvalidArgumentException("The invoice balance has more decimals than {$currency} takes.");
        }
        $price = $places ? $whole . '.' . str_pad(substr($fraction, 0, $places), $places, '0') : $whole;
        if (preg_match('/[1-9]/', $price) !== 1) {
            throw new \InvalidArgumentException('Nothing is left to pay on this invoice.');
        }
        return $price;
    }

    /**
     * One checkout session per WHMCS, invoice, balance and try: asked again it
     * is the same one. The WHMCS's own part keeps two on one SpotEx account
     * (or a copy of this one) from being handed each other's sessions.
     */
    public static function idempotencyKey(string $site, int $invoiceId, string $price, string $currency, int $attempt): string
    {
        return 'whmcs-' . substr(hash('sha256', $site), 0, 8) . '-' . $invoiceId . '-' . substr(hash('sha256', $price . '|' . $currency), 0, 16) . '-' . $attempt;
    }

    /** A session SpotEx answered with is this invoice's own, for this balance, and open to be paid. */
    public static function sessionIsOurs(array $session, int $invoiceId, string $price, string $currency): bool
    {
        return ($session['status'] ?? '') === 'open'
            && ($session['order_id'] ?? null) === (string) $invoiceId
            && ($session['currency'] ?? null) === $currency
            && self::sameAmount((string) ($session['price'] ?? ''), $price);
    }

    /**
     * What the invoice page's Pay button carries, so that only a page this
     * WHMCS drew can start a payment for an invoice.
     */
    public static function payToken(int $invoiceId, string $apiKey): string
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('No SpotEx Pay API key is set.');
        }
        return hash_hmac('sha256', 'spotexpay-pay|' . $invoiceId, $apiKey);
    }

    /** Two decimal strings for the same amount: 25, 25.0 and 25.00. */
    public static function sameAmount(string $a, string $b): bool
    {
        $norm = function (string $v): string {
            if (preg_match('/\A(\d+)(?:\.(\d*))?\z/', trim($v), $m) !== 1) {
                return 'x' . $v;
            }
            $whole = ltrim($m[1], '0');
            $fraction = rtrim($m[2] ?? '', '0');
            return ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
        };
        return $norm($a) === $norm($b);
    }

    /**
     * What a webhook means for the invoice it names, given what was kept when
     * the invoice was sent to SpotEx: ['pay', note, price, currency] to add
     * the payment (see settle()), ['decline', note] for a payment that is
     * never to be added (logged, and its checkout remembered so it never is
     * later), ['test', note] for a test payment (never added: anyone with a
     * test page's link can press its buttons), ['note', note] to log, or
     * ['ignore', ''].
     *
     * @param array $kept session_id, price, currency and test of the session it was sent to last, and earlier: the
     *                    sessions it was sent to before, each id, price, currency and test
     */
    public static function eventAction(array $event, array $kept): array
    {
        $checkout = $event['data']['checkout'] ?? null;
        $session = is_array($checkout) ? ($checkout['session_id'] ?? null) : null;
        if (!is_string($session) || $session === '' || $kept['session_id'] === '' || $kept['price'] === '') {
            return ['ignore', ''];
        }
        // The session the money came through, as this invoice sent it: the last one, or one before it.
        $sent = $session === $kept['session_id'] ? ['price' => $kept['price'], 'currency' => $kept['currency'], 'test' => (bool) $kept['test']] : null;
        foreach ($sent === null ? ($kept['earlier'] ?? []) : [] as $before) {
            if (is_array($before) && ($before['id'] ?? '') === $session) {
                $sent = ['price' => (string) ($before['price'] ?? ''), 'currency' => (string) ($before['currency'] ?? ''), 'test' => !empty($before['test'])];
            }
        }
        if ($sent === null) {
            return ['ignore', ''];
        }
        // A live invoice is paid only by live money; a test one only ever by tests.
        $live = ($event['livemode'] ?? null) === true;
        if ($live === $sent['test']) {
            return ['ignore', ''];
        }
        $what = trim((string) ($checkout['received'] ?? $checkout['amount'] ?? '') . ' ' . (string) ($checkout['coin'] ?? ''));
        $type = (string) ($event['type'] ?? '');
        if ($type === 'checkout.paid') {
            if (!empty($checkout['duplicate'])) {
                return ['decline', "A second payment ({$what}) arrived for this invoice. It is in your SpotEx merchant wallet: refund it from the Merchant Center if it isn't wanted."];
            }
            $id = (string) ($checkout['id'] ?? '');
            // Released by SpotEx rather than accepted: comes as checkout.released, but never trust the type alone.
            if (($checkout['status'] ?? '') !== 'paid' || $id === '' || in_array($checkout['accepted_by'] ?? null, ['operator', 'auto'], true)) {
                return ['ignore', ''];
            }
            $price = (string) ($checkout['price'] ?? '');
            if (($checkout['currency'] ?? '') !== $sent['currency'] || preg_match('/\A\d+(\.\d+)?\z/', $price) !== 1 || !self::sameAmount($price, $sent['price'])) {
                return ['decline', 'SpotEx reported a payment of ' . ($price ?: '?') . ' ' . ($checkout['currency'] ?? '') . ", not the {$sent['price']} {$sent['currency']} it was sent for, so it was not added."];
            }
            if (!$live) {
                return ['test', "Test payment ({$what}, checkout {$id}): no real money, so it was not added."];
            }
            // Added only while the invoice still asks for exactly this much: settle() checks, under the lock.
            return ['pay', "Paid with SpotEx Pay: {$what} (checkout {$id})." . (!empty($checkout['accepted_short']) ? ' Accepted short, within your setting or by you.' : ''), $sent['price'], $sent['currency']];
        }
        if ($type === 'checkout.partial') {
            if (!empty($checkout['duplicate'])) {
                return ['note', "A second payment ({$what}) arrived for this invoice, which was paid already. SpotEx holds it: accept it in the Merchant Center, then refund it or keep it."];
            }
            return ['note', "Part of the payment arrived ({$what}). SpotEx holds it until the rest does, or until you accept it in the Merchant Center."];
        }
        if ($type === 'checkout.released' || ($checkout['status'] ?? '') === 'released') {
            return ['note', self::releasedNote($checkout, $what)];
        }
        if ($type === 'checkout.extra') {
            return ['note', "More arrived after this invoice was paid ({$what}). It is in your SpotEx merchant wallet: refund the extra from the Merchant Center if it isn't wanted."];
        }
        if ($type === 'checkout.refunded') {
            return ['note', 'Refunded in part or in full from the SpotEx Merchant Center. Record it on the invoice by hand.'];
        }
        return ['ignore', ''];
    }

    /** What a payment SpotEx passed on without the merchant accepting it means for the invoice. */
    private static function releasedNote(array $checkout, string $what): string
    {
        if (!empty($checkout['duplicate'])) {
            return "A second payment for this invoice ({$what}), held by SpotEx, was passed to your merchant wallet. The invoice was paid already: refund it from the Merchant Center if it isn't wanted.";
        }
        if (!empty($checkout['paid_late'])) {
            return "A late payment ({$what}), worth less than the invoice when it arrived, was passed to your merchant wallet without you accepting it. The invoice is NOT paid: add it by hand if you accept it, or refund it from the Merchant Center.";
        }
        $of = isset($checkout['amount'], $checkout['coin']) ? " of {$checkout['amount']} {$checkout['coin']}" : '';
        return "A part payment ({$what}{$of}) waited too long, and SpotEx passed it to your merchant wallet. The invoice is NOT paid: add what arrived by hand if you accept it, or refund it from the Merchant Center. If the rest comes to the same address, the invoice is paid then.";
    }

    /**
     * A 'pay' decision against the invoice as it is now, read under the
     * invoice's lock: ['add', note] to add the payment, ['decline', note]
     * when the invoice no longer asks for it, or ['ignore', ''] when this
     * checkout was handled already (the webhook and the return check can both
     * arrive). Other decisions pass through.
     *
     * @param array $now recorded (this checkout was handled before: on the books, or added or declined by this
     *                   module), status (WHMCS's), balance (as WHMCS gives it), currency (the client's, now)
     */
    public static function settle(array $decision, array $now): array
    {
        if ($decision[0] !== 'pay') {
            return [$decision[0], $decision[1]];
        }
        if ($now['recorded']) {
            return ['ignore', ''];
        }
        $price = $decision[2];
        if ($now['status'] === 'Paid') {
            return ['decline', $decision[1] . ' The invoice was paid already, so this is a second payment: refund it from the SpotEx Merchant Center if it isn\'t wanted.'];
        }
        if ($now['status'] !== 'Unpaid') {
            return ['decline', $decision[1] . " The invoice is {$now['status']}, so it was not added: add it by hand, or refund it from the SpotEx Merchant Center."];
        }
        if (($now['currency'] ?? '') !== $decision[3]) {
            return ['decline', $decision[1] . " The client is billed in {$now['currency']} now, not the {$decision[3]} that was paid, so it was not added: add it by hand, or refund it from the SpotEx Merchant Center."];
        }
        if (!self::sameAmount((string) $now['balance'], $price)) {
            return ['decline', $decision[1] . " The invoice's balance is {$now['balance']} now, not the {$price} that was paid, so it was not added: add it by hand, or refund it from the SpotEx Merchant Center."];
        }
        return ['add', $decision[1]];
    }
}
