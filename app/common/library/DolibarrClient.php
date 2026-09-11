<?php

declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\Injectable;

/**
 * Dolibarr REST API client for Watson's generic §7.3 close action
 * (MAA-20260908-010) — confirm scope/price/date → create Third Party +
 * Project + validated Invoice → return the payment link. Built PHP-side
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
     * Creates a fresh Third Party + Project + validated Invoice for a
     * closed, published-price/term offer, tags the invoice with the DRA
     * category (per-invoice, not scoped to only the DRA product — Travis
     * asked this be applied "where possible", 2026-09-09), and writes the
     * Dolibarr-generated payment link into the invoice's public note
     * (the manual workaround XA-05 §A.4 documents, automated here the
     * same way ssa-agent's fulfillment.py already does it for Tim).
     *
     * Returns null on any failure — best-effort, logged, never thrown;
     * the caller must not let a Dolibarr outage block the customer-facing
     * confirmation that already happened.
     *
     * @return array{thirdparty_id:int,project_id:int,invoice_id:int,invoice_ref:string,payment_url:?string}|null
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
        ]);

        if ($thirdpartyId === null) {
            return null;
        }

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

        $invoiceId = $this->request('POST', '/invoices', [
            'socid'       => $thirdpartyId,
            'fk_project'  => $projectId,
            'type'        => 0,
            'date'        => $now,
            'lines'       => [[
                'desc'         => "{$offerName} — {$customerName} <{$customerIdentifier}>",
                'qty'          => 1,
                'subprice'     => $priceAud,
                'product_type' => 1,
            ]],
        ]);

        if ($invoiceId === null) {
            return null;
        }

        // validate returns the full invoice object (not just an id, unlike
        // create) — called once via requestRaw() directly, not through
        // request()'s int-or-null wrapper, since re-validating an
        // already-validated invoice would fail on a second call.
        $invoice = $this->requestRaw('POST', "/invoices/{$invoiceId}/validate", []);

        if (!is_array($invoice)) {
            return null;
        }

        $invoiceRef = (string) ($invoice['ref'] ?? '');
        $paymentUrl = $invoice['online_payment_url'] ?? null;

        $updateBody = ['categories' => [self::DRA_CATEGORY_ID]];

        if ($paymentUrl !== null) {
            $updateBody['note_public'] = "Pay online: {$paymentUrl}";
        }

        $this->requestRaw('PUT', "/invoices/{$invoiceId}", $updateBody);

        return [
            'thirdparty_id' => $thirdpartyId,
            'project_id'    => $projectId,
            'invoice_id'    => $invoiceId,
            'invoice_ref'   => $invoiceRef,
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
