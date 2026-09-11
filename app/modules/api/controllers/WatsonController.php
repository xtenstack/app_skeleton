<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

use App_skeleton\DolibarrClient;

/**
 * Watson's generic §7.3 close action (MAA-20260908-010) — authenticated
 * the same way every other endpoint in this module is (ControllerBase's
 * principal requirement), via the Watson-scoped API key provisioned to
 * watson@xten.au (CreateAgentTask), never Tim's own key.
 *
 * Deliberately generic across every published-price/term offer, not
 * hardcoded to the Data Restore Audit — Communications Policy §7.3
 * covers "a published price and a published term... accepted exactly as
 * published" without limiting that to one product (confirmed in
 * Watson-Ticket-Creation-and-Close-Spec-2026-09-08.md, independently
 * reached the same way by that session's role-play scenario work for
 * Care & Hosting and the Deploy Module $850 floor).
 */
class WatsonController extends ControllerBase
{
    /**
     * Every offer an AI agent may close unassisted under §7.3 — a fixed,
     * published price and term, nothing bespoke. Deploy Complete and
     * custom-scoped Deploy Modules are deliberately absent: those are
     * quoted per spec, which §7.3 and Lead-to-Close-Process.md §4 always
     * route to a human, by design, not an oversight here.
     */
    private const OFFERS = [
        'DRA'      => ['name' => 'Data Restore Audit', 'price_aud' => 450],
        'SS'       => ['name' => 'Standard Support (monthly)', 'price_aud' => 95],
        'PS'       => ['name' => 'Priority Support (monthly)', 'price_aud' => 240],
        'CH'       => ['name' => 'Care & Hosting (monthly)', 'price_aud' => 150],
        'DM_FLOOR' => ['name' => 'Deploy Module (minimum-scope, unmodified)', 'price_aud' => 850],
    ];

    /**
     * Confirms scope/price/date and creates the Dolibarr Third Party +
     * Project + validated Invoice — the "closing" half of the kickoff
     * doc's close/complete definition. REQ creation is a separate call
     * to TicketsController::createAction() (already built, already used
     * by the ticket-creation extension) — this endpoint's only job is
     * the Dolibarr side, kept as one focused action per MAA-20260908-010.
     */
    public function closeOfferAction()
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        $body = $this->getJsonBody();

        $offerCode = strtoupper(trim((string) ($body['offer_code'] ?? '')));

        if (!isset(self::OFFERS[$offerCode])) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent([
                'error'           => 'Unknown or unsupported offer_code',
                'supported_offers' => array_keys(self::OFFERS),
            ]);
        }

        $customerName = trim((string) ($body['customer_name'] ?? ''));
        $customerIdentifier = trim((string) ($body['customer_email'] ?? $body['customer_contact'] ?? ''));

        if ($customerName === '' || $customerIdentifier === '') {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'customer_name and customer_email are required']);
        }

        // §7.3 only covers an offer accepted exactly as published — a
        // caller passing a different price than the catalog's own is
        // either a bug upstream or an attempted bespoke term, neither of
        // which this endpoint is allowed to accept unassisted.
        $offer = self::OFFERS[$offerCode];

        if (isset($body['price_aud']) && (float) $body['price_aud'] !== (float) $offer['price_aud']) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent([
                'error' => "price_aud must match the published price ({$offer['price_aud']} AUD) — this endpoint cannot vary terms",
            ]);
        }

        $dolibarr = new DolibarrClient();
        $result   = $dolibarr->createClose($customerName, $customerIdentifier, $offer['name'], (float) $offer['price_aud']);

        if ($result === null) {
            $this->response->setStatusCode(502, 'Bad Gateway');

            return $this->response->setJsonContent(['error' => 'Dolibarr close creation failed — see app logs, needs manual follow-up']);
        }

        $this->response->setStatusCode(201, 'Created');

        return $this->response->setJsonContent([
            'offer_code'  => $offerCode,
            'offer_name'  => $offer['name'],
            'price_aud'   => $offer['price_aud'],
            'dolibarr'    => $result,
        ]);
    }
}
