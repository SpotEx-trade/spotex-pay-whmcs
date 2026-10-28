<?php

namespace SpotExPay\Whmcs;

defined('WHMCS') || exit;

/** What the module needs from WHMCS. WhmcsHost is the real one; the tests bring their own. */
interface Host
{
    /** The invoice as WHMCS's GetInvoice gives it (status, balance, userid, invoicenum), or null. */
    public function invoice(int $invoiceId): ?array;

    /** The currency code an account is billed in. */
    public function currencyCode(int $userId): string;

    /** WHMCS's own address, as set in General Settings. */
    public function systemUrl(): string;

    /**
     * What was kept when the invoice was sent to SpotEx: session_id, session_url, price, currency, attempt, test,
     * earlier (each id, price, currency, test) and booked (the checkouts this module added to it).
     */
    public function session(int $invoiceId): ?array;

    public function saveSession(int $invoiceId, array $session): void;

    /** One payment step per invoice at a time; false if another did not finish within a few seconds. */
    public function lock(int $invoiceId): bool;

    public function unlock(int $invoiceId): void;

    /**
     * Was this checkout added before: on WHMCS's books, or by this module to
     * this invoice (so a transaction deleted by hand is not added again)?
     */
    public function recorded(int $invoiceId, string $transactionId): bool;

    /** Adds the payment, and keeps its checkout among the invoice's booked ones. */
    public function addPayment(int $invoiceId, string $transactionId, string $amount): void;

    /** A line in WHMCS's gateway log. Never given a secret. */
    public function log(string $status, array $data): void;
}
