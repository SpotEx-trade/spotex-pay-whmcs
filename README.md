# SpotEx Pay for WHMCS

[![tests](https://github.com/SpotEx-trade/spotex-pay-whmcs/actions/workflows/tests.yml/badge.svg)](https://github.com/SpotEx-trade/spotex-pay-whmcs/actions/workflows/tests.yml)

Let your clients pay WHMCS invoices in crypto with [SpotEx Pay](https://spotex.trade/merchants). They pay from any wallet or their SpotEx balance; the money lands in your SpotEx merchant wallet, less 0.5%.

- Invoices stay in your clients' currency: any of [these 37](https://spotex.trade/docs/merchant#currencies).
- An invoice is marked paid by SpotEx's signed webhook, never by the client coming back; if the webhook is late, the module asks SpotEx itself.
- A payment is added to an invoice once, and only for a checkout that invoice was sent to, at the balance it was sent for.
- A part payment SpotEx passes on without you accepting it (`checkout.released`) is logged, never added.
- A test key (`sk_test_…`) never adds a payment: test payments are only logged.

## Install

1. Copy the `modules` folder into your WHMCS folder, so that `modules/gateways/spotexpay.php` sits beside WHMCS's other gateways.
2. In WHMCS go to Configuration → System Settings → Payment Gateways, and activate SpotEx Pay.
3. In [SpotEx Merchant Center](https://spotex.trade/merchant) → Developers, make a secret key, and set the webhook address to `https://your-whmcs/modules/gateways/callback/spotexpay.php`. Copy the signing secret.
4. Back in WHMCS, paste the key and the secret into SpotEx Pay's settings, and save.

Clients are sent back to the invoice after paying when WHMCS is on your SpotEx merchant website or one of its subdomains (`billing.example.com` for `example.com`). Elsewhere they are not sent back, and the webhook still marks the invoice paid.

Leave "Convert To For Processing" empty: SpotEx takes the invoice in its own currency.

Use one WHMCS per SpotEx merchant account. SpotEx sends an account's webhooks to one address, so a second shop on the same account would hear of its payments only when clients come back. Never put the live key on a copy of this WHMCS (a staging site): both would take the same invoices' payments.

With a test key (`sk_test_…`) the invoice page says it is in test mode, and test payments are only logged, never added.

## How it works

The invoice's Pay button makes a [checkout session](https://spotex.trade/docs/merchant#sessions) for what is left to pay and sends the client to it. One invoice and balance is one session, so a client pressing Pay again lands on the same page. SpotEx's `checkout.paid` webhook — checked against its signature, the invoice's session, balance, currency and mode — adds the payment, with the SpotEx checkout id as its transaction id.

When a payment can't be added — the invoice was paid another way, cancelled, or its balance changed after the client was sent to SpotEx — it is written to the gateway log (Billing → Gateway Log) instead. The money is in your merchant wallet: add it to the invoice by hand, or refund it from the Merchant Center.

The module keeps one table of its own, `mod_spotexpay_sessions`, made the first time a client presses Pay.

## Development

```sh
php tests/run.php
```

The tests cover the whole module against a WHMCS and a SpotEx API in memory, and run on PHP 7.4 to 8.5 on every push.
