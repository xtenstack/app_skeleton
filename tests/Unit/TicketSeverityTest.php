<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REQ-195: the app's own severity scale (low/normal/high/critical)
 * never matched RB-18/the SLA docs' S1-S4 scale. Tickets::normalizeSeverity()
 * is the reconciliation point every write path (backend, frontend, api)
 * now goes through — pure static method, no DB needed.
 */
final class TicketSeverityTest extends TestCase
{
    public function testAcceptsCanonicalValuesCaseInsensitively(): void
    {
        $this->assertSame('critical', \Tickets::normalizeSeverity('critical'));
        $this->assertSame('critical', \Tickets::normalizeSeverity('CRITICAL'));
        $this->assertSame('low', \Tickets::normalizeSeverity('Low'));
    }

    public function testAcceptsSlaCodesCaseInsensitivelyAndMapsToTheCorrectCanonicalValue(): void
    {
        $this->assertSame('critical', \Tickets::normalizeSeverity('S1'));
        $this->assertSame('high', \Tickets::normalizeSeverity('s2'));
        $this->assertSame('normal', \Tickets::normalizeSeverity('S3'));
        $this->assertSame('low', \Tickets::normalizeSeverity('s4'));
    }

    public function testReturnsNullForAnythingUnrecognized(): void
    {
        $this->assertNull(\Tickets::normalizeSeverity('urgent'));
        $this->assertNull(\Tickets::normalizeSeverity(''));
        $this->assertNull(\Tickets::normalizeSeverity('S5'));
    }

    public function testSeverityOptionsPairsEachValueWithItsSlaCode(): void
    {
        $options = \Tickets::severityOptions();

        $this->assertSame('Low / S4', $options['low']);
        $this->assertSame('Normal / S3', $options['normal']);
        $this->assertSame('High / S2', $options['high']);
        $this->assertSame('Critical / S1', $options['critical']);
    }
}
