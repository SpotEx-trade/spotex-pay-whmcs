<?php

namespace SpotExPay\Whmcs;

defined('WHMCS') || exit;

/**
 * SpotEx signs every webhook: SpotEx-Signature: t=<unix>,v1=<hex HMAC-SHA256
 * of "<t>.<raw body>" with the webhook signing secret>. The same check as
 * the SpotEx Pay PHP SDK (github.com/SpotEx-trade/spotex-pay-php), kept here
 * so the module needs nothing installed beside it.
 */
final class Signature
{
    /**
     * The event, if the signature over the raw body checks out and is no
     * older than $tolerance seconds (a replay otherwise); an exception if not.
     */
    public static function constructEvent(string $rawBody, ?string $header, string $secret, int $tolerance = 300): array
    {
        // An empty key would accept a body anyone can sign.
        if ($secret === '') {
            throw new \InvalidArgumentException('No webhook signing secret is set in the SpotEx Pay settings.');
        }
        if ($header === null || $header === '') {
            throw new \UnexpectedValueException('No SpotEx-Signature header.');
        }
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            $at = strpos($piece, '=');
            if ($at !== false && $at > 0) {
                $parts[trim(substr($piece, 0, $at))] = trim(substr($piece, $at + 1));
            }
        }
        $t = $parts['t'] ?? '';
        if (preg_match('/\A\d{1,12}\z/', $t) !== 1 || ($parts['v1'] ?? '') === '') {
            throw new \UnexpectedValueException('The SpotEx-Signature header is not in the form t=...,v1=....');
        }
        if ($tolerance > 0 && abs(time() - (int) $t) > $tolerance) {
            throw new \UnexpectedValueException('The webhook is too old.');
        }
        if (!hash_equals(hash_hmac('sha256', $t . '.' . $rawBody, $secret), $parts['v1'])) {
            throw new \UnexpectedValueException('The signature does not match.');
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            throw new \UnexpectedValueException('The webhook body is not a JSON object.');
        }
        return $event;
    }
}
