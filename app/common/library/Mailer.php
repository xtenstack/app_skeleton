<?php

declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\Injectable;

/**
 * Sends via Resend's HTTP API, not PHP's native mail(). Every
 * DigitalOcean droplet this app runs on (confirmed independently on both
 * stack-dev, in autoclaudedev-runner.sh, and stack-prod, 2026-08-27)
 * blocks all outbound SMTP ports — 25, 465, and 587 alike — so msmtp/
 * sendmail can authenticate fine and still never complete a TCP
 * connection. Resend's API rides on port 443 (HTTPS), which isn't
 * blocked. No SMTP relay, Resend's own or mail.xten.au's, is a viable
 * transport from this infrastructure regardless of credentials.
 *
 * Config: `mail.resend_api_key` in config.local.php (mirrors how DB
 * credentials are supplied — see app/config/config.php). Falls back to
 * logging and returning true if unset, same "don't block the request
 * over a mail failure" posture the old mail()-based version had.
 */
class Mailer extends Injectable
{
    private const API_URL = 'https://api.resend.com/emails';

    /**
     * $unsubscribeUrl, when given, adds a one-click List-Unsubscribe
     * header (RFC 8058) — the small "Unsubscribe" control Gmail/Outlook
     * render next to the sender name, separate from and in addition to
     * any unsubscribe text in the message body. Safe to point at the
     * same POST-based endpoint a visible link would use (see
     * XtenMarketing\Controllers\UnsubscribeController::submitAction()):
     * RFC 8058 one-click is itself POST-only, so it isn't exposed to the
     * GET-prefetch problem that endpoint's confirm/submit split exists
     * to guard against — a scanner prefetching links in the body never
     * triggers this header at all, only a real click on the mail
     * client's own button does.
     */
    public function send(string $to, string $subject, string $body, ?string $unsubscribeUrl = null): bool
    {
        // Ticket #19: real signup/password-reset flows exercised by
        // PHPUnit's RbacTest (and Playwright's fixtures) use this
        // project's own convention of an @*.invalid address -- the IANA-
        // reserved TLD (RFC 2606) guaranteed to never be a real,
        // deliverable domain. Attempting real delivery to one is never
        // correct for *any* caller, not just tests -- skip sending
        // entirely rather than calling a real API with it.
        if (preg_match('/\.invalid$/i', substr($to, strrpos($to, '@') + 1))) {
            error_log("Mailer: skipped '{$subject}' to {$to} -- .invalid is a reserved non-deliverable TLD (RFC 2606)");

            return true;
        }

        $apiKey = $this->config->mail->resend_api_key ?? '';

        if ($apiKey === '') {
            error_log("Mailer: RESEND_API_KEY not configured -- '{$subject}' to {$to} not sent. Set mail.resend_api_key in config.local.php.");

            return true;
        }

        $from    = $this->settings->get('mail_from', 'no-reply@localhost');
        $replyTo = $this->settings->get('mail_reply_to', '');

        $payload = [
            'from'    => $from,
            'to'      => [$to],
            'subject' => $subject,
            'text'    => $body,
        ];

        // Deliberately separate from `from` — lets outgoing mail be sent
        // via a dedicated transactional domain/service while replies still
        // land in a real, checked mailbox. Empty by default: only added if
        // an instance has actually configured one.
        if ($replyTo !== '') {
            $payload['reply_to'] = $replyTo;
        }

        if ($unsubscribeUrl !== null) {
            $payload['headers'] = [
                'List-Unsubscribe'      => "<{$unsubscribeUrl}>",
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ];
        }

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Mailer: Resend API failed sending '{$subject}' to {$to} — HTTP {$httpCode}" . ($curlError !== '' ? " (curl: {$curlError})" : '') . ($response ? " body: {$response}" : ''));

            return false;
        }

        return true;
    }
}
