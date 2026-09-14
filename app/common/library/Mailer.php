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
 * Credential source: `external_connections` (name='resend'), the
 * standard store every module/library is meant to use — see
 * MODULE-SPEC.md "External Credentials". Falls back to
 * `mail.resend_api_key` in config.local.php (the pre-2026-09-13 path)
 * only if no active 'resend' row exists yet, so an instance that
 * hasn't migrated its key into ExternalConnections doesn't silently
 * stop sending mail. Falls back further to logging and returning true
 * if neither is set, same "don't block the request over a mail
 * failure" posture the old mail()-based version had.
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
     *
     * Return value is `string|bool`, not a plain bool, so a caller that
     * wants to correlate a later bounce/complaint webhook back to this
     * exact send (WebhookController::resendAction()) has Resend's own
     * message ID to key on — campaign_sends.provider_message_id, added
     * alongside this. `false` on a real API failure; `true` (no ID) on
     * the two shortcut paths below where nothing was actually sent to
     * Resend at all, so there is no real message ID to return. Both
     * still read as truthy for the existing `$sent ? 'sent' : 'failed'`
     * call site — only `is_string($sent)` callers need to care about
     * the difference.
     *
     * $isHtml sends $body as Resend's `html` part, with a tag-stripped
     * `text` fallback alongside it. Default false keeps every existing
     * caller (signup, password reset, the plain-text campaign templates)
     * sending exactly what it sent before.
     */
    public function send(string $to, string $subject, string $body, ?string $unsubscribeUrl = null, bool $isHtml = false): string|bool
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

        $connection = \ExternalConnections::findActiveByName('resend');
        $apiKey     = $connection ? ($connection->revealCredential() ?? '') : ($this->config->mail->resend_api_key ?? '');

        if ($apiKey === '') {
            error_log("Mailer: no Resend API key configured (checked external_connections 'resend' and mail.resend_api_key) -- '{$subject}' to {$to} not sent.");

            return true;
        }

        $from    = $this->settings->get('mail_from', 'no-reply@localhost');
        $replyTo = $this->settings->get('mail_reply_to', '');

        $payload = [
            'from'    => $from,
            'to'      => [$to],
            'subject' => $subject,
            'text'    => $isHtml ? self::htmlToText($body) : $body,
        ];

        if ($isHtml) {
            $payload['html'] = $body;
        }

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

        $decoded = json_decode((string) $response, true);
        $id      = is_array($decoded) ? ($decoded['id'] ?? null) : null;

        if (!is_string($id) || $id === '') {
            // Resend returned 200 but not the shape we expect -- still a
            // real send (don't report failure for something that likely
            // went out), just nothing to correlate a bounce against later.
            error_log("Mailer: Resend API returned 200 but no message id sending '{$subject}' to {$to} — body: {$response}");

            return true;
        }

        return $id;
    }

    /**
     * Plain-text fallback for an HTML body: drops <head>/<style>/<script>
     * blocks, turns block-level closes and <br> into line breaks, keeps a
     * link's URL next to its text, then strips the remaining tags.
     */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('#<(head|style|script)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = preg_replace('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $text) ?? $text;
        $text = preg_replace('#<br\s*/?>|</(p|div|tr|h[1-6]|li)>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
