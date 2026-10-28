<?php

namespace SpotExPay\Whmcs;

defined('WHMCS') || exit;

/**
 * The module's three moments: the client presses Pay on an invoice, SpotEx
 * sends a signed webhook, and the client comes back from paying.
 */
final class Handler
{
    /** @var Host */
    private $host;
    /** @var string */
    private $apiKey;
    /** @var string */
    private $webhookSecret;
    /** @var callable|null */
    private $transport;

    /** @param callable|null $transport For tests: see Client. */
    public function __construct(Host $host, string $apiKey, string $webhookSecret, ?callable $transport = null)
    {
        $this->host = $host;
        $this->apiKey = $apiKey;
        $this->webhookSecret = $webhookSecret;
        $this->transport = $transport;
    }

    /** From the gateway's settings, as getGatewayVariables() or a module function's $params give them. */
    public static function forWhmcs(array $params): self
    {
        return new self(
            new WhmcsHost((string) ($params['name'] ?? 'SpotEx Pay')),
            trim((string) ($params['apiKey'] ?? '')),
            trim((string) ($params['webhookSecret'] ?? ''))
        );
    }

    private function client(int $timeout = 30): Client
    {
        return new Client($this->apiKey, $this->transport, $timeout);
    }

    public function isTestMode(): bool
    {
        return $this->client()->isTestMode();
    }

    public function payToken(int $invoiceId): string
    {
        return Invoices::payToken($invoiceId, $this->apiKey);
    }

    /** The currency the invoice is billed in (its client's), as SpotEx writes it; '' if there is no such invoice. */
    public function invoiceCurrency(int $invoiceId): string
    {
        $invoice = $this->host->invoice($invoiceId);
        return $invoice === null ? '' : $this->host->currencyCode((int) ($invoice['userid'] ?? 0));
    }

    private function invoiceUrl(int $invoiceId): string
    {
        return rtrim($this->host->systemUrl(), '/') . '/viewinvoice.php?id=' . $invoiceId;
    }

    /** A session, or [] when SpotEx has none by that id for this key. */
    private function lookUp(string $sessionId): array
    {
        try {
            return $this->client(10)->retrieveSession($sessionId);
        } catch (ApiError $e) {
            if ($e->status === 404) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Where to send the client who pressed Pay: the SpotEx page for what is
     * left on the invoice — the same one while it is open — or back to the
     * invoice when its last page was paid and that payment is now added.
     * Throws InvalidArgumentException (the invoice can't be paid this way)
     * or ApiError (SpotEx refused or could not be reached).
     */
    public function startPayment(int $invoiceId): string
    {
        if (!$this->host->lock($invoiceId)) {
            throw new ApiError(0, 'busy', 'This invoice is being paid in another window. Try again in a moment.');
        }
        try {
            // Read under the lock: a webhook adding a payment can't come between the look and the new session.
            $invoice = $this->host->invoice($invoiceId);
            if ($invoice === null) {
                throw new \InvalidArgumentException('This invoice was not found.');
            }
            if (($invoice['status'] ?? '') !== 'Unpaid') {
                throw new \InvalidArgumentException('This invoice is not waiting for payment.');
            }
            $currency = $this->host->currencyCode((int) ($invoice['userid'] ?? 0));
            if (!Invoices::supportsCurrency($currency)) {
                throw new \InvalidArgumentException("SpotEx Pay does not take payments in {$currency}.");
            }
            $price = Invoices::price((string) ($invoice['balance'] ?? ''), $currency);
            $test = $this->client()->isTestMode();
            $kept = $this->host->session($invoiceId);
            // Made with the other kind of key (test or live), it can't be asked about: a new one follows.
            if ($kept !== null && $kept['test'] === $test) {
                $last = $this->lookUp($kept['session_id']);
                if (($last['status'] ?? '') === 'open' && $kept['price'] === $price && $kept['currency'] === $currency && $kept['session_url'] !== '') {
                    return $kept['session_url'];
                }
                // Paid already, and the webhook not here yet: add it, rather than ask for the money again.
                if (($last['status'] ?? '') === 'paid' && !empty($last['checkout_id'])) {
                    $checkout = $this->client(10)->retrieveCheckout((string) $last['checkout_id']);
                    $event = ['type' => 'checkout.paid', 'livemode' => ($checkout['livemode'] ?? false) === true, 'data' => ['checkout' => $checkout]];
                    // Back to the invoice only if that added it: one handled before (refunded since, say) asks anew.
                    if ($this->applyLocked($invoiceId, Invoices::eventAction($event, $kept), (string) ($checkout['id'] ?? '')) === 'added') {
                        return $this->invoiceUrl($invoiceId);
                    }
                }
            }
            // Otherwise a new session: a new try's key, so an expired one is never handed back.
            $attempt = (int) ($kept['attempt'] ?? 0);
            $number = (string) ($invoice['invoicenum'] ?? '');
            $session = null;
            for ($tries = 0; $tries < 3 && $session === null; $tries++) {
                $attempt++;
                $answer = $this->createSession([
                    'price' => $price,
                    'currency' => $currency,
                    'item' => 'Invoice ' . Invoices::itemRef($number !== '' ? $number : (string) $invoiceId),
                    // The invoice's id, which the webhook finds it by.
                    'order_id' => (string) $invoiceId,
                    'return_url' => $this->invoiceUrl($invoiceId) . '&spotexpay=return',
                ], Invoices::idempotencyKey($this->host->systemUrl(), $invoiceId, $price, $currency, $attempt));
                // A key used before (a restored backup, a copy of this WHMCS) hands back that session: never take another invoice's.
                $session = Invoices::sessionIsOurs($answer, $invoiceId, $price, $currency) ? $answer : null;
            }
            $url = (string) ($session['url'] ?? '');
            $host = (string) parse_url(Client::BASE_URL, PHP_URL_HOST);
            if ($session === null || empty($session['id']) || strpos($url, 'https://' . $host . '/') !== 0) {
                // Keep the tries counted, so the next press asks with new keys, not the same refused ones.
                if ($kept !== null) {
                    $this->host->saveSession($invoiceId, ['attempt' => $attempt] + $kept);
                }
                throw new ApiError(0, 'invalid_response', 'SpotEx Pay answered without a payment page for this invoice.');
            }
            // The sessions it was sent to before, each as it was sent: a payment on one of them still counts, or is logged.
            $earlier = $kept['earlier'] ?? [];
            if ($kept !== null) {
                $earlier[] = ['id' => $kept['session_id'], 'price' => $kept['price'], 'currency' => $kept['currency'], 'test' => $kept['test']];
            }
            $this->host->saveSession($invoiceId, [
                'session_id' => (string) $session['id'],
                'session_url' => $url,
                'price' => $price,
                'currency' => $currency,
                'attempt' => $attempt,
                'test' => $test,
                'earlier' => array_slice($earlier, -10),
                'booked' => $kept['booked'] ?? [],
            ]);
            $this->host->log('Sent to SpotEx', ['invoice' => $invoiceId, 'session' => (string) $session['id'], 'amount' => "{$price} {$currency}", 'test' => $test ? 'yes' : 'no']);
            return $url;
        } finally {
            $this->host->unlock($invoiceId);
        }
    }

    /**
     * SpotEx sends clients back only to the merchant's own website: a WHMCS
     * elsewhere goes without, and its invoices are marked paid by the webhook.
     */
    private function createSession(array $params, string $key): array
    {
        try {
            return $this->client()->createSession($params, $key);
        } catch (ApiError $e) {
            if ($e->status !== 400 || strpos($e->getMessage(), 'return_url') !== 0) {
                throw $e;
            }
            unset($params['return_url']);
            return $this->client()->createSession($params, $key . '-n');
        }
    }

    /**
     * A webhook from SpotEx. True once handled (answer 2xx, so SpotEx stops
     * retrying), false to have it come again. Throws InvalidArgumentException
     * or UnexpectedValueException when its signature does not check out.
     */
    public function handleWebhook(string $rawBody, ?string $signature): bool
    {
        $event = Signature::constructEvent($rawBody, $signature, $this->webhookSecret);
        $checkout = $event['data']['checkout'] ?? null;
        $orderId = is_array($checkout) ? (string) ($checkout['order_id'] ?? '') : '';
        if (preg_match('/\A[1-9]\d{0,9}\z/', $orderId) !== 1) {
            // Not an invoice of this WHMCS's: payouts, tests, another site. Nothing to do here.
            return true;
        }
        $invoiceId = (int) $orderId;
        $kept = $this->host->session($invoiceId);
        if ($kept === null) {
            return true;
        }
        // eventAction matches the event's session to one this invoice was sent to, so an id alone decides nothing.
        return $this->apply($invoiceId, Invoices::eventAction($event, $kept), (string) ($checkout['id'] ?? '')) !== 'busy';
    }

    /**
     * The client is back on the invoice from SpotEx: if the webhook has not
     * come yet, ask SpotEx (from the server, with the key) and treat the
     * answer as the webhook would be. 'paid' when the payment is on the
     * invoice now; 'waiting' otherwise.
     */
    public function checkOnReturn(int $invoiceId): string
    {
        $kept = $this->host->session($invoiceId);
        if ($kept === null || $this->apiKey === '' || $kept['test'] !== $this->isTestMode()) {
            return 'waiting';
        }
        try {
            // A client is waiting on this page: a short wait at most.
            $session = $this->lookUp($kept['session_id']);
            if (($session['status'] ?? '') !== 'paid' || empty($session['checkout_id'])) {
                return 'waiting';
            }
            $checkout = $this->client(10)->retrieveCheckout((string) $session['checkout_id']);
        } catch (ApiError $e) {
            return 'waiting';
        }
        $event = ['type' => 'checkout.paid', 'livemode' => ($checkout['livemode'] ?? false) === true, 'data' => ['checkout' => $checkout]];
        $done = $this->apply($invoiceId, Invoices::eventAction($event, $kept), (string) ($checkout['id'] ?? ''));
        if ($done === 'added') {
            return 'paid';
        }
        // Handled before: paid only if the invoice says so (it may have been reopened since).
        $invoice = $done === 'recorded' ? $this->host->invoice($invoiceId) : null;
        return $invoice !== null && ($invoice['status'] ?? '') !== 'Unpaid' ? 'paid' : 'waiting';
    }

    /** Does what was decided, taking the invoice's lock to add or decline a payment. 'busy' when it could not be taken. */
    private function apply(int $invoiceId, array $decision, string $checkoutId): string
    {
        if ($decision[0] !== 'pay' && $decision[0] !== 'decline') {
            return $this->applyLocked($invoiceId, $decision, $checkoutId);
        }
        if (!$this->host->lock($invoiceId)) {
            return 'busy';
        }
        try {
            return $this->applyLocked($invoiceId, $decision, $checkoutId);
        } finally {
            $this->host->unlock($invoiceId);
        }
    }

    /**
     * Does what was decided; a payment added or declined only while the
     * caller holds the invoice's lock. 'added', 'declined' (logged, and never
     * to be added later), 'recorded' (handled before), 'logged' (a test
     * payment or a note) or 'ignored'.
     */
    private function applyLocked(int $invoiceId, array $decision, string $checkoutId): string
    {
        if ($decision[0] === 'ignore') {
            return 'ignored';
        }
        $line = ['invoice' => $invoiceId, 'checkout' => $checkoutId, 'message' => $decision[1]];
        if ($decision[0] === 'test' || $decision[0] === 'note') {
            $this->host->log($decision[0] === 'test' ? 'Test payment' : 'Note', $line);
            return 'logged';
        }
        if ($this->host->recorded($invoiceId, $checkoutId)) {
            return 'recorded';
        }
        $amount = (string) ($decision[2] ?? '');
        if ($decision[0] === 'pay') {
            $invoice = $this->host->invoice($invoiceId);
            $decision = Invoices::settle($decision, [
                'recorded' => false,
                'status' => (string) ($invoice['status'] ?? 'missing'),
                'balance' => (string) ($invoice['balance'] ?? ''),
                'currency' => $invoice === null ? '' : $this->host->currencyCode((int) ($invoice['userid'] ?? 0)),
            ]);
        }
        if ($decision[0] === 'add') {
            $this->host->addPayment($invoiceId, $checkoutId, $amount);
            $this->host->log('Success', $line);
            return 'added';
        }
        // Declined: what the merchant does by hand is never doubled by this module adding it later.
        if ($checkoutId !== '') {
            $this->host->remember($invoiceId, $checkoutId);
        }
        $this->host->log('Note', ['message' => $decision[1]] + $line);
        return 'declined';
    }
}
