<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

/**
 * Lets an authenticated caller record an email-level unsubscribe directly
 * — the counterpart to XtenMarketing\Controllers\UnsubscribeController's
 * recipient-facing, per-send-token flow (marketing/unsubscribe/*), for a
 * caller that already knows an address opted out through some other
 * channel and has no send-specific token to redeem. Built for a script
 * watching deploy@xten.au for the "reply 'no thanks' and I won't" opt-out
 * line in Health-Check-Outreach-Emails.md's E-1/E-2 templates, which by
 * design carry no unsubscribe link at all (see that doc's rule 3).
 *
 * Writes to abn_lookup.unsubscribes directly via $this->db rather than
 * XtenMarketing\Unsubscribe — that model is autoloaded only inside the
 * marketing plugin module's own Loader instance (see its Module::
 * registerAutoloaders()), which app/config/loader.php's global
 * setDirectories() call does not include, so the class isn't visible
 * here regardless of which module a given request dispatches into.
 */
class UnsubscribesController extends ControllerBase
{
    public function createAction()
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        $body  = $this->getJsonBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'a valid email is required']);
        }

        $this->db->execute(
            'INSERT INTO abn_lookup.unsubscribes (email, campaign_code, reason) VALUES (:email, :campaign_code, :reason) ON CONFLICT (email) DO NOTHING',
            [
                'email'         => $email,
                'campaign_code' => isset($body['campaign_code']) ? (string) $body['campaign_code'] : null,
                'reason'        => isset($body['reason']) ? (string) $body['reason'] : 'api',
            ]
        );

        $this->response->setStatusCode(201, 'Created');

        return $this->response->setJsonContent(['email' => $email, 'unsubscribed' => true]);
    }
}
