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
        $replyTo = $this->settings->get('mail_reply_to', '');
        $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8\r\n";

        // Deliberately separate from `from` — lets outgoing mail be sent
        // via a dedicated transactional domain/service while replies still
        // land in a real, checked mailbox. Empty by default: only added if
        // an instance has actually configured one.
        if ($replyTo !== '') {
            $headers .= "Reply-To: {$replyTo}\r\n";
        }

        $sent = @mail($to, $subject, $body, $headers);

        if (!$sent) {
            error_log("Mailer: mail() failed sending '{$subject}' to {$to} — is a local MTA (sendmail/postfix) configured? PHP's mail() requires one.");
        }

        return $sent;
    }
}
