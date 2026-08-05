<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ControllerBase::enforceCsrf() (app/modules/backend/controllers/ControllerBase.php)
 * is what CLAUDE.md's "no POST endpoint without the CSRF token check"
 * rule actually depends on — it's centralized precisely so no individual
 * controller can accidentally skip it, which also means a regression
 * here would silently affect every POST action in the module at once.
 * Real HTTP against the real running stack — see HttpClient's own
 * docblock for why this isn't dispatched in-process.
 */
final class CsrfProtectionTest extends TestCase
{
    public function testPostWithoutTokenIsRejected(): void
    {
        $client = new HttpClient();

        // A POST with no CSRF field at all — not a wrong token, no
        // field present, the simplest and most common way this would
        // actually regress (a form that forgot the hidden field, or a
        // hand-rolled request that never had one to begin with).
        $response = $client->post('/backend/session/login', [
            'email'    => 'nobody@example.com',
            'password' => 'irrelevant',
        ]);

        $this->assertSame(200, $response['status'], 'expected the request to be redirected back to a real page, not fail outright');
        $this->assertStringContainsString(
            'session expired',
            $response['body'],
            'expected enforceCsrf()\'s flash message on a token-less POST — got something else, meaning the request was not rejected the way it should have been'
        );
        $this->assertStringNotContainsString(
            'Invalid email or password',
            $response['body'],
            'the request reached loginAction() and evaluated credentials at all — enforceCsrf() should have stopped it first'
        );
    }

    public function testPostWithValidTokenIsNotRejectedByCsrfCheck(): void
    {
        // Not a full login-success test (credentials are deliberately
        // wrong) — this only proves a *present, valid* token clears
        // enforceCsrf() and the request reaches loginAction() itself,
        // the necessary counterpart to the rejection test above so a
        // trivial "reject everything" bug couldn't pass it by accident.
        $client = new HttpClient();
        $page   = $client->get('/backend/session');
        $csrf   = HttpClient::extractCsrf($page['body']);

        $this->assertNotSame('', $csrf['token'], 'login page did not embed a CSRF token to begin with');

        $response = $client->post('/backend/session/login', [
            'email'      => 'nobody@example.com',
            'password'   => 'irrelevant',
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringContainsString('Invalid email or password', $response['body']);
        $this->assertStringNotContainsString('session expired', $response['body']);
    }
}
