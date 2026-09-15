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
        'DRA'      => ['name' => 'Data Restore Audit', 'price_aud' => 450, 'product_id' => 7],
        'SS'       => ['name' => 'Standard Support (monthly)', 'price_aud' => 95, 'product_id' => 2, 'recurring' => 'm'],
        'PS'       => ['name' => 'Priority Support (monthly)', 'price_aud' => 240, 'product_id' => 3, 'recurring' => 'm'],
        'CH'       => ['name' => 'Care & Hosting (monthly)', 'price_aud' => 150],
        'DM_FLOOR' => ['name' => 'Deploy Module (minimum-scope, unmodified)', 'price_aud' => 850, 'product_id' => 6],
        // 2026-09-15 (Travis, room 2f3 #144/#145): the Directory/People/video
        // catalogue — same SKUs, prices and Dolibarr ids as ssa-agent's
        // backend/products.py (Tim) and directory-module's PACKAGE_PRICES.
        'DEP-RESTORE'                => ['name' => 'Data Restore Audit', 'price_aud' => 450, 'product_id' => 7],
        'DIR-LIST-FEATURED-M'        => ['name' => 'Featured Directory Listing (Monthly)', 'price_aud' => 29, 'product_id' => 12, 'recurring' => 'm', 'category_id' => 3],
        'DIR-LIST-FEATURED-Y'        => ['name' => 'Featured Directory Listing (Annual)', 'price_aud' => 290, 'product_id' => 13, 'recurring' => 'y', 'category_id' => 3],
        'DIR-LIST-PROMINENT-M'       => ['name' => 'Prominent Directory Listing (Monthly)', 'price_aud' => 59, 'product_id' => 14, 'recurring' => 'm', 'category_id' => 3],
        'DIR-LIST-PROMINENT-Y'       => ['name' => 'Prominent Directory Listing (Annual)', 'price_aud' => 590, 'product_id' => 15, 'recurring' => 'y', 'category_id' => 3],
        'DIR-VIDEO-30S-ADDON'        => ['name' => '30-Second Video Showcase (Add-on)', 'price_aud' => 19, 'product_id' => 16, 'recurring' => 'm', 'category_id' => 3],
        'DIR-VIDEO-30S-STANDALONE'   => ['name' => '30-Second Video Showcase (Standalone)', 'price_aud' => 39, 'product_id' => 17, 'recurring' => 'm', 'category_id' => 3],
        'DIR-BUNDLE-PRO-VID-M'       => ['name' => 'Prominent + Video Showcase Bundle (Monthly)', 'price_aud' => 75, 'product_id' => 18, 'recurring' => 'm', 'category_id' => 3],
        'DIR-BUNDLE-PRO-VID-Y'       => ['name' => 'Prominent + Video Showcase Bundle (Annual)', 'price_aud' => 750, 'product_id' => 19, 'recurring' => 'y', 'category_id' => 3],
        'SRV-DIR-VID30'              => ['name' => '30-Second AI Video Spotlight Production (Standalone)', 'price_aud' => 295, 'product_id' => 25, 'category_id' => 3],
        'SRV-DIR-VID30-BND'          => ['name' => '30-Second AI Video Spotlight Production (Tier Bundle Add-On)', 'price_aud' => 195, 'product_id' => 26, 'category_id' => 3],
        'SRV-DIR-VID-UPGRADE-TRAVIS' => ['name' => 'Founder Digital Twin Bespoke Avatar Add-On', 'price_aud' => 150, 'product_id' => 27, 'category_id' => 3],
        'PPL-VERIFIED-PRO-M'         => ['name' => 'Verified Practitioner Subscription (Monthly)', 'price_aud' => 19, 'product_id' => 21, 'recurring' => 'm', 'category_id' => 3],
        'PPL-VERIFIED-PRO-Y'         => ['name' => 'Verified Practitioner Subscription (Annual)', 'price_aud' => 190, 'product_id' => 22, 'recurring' => 'y', 'category_id' => 3],
        'PPL-FEATURED-M'             => ['name' => 'Featured Professional Placement (Monthly)', 'price_aud' => 49, 'product_id' => 23, 'recurring' => 'm', 'category_id' => 3],
        'PPL-FEATURED-Y'             => ['name' => 'Featured Professional Placement (Annual)', 'price_aud' => 490, 'product_id' => 24, 'recurring' => 'y', 'category_id' => 3],
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

        // One offer_code, or offer_codes[] for a multi-product purchase (e.g. a
        // monthly bundle plus the one-off production fee) — one order each.
        $codes = $body['offer_codes'] ?? [$body['offer_code'] ?? ''];
        $codes = array_values(array_unique(array_map(static fn ($c) => strtoupper(trim((string) $c)), (array) $codes)));

        foreach ($codes as $code) {
            if ($code === '' || !isset(self::OFFERS[$code])) {
                $this->response->setStatusCode(422, 'Unprocessable Entity');

                return $this->response->setJsonContent([
                    'error'            => 'Unknown or unsupported offer_code',
                    'supported_offers' => array_keys(self::OFFERS),
                ]);
            }
        }
        $offerCode = $codes[0];

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
        $result   = null;
        $orders   = [];

        foreach ($codes as $code) {
            $o   = self::OFFERS[$code];
            $res = $dolibarr->createClose(
                $customerName,
                $customerIdentifier,
                $o['name'],
                (float) $o['price_aud'],
                $o['product_id'] ?? null,
                $o['recurring'] ?? 'none',
                $o['category_id'] ?? null,
                $result['thirdparty_id'] ?? null,
                $result['project_id'] ?? null
            );

            if ($res === null) {
                if ($result === null) {
                    $this->response->setStatusCode(502, 'Bad Gateway');

                    return $this->response->setJsonContent(['error' => 'Dolibarr close creation failed — see app logs, needs manual follow-up']);
                }
                error_log("WatsonController::closeOffer: order for {$code} failed after {$result['order_ref']} — needs manual follow-up");
                continue;
            }
            $res['offer_code'] = $code;
            $res['offer_name'] = $o['name'];
            $res['price_aud']  = $o['price_aud'];
            $orders[]          = $res;
            $result            = $result ?? $res;
        }

        $this->response->setStatusCode(201, 'Created');

        return $this->response->setJsonContent([
            'offer_code'  => $offerCode,
            'offer_codes' => $codes,
            'offer_name'  => $offer['name'],
            'price_aud'   => array_sum(array_column($orders, 'price_aud')),
            'dolibarr'    => $result,
            'orders'      => $orders,
        ]);
    }
}
