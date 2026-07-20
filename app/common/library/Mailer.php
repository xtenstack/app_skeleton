<?php
declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\Injectable;

/**
 * Thin wrapper over PHP's native mail() — relies on a local MTA
 * (sendmail/postfix) being configured on the host, same as any typical PHP
 * deploy. No SMTP client, no queue: fire-and-log. If mail() fails (most
 * likely cause: no MTA configured, e.g. local dev), the failure is logged
 * via error_log() rather than surfaced to the end user, since a broken
 * mail() setup shouldn't block the request that triggered it (e.g. signup
 * still succeeds — the user just needs a resend or an admin to check logs).
 */
class Mailer extends Injectable
{
    public function send(string $to, string $subject, string $body): bool
    {
        $from    = $this->settings->get('mail_from', 'no-reply@localhost');
        $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8\r\n";

        $sent = @mail($to, $subject, $body, $headers);

        if (!$sent) {
            error_log("Mailer: mail() failed sending '{$subject}' to {$to} — is a local MTA (sendmail/postfix) configured? PHP's mail() requires one.");
        }

        return $sent;
    }
}
