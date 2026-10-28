<?php

namespace SpotExPay\Whmcs;

defined('WHMCS') || exit;

/** An answer from the SpotEx Pay API other than 2xx, or no answer at all (status 0, network_error). */
class ApiError extends \RuntimeException
{
    /** @var int */
    public $status;
    /** @var string */
    public $errorCode;

    public function __construct(int $status, string $errorCode, string $message)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }
}

/** The few calls the module makes to the SpotEx Pay merchant API, over cURL. */
final class Client
{
    const BASE_URL = 'https://spotex.trade/api/merchant/v1';
    const VERSION = '0.1.0';

    /** @var string */
    private $key;
    /** @var callable */
    private $transport;
    /** @var int */
    private $timeout;

    /**
     * @param callable|null $transport For tests: function (string $method, string $url, array $headers, ?string $body, int $timeout): array [int $status, string $body]
     * @param int $timeout Seconds: short where a client is waiting on a page.
     */
    public function __construct(string $key, ?callable $transport = null, int $timeout = 30)
    {
        $this->key = $key;
        $this->transport = $transport ?? [self::class, 'curlTransport'];
        $this->timeout = $timeout;
    }

    public function isTestMode(): bool
    {
        return strpos($this->key, 'sk_test_') === 0;
    }

    /** A checkout session: send the client to its 'url'. */
    public function createSession(array $params, string $idempotencyKey): array
    {
        return $this->request('POST', '/checkout-sessions', $params, $idempotencyKey);
    }

    public function retrieveSession(string $id): array
    {
        return $this->request('GET', '/checkout-sessions/' . rawurlencode($id));
    }

    public function retrieveCheckout(string $id): array
    {
        return $this->request('GET', '/checkouts/' . rawurlencode($id));
    }

    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->key,
            'Accept' => 'application/json',
            'User-Agent' => 'spotex-pay-whmcs/' . self::VERSION,
        ];
        $json = null;
        if ($body !== null) {
            $json = json_encode($body);
            if ($json === false) {
                throw new ApiError(0, 'invalid_request', 'The invoice could not be sent as JSON.');
            }
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        [$status, $raw] = call_user_func($this->transport, $method, self::BASE_URL . $path, $headers, $json, $this->timeout);
        $data = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            $error = is_array($data) && isset($data['error']) && is_array($data['error']) ? $data['error'] : [];
            throw new ApiError((int) $status, (string) ($error['code'] ?? 'http_error'), (string) ($error['message'] ?? ('HTTP ' . $status)));
        }
        if (!is_array($data)) {
            throw new ApiError((int) $status, 'invalid_response', 'SpotEx Pay answered with something that is not JSON.');
        }
        return $data;
    }

    /** @internal cURL, which WHMCS itself requires. */
    public static function curlTransport(string $method, string $url, array $headers, ?string $body, int $timeout = 30): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new ApiError(0, 'network_error', 'Could not reach SpotEx Pay: ' . curl_error($ch));
        }
        // No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5; the handle goes with $ch.
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $raw];
    }
}
