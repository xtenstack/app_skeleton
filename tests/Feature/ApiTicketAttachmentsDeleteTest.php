<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * MAA-20261102-002: a service key may delete an attachment on a ticket it
 * raised while the ticket is open; other keys get 404; a closed ticket is
 * 409 for the raiser but staff may still delete. The file leaves the disk.
 * Real HTTP against the running stack, like ApiTicketMessagesTest.
 */
final class ApiTicketAttachmentsDeleteTest extends TestCase
{
    private static int $serviceUserId;
    private static int $otherUserId;
    private static int $adminUserId;
    private static string $serviceToken;
    private static string $otherToken;
    private static string $adminToken;
    private static int $serviceKeyId;
    private static int $otherKeyId;
    private static int $adminKeyId;
    /** @var int[] */
    private static array $ticketIds = [];

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        $adminRoleId = \Roles::idsByNames(['admin'])[0] ?? null;
        self::assertNotNull($adminRoleId, "fixture setup requires an 'admin' role — run ./run seed");

        $tag                 = bin2hex(random_bytes(6));
        self::$serviceUserId = self::makeUser("phpunit-attdel-service-$tag@example.invalid", 2);
        self::$otherUserId   = self::makeUser("phpunit-attdel-other-$tag@example.invalid", 2);
        self::$adminUserId   = self::makeUser("phpunit-attdel-admin-$tag@example.invalid", (int) $adminRoleId);
        [self::$serviceKeyId, self::$serviceToken] = self::makeKey(self::$serviceUserId, 'service');
        [self::$otherKeyId, self::$otherToken]     = self::makeKey(self::$otherUserId, 'other');
        [self::$adminKeyId, self::$adminToken]     = self::makeKey(self::$adminUserId, 'admin');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$ticketIds as $ticketId) {
            foreach (\TicketAttachments::find(['conditions' => 'ticket_id = :t:', 'bind' => ['t' => $ticketId]]) as $attachment) {
                @unlink(BASE_PATH . '/storage/ticket-attachments/' . $ticketId . '/' . $attachment->filename);
                $attachment->delete();
            }
            @rmdir(BASE_PATH . '/storage/ticket-attachments/' . $ticketId);
            \Tickets::findFirstById($ticketId)?->delete();
        }
        foreach ([self::$serviceKeyId, self::$otherKeyId, self::$adminKeyId] as $keyId) {
            \ApiKeys::findFirstById($keyId)?->delete();
        }
        foreach ([self::$serviceUserId, self::$otherUserId, self::$adminUserId] as $userId) {
            \Users::findFirstById($userId)?->softDelete();
        }
    }

    private static function makeUser(string $email, int $roleId): int
    {
        $user                = new \Users();
        $user->email         = $email;
        $user->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'AttachmentDelete';
        $user->role_id       = $roleId;
        $user->is_active     = 1;
        self::assertTrue($user->save(), 'fixture user failed to save: ' . implode('; ', $user->getMessages()));

        return (int) $user->id;
    }

    /** @return array{0: int, 1: string} */
    private static function makeKey(int $userId, string $label): array
    {
        $token             = "phpunit-attdel-$label-" . bin2hex(random_bytes(16));
        $key               = new \ApiKeys();
        $key->user_id      = $userId;
        $key->name         = "phpunit-attdel-$label-key";
        $key->token_hash   = hash('sha256', $token);
        $key->token_prefix = substr($token, 0, 10);
        self::assertTrue($key->save(), 'fixture api key failed to save: ' . implode('; ', $key->getMessages()));

        return [(int) $key->id, $token];
    }

    private function ticketRaisedBy(int $keyId, int $userId, string $status = 'open'): \Tickets
    {
        $ticket                      = new \Tickets();
        $ticket->title               = 'phpunit-attdel-fixture-' . bin2hex(random_bytes(4));
        $ticket->description         = 'fixture ticket for ApiTicketAttachmentsDeleteTest';
        $ticket->severity            = 'normal';
        $ticket->ticket_type         = 'support';
        $ticket->status              = $status;
        $ticket->source_ref          = 'phpunit:' . bin2hex(random_bytes(4));
        $ticket->reporter_user_id    = $userId;
        $ticket->reporter_api_key_id = $keyId;
        self::assertTrue($ticket->save(), 'fixture ticket failed to save: ' . implode('; ', $ticket->getMessages()));
        self::$ticketIds[] = (int) $ticket->id;

        return $ticket;
    }

    /** @return array{0: \TicketAttachments, 1: string} the row and the file path on disk */
    private function attachmentOn(\Tickets $ticket): array
    {
        // phpunit runs as root in CI while the web server runs as www-data:
        // the directory and file must be deletable by the web process, as
        // they are when the upload endpoint itself created them.
        $dir = BASE_PATH . '/storage/ticket-attachments/' . (int) $ticket->id;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        @chmod($dir, 0777);
        $filename = bin2hex(random_bytes(8)) . '.txt';
        $path     = $dir . '/' . $filename;
        file_put_contents($path, "phpunit attachment\n");
        @chmod($path, 0666);

        $attachment                      = new \TicketAttachments();
        $attachment->ticket_id           = (int) $ticket->id;
        $attachment->filename            = $filename;
        $attachment->original_filename   = 'phpunit.txt';
        $attachment->mime_type           = 'text/plain';
        $attachment->size_bytes          = filesize($path);
        $attachment->uploaded_by_user_id = self::$serviceUserId;
        self::assertTrue($attachment->save(), 'fixture attachment failed to save: ' . implode('; ', $attachment->getMessages()));

        return [$attachment, $path];
    }

    private function deleteAs(string $token, int $ticketId, int $attachmentId): array
    {
        return (new HttpClient())->postJson("/api/tickets/delete-attachment/$ticketId/$attachmentId", [], ['X-Api-Key: ' . $token]);
    }

    public function testRaiserDeletesItsOwnAttachmentAndTheFileLeavesTheDisk(): void
    {
        $ticket = $this->ticketRaisedBy(self::$serviceKeyId, self::$serviceUserId);
        [$attachment, $path] = $this->attachmentOn($ticket);

        $res = $this->deleteAs(self::$serviceToken, (int) $ticket->id, (int) $attachment->id);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertNull(\TicketAttachments::findFirstById((int) $attachment->id));
        clearstatcache(true, $path);
        $this->assertFileDoesNotExist($path);
    }

    public function testAnotherKeyGets404AndNothingIsDeleted(): void
    {
        $ticket = $this->ticketRaisedBy(self::$serviceKeyId, self::$serviceUserId);
        [$attachment, $path] = $this->attachmentOn($ticket);

        $res = $this->deleteAs(self::$otherToken, (int) $ticket->id, (int) $attachment->id);

        $this->assertSame(404, $res['status'], $res['body']);
        $this->assertNotNull(\TicketAttachments::findFirstById((int) $attachment->id));
        $this->assertFileExists($path);
    }

    public function testClosedTicketIs409ForTheRaiserButStaffMayStillDelete(): void
    {
        $ticket = $this->ticketRaisedBy(self::$serviceKeyId, self::$serviceUserId, 'closed');
        [$attachment, $path] = $this->attachmentOn($ticket);

        $res = $this->deleteAs(self::$serviceToken, (int) $ticket->id, (int) $attachment->id);
        $this->assertSame(409, $res['status'], $res['body']);
        $this->assertFileExists($path);

        $res = $this->deleteAs(self::$adminToken, (int) $ticket->id, (int) $attachment->id);
        $this->assertSame(200, $res['status'], $res['body']);
        clearstatcache(true, $path);
        $this->assertFileDoesNotExist($path);
    }

    public function testGetIs405(): void
    {
        $ticket = $this->ticketRaisedBy(self::$serviceKeyId, self::$serviceUserId);
        [$attachment] = $this->attachmentOn($ticket);

        $res = (new HttpClient())->getWithHeaders("/api/tickets/delete-attachment/{$ticket->id}/{$attachment->id}", ['X-Api-Key: ' . self::$serviceToken]);

        $this->assertSame(405, $res['status'], $res['body']);
    }
}
