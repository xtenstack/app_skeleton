<?php

declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\Injectable;

/**
 * Dolibarr REST API client for Watson's generic §7.3 close action
 * (MAA-20260908-010) — confirm scope/price/date → create Third Party +
 * Project + validated sales Order → return the payment link (order-first
 * since 2026-09-15: Dolibarr creates the invoice itself when the order is
 * paid; ssa-agent's order_sync finishes it). Built PHP-side
 * (not shared with Tim's Python close logic) per the spec's own
 * cost-attribution decision: Watson's costs (a lightweight Dolibarr API
 * call) and Tim's costs (voice/LLM-heavy call handling) stay on separate
 * infrastructure with nothing to untangle later.
 *
 * Config: `dolibarr.api_token`/`dolibarr.base_url` in config.local.php
 * (same gitignored-override mechanism as Mailer's resend_api_key — see
 * docker/entrypoint.sh). Token is the watson.ssa Dolibarr login's own
 * scoped credential, Thirdparties/Invoices/Projects/Categories rights
 * only — never Tim's own token or the shared admin one.
 *
 * accts.xten.au's WAF blocks PHP's default curl User-Agent the same way
 * it blocked Python's `python-requests/x.x` (confirmed live, 2026-09-09,
 * ssa-agent's fulfillment.py) — every request here sets an explicit
 * curl-like UA for the same reason.
 */
class DolibarrClient extends Injectable
{
    private const DRA_CATEGORY_ID = 2; // "DRA" invoice-scoped category, Travis 2026-09-09

    // Watson's own Dolibarr user id (login watson.ssa) — single rep, both
    // Third Party and Invoice. Watson handles a §7.3 close start to finish
    // itself; per Travis, an SSA that owns the whole flow needs no
    // secondary/different agent wired in for any part of it (2026-09-13).
    // Found unwired on every one of Watson's real production closes so far
    // (thirdparties 13-15) before this fix.
    private const WATSON_USER_ID = 10;

    private string $baseUrl;
    private string $apiToken;

    public function __construct()
    {
        $this->baseUrl  = rtrim((string) ($this->config->dolibarr->base_url ?? 'https://accts.xten.au/dolibarr'), '/');
        $this->apiToken = (string) ($this->config->dolibarr->api_token ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->apiToken !== '';
    }

    /**
     * Creates a fresh Third Party + Project + validated sales Order for a
     * closed, published-price/term offer, tags the invoice with the DRA
     * category (per-invoice, not scoped to only the DRA product — Travis
     * asked this be applied "where possible", 2026-09-09), wires Watson as
     * primary_representative on both the Third Party and the Invoice plus
     * the invoice's SALESREPFOLL contact (added 2026-09-13 — found live in
     * Dolibarr that Watson's real closes had none of this; per Travis, an
     * SSA handling a close start to finish needs no secondary agent, so
     * it's Watson's own user id throughout, same shape as Tim's), and
     * writes the Dolibarr-generated payment link into the invoice's public
     * note (the manual workaround XA-05 §A.4 documents, automated here the
     * same way ssa-agent's fulfillment.py already does it for Tim).
     *
     * Returns null on any failure — best-effort, logged, never thrown;
     * the caller must not let a Dolibarr outage block the customer-facing
     * confirmation that already happened.
     *
     * @return array{thirdparty_id:int,project_id:int,order_id:int,order_ref:string,invoice_id:null,invoice_ref:null,payment_url:?string}|null
     */
    public function createClose(string $customerName, string $customerIdentifier, string $offerName, float $priceAud): ?array
    {
        if (!$this->isConfigured()) {
            error_log('DolibarrClient: DOLIBARR_WATSON_TOKEN not configured — cannot create close records');

            return null;
        }

        $now = time();

        $thirdpartyId = $this->request('POST', '/thirdparties', [
            'name'         => "[WATSON] {$customerName} / {$customerIdentifier}",
            'client'       => 1,
            'country_id'   => 28, // Australia
            'code_client'  => 'CU-WATSON-' . $now,
            'note_private' => "Created by Watson web-chat close action, " . date('Y-m-d', $now) . " — real customer, not a role-play test.",
            'array_options' => [
                'options_primary_representative' => (string) self::WATSON_USER_ID,
            ],
        ]);

        if ($thirdpartyId === null) {
            return null;
        }

        // Best-effort native representative link, same as ssa-agent's
        // fulfillment.py — the POST succeeds against this Dolibarr
        // instance but its own GET .../representatives verification
        // endpoint 404s regardless (checked live, 2026-09-13), so this
        // is never allowed to block the close on its own.
        $this->requestRaw('POST', "/thirdparties/{$thirdpartyId}/representative/" . self::WATSON_USER_ID, []);

        $projectId = $this->request('POST', '/projects', [
            'socid'      => $thirdpartyId,
            'ref'        => 'PJ-WATSON-' . $now,
            'title'      => "{$offerName} — {$customerName}",
            'date_start' => $now,
            'status'     => 0,
        ]);

        if ($projectId === null) {
            return null;
        }

        // Order-first (Travis, 2026-09-15): the document raised before
        // payment is a sales order, not an invoice, so an abandoned checkout
        // never reaches the ledger. Dolibarr's payment page converts a paid
        // order into a paid invoice; ssa-agent's order_sync then finishes
        // that invoice (category, rep contact, recurring template) using the
        // [XTEN-ORDER ...] marker below — the same marker format
        // directory-module's DolibarrClient and ssa-agent/fulfillment.py
        // write, keep the three in step.
        $orderId = $this->request('POST', '/orders', [
            'socid'        => $thirdpartyId,
            'fk_project'   => $projectId,
            'date'         => $now,
            'note_private' => sprintf(
                '[XTEN-ORDER source=watson offer="%s" recurring=none frequency=1 category=%d rep=%d]',
                str_replace('"', "'", $offerName),
                self::DRA_CATEGORY_ID,
                self::WATSON_USER_ID
            ),
            'lines'        => [[
                'desc'         => "{$offerName} — {$customerName} <{$customerIdentifier}>",
                'qty'          => 1,
                'subprice'     => $priceAud,
                'product_type' => 1,
            ]],
            'array_options' => [
                'options_primary_representative' => (string) self::WATSON_USER_ID,
            ],
        ]);

        if ($orderId === null) {
            return null;
        }

        // Internal sales-rep-follow-up contact on the order (same mechanism
        // as before, now on the order; order_sync repeats it on the invoice).
        $this->requestRaw('POST', "/orders/{$orderId}/contact/" . self::WATSON_USER_ID . '/SALESREPFOLL', ['source' => 'internal']);

        // validate returns the full order object including
        // online_payment_url (source=order) — same asymmetry as invoices.
        $order = $this->requestRaw('POST', "/orders/{$orderId}/validate", []);

        if (!is_array($order)) {
            return null;
        }

        $orderRef   = (string) ($order['ref'] ?? '');
        $paymentUrl = $order['online_payment_url'] ?? null;

        return [
            'thirdparty_id' => $thirdpartyId,
            'project_id'    => $projectId,
            'order_id'      => $orderId,
            'order_ref'     => $orderRef,
            'invoice_id'    => null,
            'invoice_ref'   => null,
            'payment_url'   => $paymentUrl,
        ];
    }

    /** Convenience wrapper for the common "just give me the new id" case. */
    private function request(string $method, string $path, array $body): ?int
    {
        $result = $this->requestRaw($method, $path, $body);

        if (is_int($result)) {
            return $result;
        }

        if (is_array($result) && isset($result['id'])) {
            return (int) $result['id'];
        }

        return null;
    }

    /** @return mixed|null Decoded JSON response, or null on failure. */
    private function requestRaw(string $method, string $path, array $body)
    {
        $ch = curl_init($this->baseUrl . '/api/index.php' . $path);

        $headers = [
            'DOLAPIKEY: ' . $this->apiToken,
            'Content-Type: application/json',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'curl/8.5.0', // WAF workaround — see class docblock
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        } elseif ($method === 'PUT') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $opts[CURLOPT_POSTFIELDS]    = json_encode($body);
        }

        curl_setopt_array($ch, $opts);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("DolibarrClient: {$method} {$path} failed — HTTP {$httpCode}" . ($curlError !== '' ? " (curl: {$curlError})" : '') . ($response ? " body: " . substr((string) $response, 0, 300) : ''));

            return null;
        }

        return json_decode((string) $response, true);
    }
}
