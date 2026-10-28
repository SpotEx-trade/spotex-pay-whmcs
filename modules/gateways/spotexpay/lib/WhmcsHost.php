<?php

namespace SpotExPay\Whmcs;

use WHMCS\Database\Capsule;

defined('WHMCS') || exit;

/** Host over WHMCS's own functions and database. */
final class WhmcsHost implements Host
{
    const MODULE = 'spotexpay';
    const TABLE = 'mod_spotexpay_sessions';

    /** @var string */
    private $gatewayName;

    /** @param string $gatewayName What the gateway log files lines under: the gateway's display name. */
    public function __construct(string $gatewayName)
    {
        $this->gatewayName = $gatewayName;
    }

    public function invoice(int $invoiceId): ?array
    {
        $result = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        return is_array($result) && ($result['result'] ?? '') === 'success' ? $result : null;
    }

    public function currencyCode(int $userId): string
    {
        $currency = getCurrency($userId);
        // Codes are typed in by hand in WHMCS: "usd" is USD.
        return is_array($currency) ? strtoupper(trim((string) ($currency['code'] ?? ''))) : '';
    }

    public function systemUrl(): string
    {
        return (string) \WHMCS\Config\Setting::getValue('SystemURL');
    }

    public function session(int $invoiceId): ?array
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) {
            return null;
        }
        $row = Capsule::table(self::TABLE)->where('invoice_id', $invoiceId)->first();
        if (!$row) {
            return null;
        }
        $earlier = json_decode((string) $row->earlier, true);
        $booked = json_decode((string) $row->booked, true);
        return [
            'session_id' => (string) $row->session_id,
            'session_url' => (string) $row->session_url,
            'price' => (string) $row->price,
            'currency' => (string) $row->currency,
            'attempt' => (int) $row->attempt,
            'test' => (int) $row->test === 1,
            'earlier' => is_array($earlier) ? array_values(array_filter($earlier, 'is_array')) : [],
            'booked' => is_array($booked) ? array_map('strval', $booked) : [],
        ];
    }

    public function saveSession(int $invoiceId, array $session): void
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) {
            try {
                Capsule::schema()->create(self::TABLE, function ($table) {
                    $table->unsignedInteger('invoice_id')->primary();
                    $table->string('session_id', 100);
                    $table->string('session_url', 255);
                    $table->string('price', 32);
                    $table->string('currency', 3);
                    $table->unsignedInteger('attempt')->default(0);
                    $table->boolean('test')->default(false);
                    $table->text('earlier')->nullable();
                    $table->text('booked')->nullable();
                    $table->timestamp('updated_at')->nullable();
                });
            } catch (\Exception $e) {
                // Two first-ever Pay presses at once: the other made it.
                if (!Capsule::schema()->hasTable(self::TABLE)) {
                    throw $e;
                }
            }
        }
        $values = [
            'session_id' => $session['session_id'],
            'session_url' => $session['session_url'],
            'price' => $session['price'],
            'currency' => $session['currency'],
            'attempt' => $session['attempt'],
            'test' => $session['test'] ? 1 : 0,
            'earlier' => json_encode(array_values($session['earlier'])),
            'booked' => json_encode(array_values($session['booked'] ?? [])),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        // Called under the invoice's lock, so nothing comes between the look and the write.
        if (Capsule::table(self::TABLE)->where('invoice_id', $invoiceId)->exists()) {
            Capsule::table(self::TABLE)->where('invoice_id', $invoiceId)->update($values);
        } else {
            Capsule::table(self::TABLE)->insert(['invoice_id' => $invoiceId] + $values);
        }
    }

    /**
     * MySQL's named locks are server-wide: the name carries this database's,
     * so two WHMCS on one server never wait on each other. A few seconds at
     * most, inside SpotEx's five-second wait for a webhook answer.
     */
    public function lock(int $invoiceId): bool
    {
        $got = Capsule::connection()->selectOne("SELECT GET_LOCK(CONCAT('spx-', SHA1(CONCAT(DATABASE(), '|', ?))), 3) AS got", [$invoiceId]);
        return $got !== null && (int) $got->got === 1;
    }

    public function unlock(int $invoiceId): void
    {
        Capsule::connection()->selectOne("SELECT RELEASE_LOCK(CONCAT('spx-', SHA1(CONCAT(DATABASE(), '|', ?)))) AS released", [$invoiceId]);
    }

    public function recorded(int $invoiceId, string $transactionId): bool
    {
        if ($transactionId === '') {
            return false;
        }
        $session = $this->session($invoiceId);
        if ($session !== null && in_array($transactionId, $session['booked'], true)) {
            return true;
        }
        return Capsule::table('tblaccounts')->where('gateway', self::MODULE)->where('transid', $transactionId)->exists();
    }

    public function addPayment(int $invoiceId, string $transactionId, string $amount): void
    {
        addInvoicePayment($invoiceId, $transactionId, $amount, 0, self::MODULE);
        $this->remember($invoiceId, $transactionId);
    }

    public function remember(int $invoiceId, string $transactionId): void
    {
        $session = $this->session($invoiceId);
        if ($session !== null) {
            $booked = array_slice(array_values(array_unique(array_merge($session['booked'], [$transactionId]))), -20);
            Capsule::table(self::TABLE)->where('invoice_id', $invoiceId)->update(['booked' => json_encode($booked)]);
        }
    }

    public function log(string $status, array $data): void
    {
        logTransaction($this->gatewayName, $data, $status);
    }
}
