<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

/**
 * Usage: ./run search-resolve run [dry-run] [limit]
 *
 * Implements MAA-20260908-005's Search Engine Resolver -- Brave only for
 * now. Google CSE and Bing were both specified in that handover but
 * neither has a provisioned key (checked api-keys.env, XTMK Session 4);
 * this task is written so adding a second provider later is a new
 * private method plus one more entry in $this->providers(), not a
 * rewrite.
 *
 * Deliberately conservative, matching CampaignSendTask's posture:
 *  - never wired into cron -- run by hand until proven safe over real
 *    queries and real writes.
 *  - a hard daily cap (33 -- Brave's real free-tier pace, 1,000/month)
 *    enforced by counting today's search_resolution_log rows for this
 *    provider, not just trusting the caller's limit argument.
 *  - a candidate only gets written to entity_domains/contacts if the
 *    fetched page's own text contains the target ABN and it passes the
 *    ATO Modulo-89 checksum -- matching the ABN found is what "verified"
 *    means here, a plausible-looking domain on its own is not enough.
 *  - every attempt is logged to search_resolution_log immediately
 *    (resolved or not) so a dead end is never re-queried inside its
 *    90-day window (see v_stubborn_search_queue, migration 027/028).
 */
class SearchResolveTask extends \Phalcon\Cli\Task
{
    private const DAILY_CAP        = 33;
    private const HTTP_TIMEOUT     = 10;
    private const NEGATIVE_DOMAINS = [
        'yellowpages.com.au', 'truelocal.com.au', 'whitepages.com.au',
        'hipages.com.au', 'womo.com.au', 'linkedin.com', 'facebook.com',
        'cylex.com.au', 'startlocal.com.au', 'whereis.com', 'dnb.com', 'yelp.com', 'yelp.com.au', 'creditorwatch.com.au',
    ];
    private const ALLOWED_TLD_SUFFIXES = ['.com.au', '.net.au', '.org.au', '.au'];
    private const JS_PACKAGE_TOKENS    = ['react', 'lodash', 'core-js', 'webpack', 'vue', 'jquery', 'bootstrap'];

    public function mainAction(): void
    {
        echo 'Usage: ./run search-resolve run [dry-run] [limit]' . PHP_EOL;
    }

    public function runAction($mode = null, $limitArg = null): void
    {
        $dryRun   = ($mode === 'dry-run');
        $db       = $this->db;
        $braveKey = $this->config->search->brave_api_key ?? '';

        if ($braveKey === '') {
            echo 'BRAVE_API_KEY not configured -- set it in .env / config.local.php first.' . PHP_EOL;

            return;
        }

        $alreadyToday = (int) $db->fetchOne(
            "SELECT count(*) AS c FROM abn_lookup.search_resolution_log
             WHERE provider = 'brave' AND searched_at::date = CURRENT_DATE",
            \Phalcon\Db\Enum::FETCH_ASSOC
        )['c'];

        $remaining = self::DAILY_CAP - $alreadyToday;

        if ($remaining <= 0) {
            echo "Brave daily cap (" . self::DAILY_CAP . ") already used today ({$alreadyToday}) -- try again tomorrow." . PHP_EOL;

            return;
        }

        $limit = $limitArg !== null ? min((int) $limitArg, $remaining) : min(10, $remaining);

        $candidates = $db->fetchAll(
            'SELECT abn, search_name, postcode, state, anzsic_code, priority_score
             FROM abn_lookup.v_stubborn_search_queue
             ORDER BY priority_score DESC
             LIMIT :lim',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['lim' => $limit]
        );

        if (!$candidates) {
            echo 'No candidates in v_stubborn_search_queue.' . PHP_EOL;

            return;
        }

        echo count($candidates) . ($dryRun ? ' candidate(s) [dry-run, no writes]:' : ' candidate(s):') . PHP_EOL;

        foreach ($candidates as $row) {
            $this->resolveOne($row, $braveKey, $dryRun);
        }
    }

    private function resolveOne(array $row, string $braveKey, bool $dryRun): void
    {
        $db    = $this->db;
        $abn   = $row['abn'];
        $query = sprintf(
            '"%s" %s %s %s',
            $row['search_name'],
            $row['postcode'] ?? '',
            $row['state'] ?? '',
            '-site:' . implode(' -site:', self::NEGATIVE_DOMAINS)
        );

        echo "  {$row['search_name']} ({$abn}) -- querying Brave..." . PHP_EOL;

        $results = $this->braveSearch($query, $braveKey);

        $domain = $this->pickCandidateDomain($results);

        if (!$domain) {
            echo "    no usable result" . PHP_EOL;
            $this->logAttempt($abn, $query, null, false, 'no_match', $dryRun);

            return;
        }

        [$verified, $foundEmail] = $this->verifyAndExtract($domain, $abn);

        if (!$verified) {
            echo "    candidate {$domain} -- ABN not confirmed on page, not writing" . PHP_EOL;
            $this->logAttempt($abn, $query, $domain, false, 'unverified', $dryRun);

            return;
        }

        echo "    VERIFIED {$domain}" . ($foundEmail ? " <{$foundEmail}>" : ' (no email found)') . PHP_EOL;

        if ($dryRun) {
            return;
        }

        $db->execute(
            "INSERT INTO abn_lookup.entity_domains (abn, domain, source, verified_by, checked_at)
             VALUES (:abn, :domain, 'search_resolver', 'modulo89', now())
             ON CONFLICT (abn, domain) DO NOTHING",
            ['abn' => $abn, 'domain' => $domain]
        );

        if ($foundEmail) {
            $db->execute(
                "INSERT INTO abn_lookup.contacts (abn, domain, kind, value, source_url, extraction_method, fetched_at)
                 VALUES (:abn, :domain, 'email', :email, :url, 'search_resolver', now())
                 ON CONFLICT (domain, kind, value, source_url) DO NOTHING",
                ['abn' => $abn, 'domain' => $domain, 'email' => $foundEmail, 'url' => "https://{$domain}"]
            );
        }

        $this->logAttempt($abn, $query, $domain, true, 'modulo89_verified', false);
    }

    private function braveSearch(string $query, string $apiKey): array
    {
        $url = 'https://api.search.brave.com/res/v1/web/search?' . http_build_query(['q' => $query, 'count' => 5]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', "X-Subscription-Token: {$apiKey}"],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$body) {
            error_log("SearchResolveTask: Brave API returned HTTP {$code} for query [{$query}]");

            return [];
        }

        $data = json_decode($body, true);

        return $data['web']['results'] ?? [];
    }

    private function pickCandidateDomain(array $results): ?string
    {
        foreach (array_slice($results, 0, 3) as $result) {
            $url  = $result['url'] ?? '';
            $host = parse_url($url, PHP_URL_HOST);

            if (!$host) {
                continue;
            }

            $host = preg_replace('/^www\./', '', strtolower($host));

            foreach (self::NEGATIVE_DOMAINS as $bad) {
                if (str_contains($host, $bad)) {
                    continue 2;
                }
            }

            foreach (self::ALLOWED_TLD_SUFFIXES as $suffix) {
                if (str_ends_with($host, $suffix)) {
                    return $host;
                }
            }
        }

        return null;
    }

    /** @return array{0: bool, 1: ?string} [verified, foundEmail] */
    private function verifyAndExtract(string $domain, string $targetAbn): array
    {
        $html = $this->fetch("https://{$domain}") ?? $this->fetch("https://{$domain}/contact");

        if (!$html) {
            return [false, null];
        }

        $text = preg_replace('#<(script|style|code|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = strip_tags($text);

        $abnFound = false;

        if (preg_match_all('/\b\d{2}\s?\d{3}\s?\d{3}\s?\d{3}\b/', $text, $m)) {
            foreach ($m[0] as $candidate) {
                $digits = preg_replace('/\D/', '', $candidate);

                if ($digits === $targetAbn && $this->isValidAbnChecksum($digits)) {
                    $abnFound = true;

                    break;
                }
            }
        }

        if (!$abnFound) {
            return [false, null];
        }

        $email = null;

        if (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}/', $text, $em)) {
            foreach ($em[0] as $candidate) {
                $lower = strtolower($candidate);

                if (preg_match('/\d+\.\d+\.\d+/', $candidate)) {
                    continue; // semver, e.g. react@18.3.1's tail
                }

                if (preg_match('/\.(png|jpg|jpeg|svg|gif|webp)$/i', $candidate)) {
                    continue;
                }

                $isJsPackage = false;

                foreach (self::JS_PACKAGE_TOKENS as $token) {
                    if (str_starts_with($lower, $token . '@') || str_contains($lower, '@' . $token)) {
                        $isJsPackage = true;

                        break;
                    }
                }

                if ($isJsPackage) {
                    continue;
                }

                $emailDomain = substr(strrchr($candidate, '@'), 1);

                if (!checkdnsrr($emailDomain, 'MX')) {
                    continue;
                }

                $email = $candidate;

                break;
            }
        }

        return [true, $email];
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; XTenSearchResolver/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($code === 200 && $body) ? $body : null;
    }

    private function isValidAbnChecksum(string $abn): bool
    {
        if (strlen($abn) !== 11 || !ctype_digit($abn)) {
            return false;
        }

        $weights = [10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19];
        $sum     = 0;

        foreach (str_split($abn) as $i => $digit) {
            $value = (int) $digit - ($i === 0 ? 1 : 0);
            $sum  += $value * $weights[$i];
        }

        return $sum % 89 === 0;
    }

    private function logAttempt(string $abn, string $query, ?string $domain, bool $resolved, string $method, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $this->db->execute(
            'INSERT INTO abn_lookup.search_resolution_log (abn, provider, query_used, found_domain, is_resolved, match_method, searched_at)
             VALUES (:abn, :provider, :query, :domain, :resolved, :method, now())
             ON CONFLICT (abn) DO UPDATE SET
                provider = EXCLUDED.provider, query_used = EXCLUDED.query_used,
                found_domain = EXCLUDED.found_domain, is_resolved = EXCLUDED.is_resolved,
                match_method = EXCLUDED.match_method, searched_at = EXCLUDED.searched_at',
            [
                'abn'      => $abn,
                'provider' => 'brave',
                'query'    => $query,
                'domain'   => $domain,
                'resolved' => $resolved ? 't' : 'f',
                'method'   => $method,
            ]
        );
    }
}
