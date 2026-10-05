<?php

declare(strict_types=1);

use App_skeleton\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Mailer::send()'s $attachments: the shape handed to the provider, and
 * that a document the caller meant to attach is never silently left off.
 * Nothing here reaches the network: a refused attachment fails before any
 * request, and an .invalid recipient is skipped by the mailer itself.
 */
final class MailerAttachmentsTest extends TestCase
{
    private string $errorLog;

    private string $previousErrorLog;

    protected function setUp(): void
    {
        $this->errorLog         = (string) tempnam(sys_get_temp_dir(), 'app_skeleton_mailer_log_');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        unlink($this->errorLog);
    }

    public function testAttachmentsAreBase64EncodedWithTheirNameAndType(): void
    {
        $pdf = "%PDF-1.4\n\x00\xff binary";

        self::assertSame(
            [
                ['filename' => 'INV-0001.pdf', 'content' => base64_encode($pdf), 'content_type' => 'application/pdf'],
                ['filename' => 'notes.txt', 'content' => base64_encode('hello')],
            ],
            $this->payload([
                ['filename' => 'INV-0001.pdf', 'content' => $pdf, 'content_type' => 'application/pdf'],
                ['filename' => 'notes.txt', 'content' => 'hello', 'content_type' => 'not a type'],
            ]),
            'an unusable content_type is dropped, the file is still sent'
        );
        self::assertSame([], $this->payload([]));
    }

    /**
     * @dataProvider unusableAttachments
     *
     * @param array<int, mixed> $attachments
     */
    public function testAnUnusableAttachmentFailsTheSendInsteadOfBeingLeftOff(array $attachments): void
    {
        self::assertNull($this->payload($attachments));
        self::assertFalse((new Mailer())->send('someone@example.invalid', 'Invoice', 'Body', null, false, $attachments));
        self::assertStringContainsString('not sent', (string) file_get_contents($this->errorLog));
    }

    /** @return array<string, array{0: array<int, mixed>}> */
    public static function unusableAttachments(): array
    {
        return [
            'no content'          => [[['filename' => 'a.pdf']]],
            'empty content'       => [[['filename' => 'a.pdf', 'content' => '']]],
            'no filename'         => [[['content' => 'x']]],
            'a path, not a name'  => [[['filename' => '../a.pdf', 'content' => 'x']]],
            'a header injection'  => [[['filename' => "a.pdf\r\nBcc: x@example.com", 'content' => 'x']]],
            'not an array'        => [['a.pdf']],
            'too large in total'  => [[
                ['filename' => 'a.bin', 'content' => str_repeat('a', Mailer::MAX_ATTACHMENT_BYTES)],
                ['filename' => 'b.bin', 'content' => 'b'],
            ]],
        ];
    }

    public function testAnExistingCallerWithoutAttachmentsIsUnchanged(): void
    {
        // .invalid is never delivered to: true, with nothing sent.
        self::assertTrue((new Mailer())->send('someone@example.invalid', 'Subject', 'Body'));
        self::assertTrue((new Mailer())->send('someone@example.invalid', 'Subject', 'Body', attachments: [
            ['filename' => 'INV-0001.pdf', 'content' => '%PDF-1.4', 'content_type' => 'application/pdf'],
        ]));
    }

    /**
     * @param array<int, mixed> $attachments
     *
     * @return array<int, array<string, string>>|null
     */
    private function payload(array $attachments): ?array
    {
        $method = new \ReflectionMethod(Mailer::class, 'attachmentsPayload');
        $method->setAccessible(true);

        return $method->invoke(null, $attachments);
    }
}
