<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REQ-225: confirms KbArticlesController's backend create POST goes
 * through the same central CSRF check every other backend POST does
 * (ControllerBase::enforceCsrf(), app/modules/backend/controllers/
 * ControllerBase.php) — CLAUDE.md's "no POST endpoint without the CSRF
 * token check" rule, and this feature's own new controller is exactly
 * the kind of addition that rule exists to catch a regression in.
 * enforceCsrf() runs before the login/role checks (see ControllerBase's
 * own docblock), so a token-less POST here is rejected the same way
 * regardless of whether the caller would otherwise be authorized — no
 * fixture user/session needed, same shape as CsrfProtectionTest. Real
 * HTTP against the real running stack — see HttpClient's own docblock
 * for why this isn't dispatched in-process.
 */
final class KbArticlesCsrfTest extends TestCase
{
    public function testCreatePostWithoutCsrfTokenIsRejected(): void
    {
        $client = new HttpClient();

        $response = $client->post('/backend/kb-articles/create', [
            'title' => 'should never be created',
            'body'  => 'no csrf token supplied',
        ]);

        $this->assertSame(200, $response['status'], 'expected the request to be redirected back to a real page, not fail outright');
        $this->assertStringContainsString(
            'session expired',
            $response['body'],
            'expected enforceCsrf()\'s flash message on a token-less POST — got something else, meaning the request was not rejected the way it should have been'
        );
    }
}
