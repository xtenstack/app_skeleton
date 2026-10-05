<?php

declare(strict_types=1);

use App_skeleton\Pdf;
use App_skeleton\Pdf\Document;
use App_skeleton\Pdf\Fonts;
use App_skeleton\Pdf\PdfException;
use App_skeleton\Pdf\Text;
use App_skeleton\Pdf\Typesetter;
use Phalcon\Di\FactoryDefault;
use PHPUnit\Framework\TestCase;

/**
 * The `pdf` service (App_skeleton\Pdf) and its document builder, as a
 * module uses them (docs/MODULE-SPEC.md, "PDF documents"). Documents are
 * built with `compress => false`, which leaves each page's drawing
 * instructions readable in the bytes, so a test can say what text is on
 * which page. No database and no network.
 */
final class PdfTest extends TestCase
{
    private static function service(): Pdf
    {
        return new Pdf();
    }

    private static function document(array $options = []): Document
    {
        return self::service()->document($options + ['compress' => false]);
    }

    /** @return list<string> each page's content, in page order (uncompressed documents only) */
    private static function pages(string $pdf): array
    {
        // Each page object names its content object; other streams in the
        // file (the fonts' character maps) are not pages.
        preg_match_all('#/Type /Page\b(?!s).*?/Contents (\d+) 0 R#s', $pdf, $m);

        $pages = [];

        foreach ($m[1] as $object) {
            if (preg_match('/\n' . $object . ' 0 obj\n<<[^>]*>>\nstream\n(.*?)\nendstream\nendobj/s', $pdf, $content)) {
                $pages[] = $content[1];
            }
        }

        return $pages;
    }

    /** @return int[] the pages (0-based) whose content shows $text */
    private static function pagesShowing(string $pdf, string $text): array
    {
        $found = [];

        foreach (self::pages($pdf) as $n => $content) {
            // Text is drawn as "(…) Tj", with brackets and backslashes escaped.
            if (str_contains($content, '(' . strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ') Tj')) {
                $found[] = $n;
            }
        }

        return $found;
    }

    /**
     * A PDF reader finds every object through the cross-reference table at
     * the end of the file. If any text had upset the file's structure,
     * these offsets would no longer point at the objects.
     */
    private function assertIsAWellFormedPdf(string $pdf): void
    {
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);

        $this->assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m), 'startxref is present');
        $this->assertSame('xref', substr($pdf, (int) $m[1], 4), 'startxref points at the cross-reference table');

        $this->assertSame(1, preg_match('/xref\n0 (\d+)\n((?:\d{10} \d{5} [nf] ?\n)+)trailer/', substr($pdf, (int) $m[1]), $table));

        $entries = explode("\n", trim($table[2]));
        $this->assertCount((int) $table[1], $entries);

        foreach ($entries as $number => $entry) {
            if ($number === 0) {
                continue;
            }

            $this->assertSame("{$number} 0 obj", substr($pdf, (int) substr($entry, 0, 10), strlen("{$number} 0 obj")), "object {$number} is where the table says");
        }
    }

    public function testFpdfIsARuntimeDependencyAndItsLicenceTextIsShipped(): void
    {
        $composer = json_decode((string) file_get_contents(BASE_PATH . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        // 1.8.2 exactly: later 1.x releases require ext-gd, which the runtime image does not have.
        $this->assertSame('1.8.2', $composer['require']['setasign/fpdf'] ?? null);
        $this->assertArrayNotHasKey('setasign/fpdf', $composer['require-dev']);

        // FPDF's own licence text, unchanged, and listed with the other third-party libraries.
        $this->assertFileEquals(BASE_PATH . '/vendor/setasign/fpdf/license.txt', BASE_PATH . '/licences/FPDF.txt');
        $this->assertStringContainsString('setasign/fpdf', (string) file_get_contents(BASE_PATH . '/licences/README.md'));
        $this->assertStringContainsString('FPDF.txt', (string) file_get_contents(BASE_PATH . '/licences/README.md'));
    }

    public function testSupportsAnswersForThisMajorVersionOnly(): void
    {
        $service = self::service();

        $this->assertSame('1.0.0', $service->interfaceVersion());
        $this->assertSame(Pdf::VERSION, $service->interfaceVersion());

        foreach (['1', '1.0', '1.0.0'] as $yes) {
            $this->assertTrue($service->supports($yes), $yes);
        }

        foreach (['1.1', '1.0.1', '2.0', '0.9', '2', '', 'one', '1.x', '-1'] as $no) {
            $this->assertFalse($service->supports($no), var_export($no, true));
        }
    }

    /** services.php is shared by web and CLI, so the service exists on both. */
    public function testTheServiceIsRegisteredInTheContainer(): void
    {
        $di = new FactoryDefault();
        require APP_PATH . '/config/services.php';

        $this->assertTrue($di->has('pdf'));

        $service = $di->getShared('pdf');
        $this->assertInstanceOf(Pdf::class, $service);
        $this->assertSame($service, $di->getShared('pdf'), 'one shared instance');
        $this->assertTrue($service->supports('1.0'));
        $this->assertStringStartsWith('%PDF-', $service->document()->paragraph('Hello')->output());
    }

    public function testAnEmptyDocumentIsStillAPdf(): void
    {
        $pdf = self::document()->output();

        $this->assertIsAWellFormedPdf($pdf);
        $this->assertCount(1, self::pages($pdf));
    }

    public function testOutputIsAPdfThatContainsTheGivenStrings(): void
    {
        $build = static fn (array $options): string => self::service()->document($options + ['title' => 'Statement 42', 'author' => 'Example Pty Ltd'])
            ->heading('Statement of account')
            ->heading('October', 2)
            ->heading('Detail', 3, ['align' => 'right'])
            ->paragraph('Everything you bought this month.')
            ->paragraph('Small print.', ['size' => 'small', 'muted' => true, 'style' => 'bold', 'align' => 'center'])
            ->spacer()
            ->keyValues([
                ['title' => 'From', 'lead' => 'Example Pty Ltd', 'rows' => [['ABN', '51 824 753 556'], ['', "12 Example Street\nPerth WA 6000"]]],
                ['title' => 'To', 'lead' => 'A Customer', 'rows' => [['Account', 'C-0042']]],
            ])
            ->keyValues([['rows' => [['Reference', 'R-9']]], ['lead' => 'Second block']], ['layout' => 'stacked', 'labelWidth' => 30])
            ->table(
                [['label' => 'Item'], ['label' => 'Qty', 'width' => 20, 'align' => 'right'], ['label' => 'Amount', 'width' => 30, 'align' => 'right']],
                [['Widget', 3, '31.50'], ['Gadget', '1', '9.95'], ['Nothing', null, 0.5]],
                ['size' => 'small']
            )
            ->totals([['Subtotal', '41.45'], ['Tax', '4.15'], ['Total', '45.60', true]], ['width' => 70])
            ->output();

        $plain = $build(['compress' => false]);
        $this->assertIsAWellFormedPdf($plain);

        foreach ([
            'Statement of account', 'October', 'Detail', 'Everything you bought this month.', 'Small print.',
            'From', 'Example Pty Ltd', 'ABN', '51 824 753 556', '12 Example Street', 'Perth WA 6000', 'To', 'A Customer', 'Account', 'C-0042',
            'Reference', 'R-9', 'Second block', 'Item', 'Qty', 'Amount', 'Widget', '3', '31.50', 'Gadget', '9.95', 'Nothing', '0.5',
            'Subtotal', '41.45', 'Tax', '4.15', 'Total', '45.60',
        ] as $text) {
            $this->assertNotSame([], self::pagesShowing($plain, $text), "the page shows '{$text}'");
        }

        $this->assertStringContainsString('Statement 42', $plain, 'the title is in the metadata');

        // Compression is on unless a test turns it off: same document, smaller, text not readable.
        $compressed = $build([]);
        $this->assertStringStartsWith('%PDF-', $compressed);
        $this->assertStringEndsWith("%%EOF\n", $compressed);
        $this->assertLessThan(strlen($plain), strlen($compressed));
        $this->assertStringNotContainsString('(Statement of account)', $compressed);
        $this->assertStringContainsString('/FlateDecode', $compressed);
    }

    /** @return array<string, array{0: string, 1: list<array{0: string, 1: string}>}> */
    public static function runsOfText(): array
    {
        return [
            'plain ASCII is one Western run'        => ['Invoice 42 (paid)', [['cp1252', 'Invoice 42 (paid)']]],
            'Western accents, dash, euro'           => ['Zoë — Café €5', [['cp1252', "Zo\xEB \x97 Caf\xE9 \x805"]]],
            // β is Greek; the micro sign is in the Greek page too, so the run is not broken for it.
            'Greek and ASCII share one run'         => ['β-mercaptoethanol 5 µL', [['cp1253', "\xE2-mercaptoethanol 5 \xB5L"]]],
            'Central European'                      => ['Łódź', [['cp1250', "\xA3\xF3d\x9F"]]],
            'Cyrillic'                              => ['Привет', [['cp1251', "\xCF\xF0\xE8\xE2\xE5\xF2"]]],
            'leading ASCII joins the run it leads to' => ['abc Привет', [['cp1251', "abc \xCF\xF0\xE8\xE2\xE5\xF2"]]],
            'Western, Greek and Cyrillic in one sentence' => [
                'Café costs €5, in Greek καφές, in Russian кофе.',
                [
                    ['cp1252', "Caf\xE9 costs \x805, in Greek "],
                    ['cp1253', "\xEA\xE1\xF6\xDD\xF2, in Russian "],
                    ['cp1251', "\xEA\xEE\xF4\xE5."],
                ],
            ],
            'a page is left only when it lacks the character' => ['Привет déjà', [['cp1251', "\xCF\xF0\xE8\xE2\xE5\xF2 d"], ['cp1252', "\xE9j\xE0"]]],
            'Turkish'                               => ['İstanbul şehir', [['cp1254', "\xDDstanbul \xFEehir"]]],
            'Baltic'                                => ['Rīga', [['cp1257', "R\xEEga"]]],
            'empty'                                 => ['', []],
        ];
    }

    /**
     * @dataProvider runsOfText
     *
     * @param list<array{0: string, 1: string}> $expected
     */
    public function testAStringIsSplitIntoRunsByCodePage(string $text, array $expected): void
    {
        $this->assertSame($expected, Text::runs($text));

        // What the runs say is what was given.
        $this->assertSame($text, Typesetter::plain(Text::runs($text)));
    }

    public function testCharactersNoCodePageHasAreReplacedAndNothingThrows(): void
    {
        // Chinese, an emoji: plain ASCII (iconv's equivalent where it has one, otherwise "?"), in whatever run is current.
        foreach (['日本語', "\u{1F600}", 'العربية', 'עברית', '한국어'] as $unsupported) {
            $runs = Text::runs($unsupported);

            $this->assertCount(1, $runs, $unsupported);
            $this->assertSame('cp1252', $runs[0][0]);
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $runs[0][1], $unsupported);
        }

        $this->assertSame([['cp1252', '? ok']], array_map(static fn (array $run): array => [$run[0], preg_replace('/\?+/', '?', $run[1])], Text::runs('日本 ok')));
        $this->assertSame('cp1251', Text::runs('Привет 日本')[0][0], 'the replacement stays in the Cyrillic run');
        $this->assertCount(1, Text::runs('Привет 日本'));

        // Invalid UTF-8, control characters, odd separators and spaces.
        $this->assertSame([['cp1252', 'bad ?( utf']], Text::runs("bad \xC3\x28 utf"));
        $this->assertSame([['cp1252', 'ab']], Text::runs("a\x00\x07\x1Bb"));
        $this->assertSame("a\nb\nc\nd", Text::normalise("a\r\nb\rc\u{2028}d"));
        $this->assertSame('a    b c-d', Text::normalise("a\tb\u{00A0}c\u{2011}d"));
        $this->assertSame('', Text::normalise(''));
        $this->assertSame([['cp1252', 'plain ASCII (1) \\ ok']], Text::runs('plain ASCII (1) \\ ok'));

        foreach (['Zoë — Café', 'Łukasz 日本語', "\xFF\xFE\xFD", str_repeat("\u{1F600}", 50), "\u{202E}reversed", 'β-mercaptoethanol', 'Привет'] as $anything) {
            foreach (Text::runs($anything) as [$page, $bytes]) {
                $this->assertContains($page, Fonts::CODE_PAGES);
                $this->assertSame(1, preg_match('/^[\x0A\x20-\x7E\x80-\xFF]+$/', $bytes), 'only printable single bytes and line breaks come out');

                // Every byte of a run is a character its font really has.
                foreach (str_split($bytes) as $byte) {
                    if ($byte !== "\n") {
                        $this->assertNotSame(0xFFFD, Fonts::codePoint($page, ord($byte)), "{$page} has byte " . bin2hex($byte));
                        $this->assertArrayHasKey($byte, Fonts::widths($page, Fonts::BOLD));
                    }
                }
            }
        }
    }

    public function testTheFontsAreCommittedForEveryCodePageInRegularAndBold(): void
    {
        $this->assertSame(['cp1252', 'cp1250', 'cp1251', 'cp1253', 'cp1254', 'cp1257'], Fonts::CODE_PAGES);

        foreach (Fonts::CODE_PAGES as $page) {
            foreach ([Fonts::REGULAR, Fonts::BOLD] as $style) {
                $definition = Fonts::DIR . Fonts::file($page, $style);

                $this->assertFileExists($definition);
                $this->assertFileExists(substr($definition, 0, -4) . '.z', 'the embedded font program');
                $this->assertGreaterThan(500, Fonts::width($page, $style, 'W'));
            }

            // Bold is wider than regular in every page, so the two really are different fonts.
            $this->assertGreaterThan(Fonts::width($page, Fonts::REGULAR, 'Total 100.00'), Fonts::width($page, Fonts::BOLD, 'Total 100.00'));
        }

        $this->assertSame(0xE2, Fonts::byte('cp1253', 0x03B2), 'β in the Greek page');
        $this->assertNull(Fonts::byte('cp1252', 0x03B2), 'and not in the Western one');
        $this->assertSame(0x03B2, Fonts::codePoint('cp1253', 0xE2));

        // The DejaVu licence travels with the fonts, and the script that regenerates them is there.
        $licence = (string) file_get_contents(BASE_PATH . '/licences/DejaVu-Fonts.txt');
        $this->assertStringContainsString('Bitstream Vera Fonts Copyright', $licence);
        $this->assertStringContainsString('DejaVu changes are in public domain', $licence);
        $this->assertStringContainsString('DejaVu', (string) file_get_contents(BASE_PATH . '/licences/README.md'));
        $this->assertFileExists(BASE_PATH . '/bin/make-pdf-fonts.php');
    }

    public function testALongMixedScriptParagraphWrapsInsideTheWidth(): void
    {
        $sentence = 'Café costs €5, in Greek καφές κοστίζει πέντε ευρώ, in Russian кофе стоит пять евро, w Łodzi kawa kosztuje pięć euro.';
        $text     = implode(' ', array_fill(0, 12, $sentence));

        foreach ([[178.0, Fonts::REGULAR, 10.0], [60.0, Fonts::BOLD, 12.0], [25.0, Fonts::REGULAR, 8.5]] as [$width, $style, $points]) {
            $lines = Typesetter::lines($text, $width, $style, $points);

            $this->assertGreaterThan(5, count($lines));

            $joined = [];

            foreach ($lines as $n => $line) {
                $this->assertLessThanOrEqual($width + 0.0001, Typesetter::width($line, $style, $points), 'line ' . ($n + 1) . " fits {$width} mm");

                $plain = Typesetter::plain($line);
                $this->assertSame(trim($plain), $plain, 'no line starts or ends with a space');
                $this->assertNotSame('', $plain);
                $joined[] = $plain;
            }

            // Breaks fall between words: putting the lines back together gives the text.
            $this->assertSame($text, implode(' ', $joined));

            // A line is as full as it can be: the next word would not have fitted.
            for ($n = 0; $n < count($lines) - 1; $n++) {
                $next = explode(' ', $joined[$n + 1])[0];

                $this->assertGreaterThan($width, Typesetter::width(Text::runs($joined[$n] . ' ' . $next), $style, $points), 'line ' . ($n + 1) . ' could not take another word');
            }

            // More than one code page really is in play on some line.
            $this->assertGreaterThan(1, max(array_map('count', $lines)));
        }

        // Explicit line breaks are kept, an empty line included.
        $this->assertSame(['one', '', 'δύο'], array_map([Typesetter::class, 'plain'], Typesetter::lines("one\n\nδύο", 100.0, Fonts::REGULAR, 10.0)));

        // A word wider than the line is broken inside, whatever its script.
        $long = Typesetter::lines(str_repeat('Ж', 80), 30.0, Fonts::REGULAR, 10.0);
        $this->assertGreaterThan(2, count($long));
        $this->assertSame(str_repeat('Ж', 80), implode('', array_map([Typesetter::class, 'plain'], $long)));

        foreach ($long as $line) {
            $this->assertLessThanOrEqual(30.0001, Typesetter::width($line, Fonts::REGULAR, 10.0));
        }

        // Truncation measures mixed runs too, and right-aligned money is measured in its own font.
        $cut = Typesetter::fit('Привет καλημέρα hello Łódź', 30.0, Fonts::REGULAR, 10.0);
        $this->assertLessThanOrEqual(30.0001, Typesetter::width($cut, Fonts::REGULAR, 10.0));
        $this->assertStringEndsWith('...', Typesetter::plain($cut));
        $this->assertStringStartsWith('Привет', Typesetter::plain($cut));
        $this->assertSame('short', Typesetter::plain(Typesetter::fit('short', 30.0, Fonts::REGULAR, 10.0)));
        $this->assertGreaterThan(Typesetter::width(Text::runs('1 234,50 €'), Fonts::REGULAR, 10.0), Typesetter::width(Text::runs('1 234,50 €'), Fonts::BOLD, 10.0));
    }

    public function testADocumentWithGreekAndCyrillicTextEmbedsTheFontsItUses(): void
    {
        $pdf = self::document(['title' => 'Τιμολόγιο — Счёт', 'footer' => 'Σελίδα · Страница', 'pageNumbers' => true])
            ->heading('Τιμολόγιο / Счёт-фактура')
            ->paragraph('β-Mercaptoethanol 99% — 100 mL для Łukasz Żółć.')
            ->keyValues([['title' => 'Προς', 'lead' => 'Ελληνική Εταιρεία ΑΕ', 'rows' => [['ΑΦΜ', '123456789'], ['', 'Москва, Россия']]]])
            ->table(
                [['label' => 'Περιγραφή'], ['label' => 'Сумма', 'width' => 30, 'align' => 'right']],
                [['β-Mercaptoethanol 99% — 100 mL', '45,60 €'], ['Кофе молотый', '9,95 €']]
            )
            ->totals([['Σύνολο', '55,55 €'], ['Итого', '55,55 €', true]])
            ->output();

        $this->assertIsAWellFormedPdf($pdf);

        // Regular text was Western, Central European, Cyrillic and Greek; bold was Greek and Cyrillic.
        foreach (['DejaVuSans-cp1252', 'DejaVuSans-cp1250', 'DejaVuSans-cp1251', 'DejaVuSans-cp1253', 'DejaVuSans-Bold-cp1253', 'DejaVuSans-Bold-cp1251'] as $font) {
            $this->assertStringContainsString("+{$font}\n", $pdf, "{$font} is embedded");
        }

        // A font no text needed is not carried.
        foreach (['DejaVuSans-cp1254', 'DejaVuSans-cp1257', 'DejaVuSans-Bold-cp1257', 'DejaVuSans-Bold-cp1250', 'Helvetica'] as $font) {
            $this->assertStringNotContainsString($font, $pdf, "{$font} is not embedded");
        }

        // The text is drawn in each run's own code page.
        $this->assertNotSame([], self::pagesShowing($pdf, "\xE2-Mercaptoethanol 99% \x97 100 mL"), 'β… in the Greek font');
        $this->assertNotSame([], self::pagesShowing($pdf, "\xCA\xEE\xF4\xE5 \xEC\xEE\xEB\xEE\xF2\xFB\xE9"), 'Кофе молотый in the Cyrillic font');
        $this->assertNotSame([], self::pagesShowing($pdf, "\xA3ukasz \xAF\xF3\xB3\xE6."), 'Łukasz Żółć in the Central European font');

        // A Western-only document carries the Western fonts only.
        $western = self::document()->heading('Tax invoice')->paragraph('Zoë — Café')->output();
        $this->assertStringContainsString("+DejaVuSans-cp1252\n", $western);
        $this->assertStringContainsString("+DejaVuSans-Bold-cp1252\n", $western);
        $this->assertSame(2, preg_match_all('#/BaseFont /\w+\+DejaVuSans#', $western));
    }

    public function testAccentsDashesAndCharactersOutsideTheFontsNeverCorruptTheFile(): void
    {
        $awkward = [
            'Zoë Brontë — Café Français',
            'Łukasz Żółć 日本語 한국어',
            "smile \u{1F600} and \u{202E}reversed",
            'Brackets (round) and a back\\slash and a stray ) or (',
            "bad \xC3\x28 bytes \xFF\xFE",
            "line one\nline two\r\nline three\ttabbed",
            '%PDF-1.3 endobj endstream xref trailer %%EOF',
            '',
        ];

        $document = self::document(['title' => 'Zoë — 日本語 (test)', 'author' => "bad \xC3\x28", 'subject' => "smile \u{1F600}", 'footer' => 'Café — 日本語 )', 'pageNumbers' => true]);

        foreach ($awkward as $text) {
            $document
                ->heading($text, 2)
                ->paragraph($text)
                ->keyValues([['title' => $text, 'lead' => $text, 'rows' => [[$text, $text], ['', $text]]]])
                ->table([['label' => $text], ['label' => 'B', 'width' => 40, 'align' => 'right']], [[$text, $text]])
                ->totals([[$text, $text], [$text, $text, true]]);
        }

        $pdf = $document->output();

        $this->assertIsAWellFormedPdf($pdf);

        // Western text is drawn from the Western font: ë, the em dash and é in cp1252.
        $this->assertNotSame([], self::pagesShowing($pdf, "Zo\xEB Bront\xEB \x97 Caf\xE9 Fran\xE7ais"));
        // Brackets and backslashes are escaped for the PDF, not left to end the string early.
        $this->assertStringContainsString('(Brackets \\(round\\) and a back\\\\slash and a stray \\) or \\()', $pdf);
        // Polish is drawn as itself, from the Central European font; Chinese and Korean are replaced.
        $this->assertMatchesRegularExpression('/\(\xA3ukasz \xAF\xF3\xB3\xE6 [\x20-\x27\x2A-\x7E]+\) Tj/', $pdf);

        // And the same document compresses without complaint.
        $compressed = self::service()->document(['title' => 'Zoë — 日本語']);

        foreach ($awkward as $text) {
            $compressed->paragraph($text)->table([['label' => $text]], [[$text]]);
        }

        $this->assertStringStartsWith('%PDF-', $compressed->output());
    }

    public function testATwoHundredRowTableBreaksPagesRepeatsItsHeaderAndNeverSplitsARow(): void
    {
        $rows = [];

        for ($i = 1; $i <= 200; $i++) {
            // Every ninth row wraps over several lines, each line holding the row's own marker.
            $description = $i % 9 === 0
                ? implode(' ', array_map(static fn (int $n): string => sprintf('r%03dw%02d wrapping words here', $i, $n), range(1, 6)))
                : sprintf('Row %03d', $i);

            $rows[] = [$description, (string) $i, sprintf('%d.%02d', $i, $i % 100)];
        }

        $columns = [['label' => 'Description of the item'], ['label' => 'Quantity', 'width' => 24, 'align' => 'right'], ['label' => 'Amount due', 'width' => 30, 'align' => 'right']];

        $pdf   = self::document(['pageNumbers' => true, 'footer' => 'Two hundred rows'])->heading('A long table')->table($columns, $rows)->output();
        $pages = self::pages($pdf);

        $this->assertIsAWellFormedPdf($pdf);
        $this->assertGreaterThan(3, count($pages), 'two hundred rows need several pages');
        $this->assertSame(count($pages), preg_match_all('#/Type /Page\b(?!s)#', $pdf));

        // The header is on every page, once.
        foreach ($pages as $n => $content) {
            foreach (['Description of the item', 'Quantity', 'Amount due'] as $label) {
                $this->assertSame(1, substr_count($content, "({$label}) Tj"), 'page ' . ($n + 1) . " has the header cell '{$label}' once");
            }

            $this->assertSame(1, substr_count($content, '(Page ' . ($n + 1) . ' of ' . count($pages) . ') Tj'), 'and its page number');
            $this->assertSame(1, substr_count($content, '(Two hundred rows) Tj'), 'and the footer text');
        }

        // Every row is drawn once, and all of a row is on one page.
        $wrapped = 0;

        for ($i = 1; $i <= 200; $i++) {
            if ($i % 9 !== 0) {
                $this->assertCount(1, self::pagesShowing($pdf, sprintf('Row %03d', $i)), "row {$i} is drawn once");

                continue;
            }

            $on = [];

            foreach ($pages as $n => $content) {
                $count = preg_match_all(sprintf('/r%03dw\d\d/', $i), $content);

                if ($count > 0) {
                    $on[$n] = $count;
                }
            }

            $this->assertCount(1, $on, "wrapped row {$i} is on one page only");
            $this->assertSame(6, array_sum($on), "all six parts of wrapped row {$i} are there");

            $content = $pages[array_key_first($on)];
            $this->assertGreaterThan(1, preg_match_all(sprintf('/\([^()]*r%03dw\d\d[^()]*\) Tj/', $i), $content), "row {$i} wrapped onto more than one line");
            $wrapped++;
        }

        $this->assertSame(22, $wrapped);

        // Without the repeat, the header is drawn once.
        $once = self::document()->table($columns, $rows, ['repeatHeader' => false])->output();
        $this->assertGreaterThan(3, count(self::pages($once)));
        $this->assertSame(1, substr_count($once, '(Description of the item) Tj'));
    }

    public function testTotalsStayTogetherOnOnePage(): void
    {
        $totals = [['Subtotal (ex GST)', '1000.00'], ['GST', '100.00'], ['Total (inc GST)', '1100.00', true]];
        $moved  = 0;
        $stayed = 0;

        // Push the totals block down the page one line at a time, past the
        // point where it no longer fits: it moves to the next page whole.
        for ($fill = 40; $fill <= 62; $fill++) {
            $filler = static function (int $fill): Document {
                $document = self::document();

                for ($line = 1; $line <= $fill; $line++) {
                    $document->paragraph("Filler line {$line}");
                }

                return $document;
            };

            $filled = count(self::pages($filler($fill)->output()));
            $pdf    = $filler($fill)->totals($totals)->output();

            $on = [];

            foreach (['Subtotal (ex GST)', '1000.00', 'GST', '100.00', 'Total (inc GST)', '1100.00'] as $text) {
                $pages = self::pagesShowing($pdf, $text);

                $this->assertCount(1, $pages, "'{$text}' is drawn once ({$fill} lines above)");
                $on[] = $pages[0];
            }

            $this->assertCount(1, array_unique($on), "the totals are all on one page ({$fill} lines above)");

            if ($filled === 1 && $on[0] === 1) {
                $moved++;
            } elseif ($on[0] === 0) {
                $stayed++;
            }
        }

        $this->assertGreaterThan(0, $stayed, 'the totals stay on the first page while they fit');
        $this->assertGreaterThan(0, $moved, 'and move to the second page, whole, when they would have straddled the break');
    }

    public function testSideBySideBlocksAndHeadingsAreKeptWhole(): void
    {
        $document = self::document();

        // 52 lines leave too little of the first page for the taller block.
        for ($line = 1; $line <= 52; $line++) {
            $document->paragraph("Filler line {$line}");
        }

        $pdf = $document
            ->keyValues([
                ['title' => 'Seller', 'lead' => 'Seller Pty Ltd', 'rows' => [['', 'S1'], ['', 'S2'], ['', 'S3'], ['', 'S4'], ['', 'S5']]],
                ['title' => 'Buyer', 'lead' => 'Buyer Pty Ltd', 'rows' => [['', 'B1']]],
            ])
            ->output();

        $on = [];

        foreach (['Seller', 'Seller Pty Ltd', 'S1', 'S5', 'Buyer', 'Buyer Pty Ltd', 'B1'] as $text) {
            $on[] = self::pagesShowing($pdf, $text)[0] ?? null;
        }

        $this->assertSame([1], array_values(array_unique($on)), 'both blocks moved to the second page together');

        $this->assertCount(1, self::pagesShowing($pdf, 'Filler line 52'));
        $this->assertSame([0], self::pagesShowing($pdf, 'Filler line 52'), 'the filler itself fitted on the first page');

        // A heading is never the last thing on a page: after 54 lines there
        // is room for the heading alone, but not for it and the next line.
        $document = self::document();

        for ($line = 1; $line <= 54; $line++) {
            $document->paragraph("Filler line {$line}");
        }

        $pdf = $document->heading('Kept with what follows', 2)->paragraph('The line after the heading')->output();
        $this->assertSame([0], self::pagesShowing($pdf, 'Filler line 54'));
        $this->assertSame([1], self::pagesShowing($pdf, 'Kept with what follows'));
        $this->assertSame([1], self::pagesShowing($pdf, 'The line after the heading'));

        $alone = self::document();

        for ($line = 1; $line <= 54; $line++) {
            $alone->paragraph("Filler line {$line}");
        }

        $this->assertCount(1, self::pages($alone->paragraph('One more line fits')->output()), 'there was room for one more line on that page');
    }

    public function testALongParagraphFlowsOverPagesAndLongWordsAreBroken(): void
    {
        $pdf = self::document()->paragraph(implode(' ', array_fill(0, 3000, 'word')))->output();

        $this->assertIsAWellFormedPdf($pdf);
        $this->assertGreaterThan(1, count(self::pages($pdf)));

        // A word wider than the line is broken, not drawn off the page.
        $pdf = self::document()->paragraph(str_repeat('X', 400))->table([['label' => 'Narrow', 'width' => 20], ['label' => 'Rest']], [[str_repeat('W', 60), 'ok']])->output();

        $this->assertIsAWellFormedPdf($pdf);
        preg_match_all('/\((X+)\) Tj/', $pdf, $m);
        $this->assertGreaterThan(2, count($m[1]));
        $this->assertSame(400, array_sum(array_map('strlen', $m[1])), 'every character of the long word is drawn');

        // A cell with more text than a page can hold is cut to one page and says so.
        $pdf = self::document()->table([['label' => 'Huge']], [[implode("\n", array_fill(0, 500, 'line'))], ['after']])->output();
        $this->assertIsAWellFormedPdf($pdf);
        $this->assertNotSame([], self::pagesShowing($pdf, '...'));
        $this->assertNotSame([], self::pagesShowing($pdf, 'after'));
    }

    public function testPageSizeOrientationMarginsAndFontSize(): void
    {
        $a4 = self::document()->paragraph('x')->output();
        $this->assertStringContainsString('/MediaBox [0 0 595.28 841.89]', $a4, 'A4 portrait by default');

        $landscape = self::document(['orientation' => 'landscape', 'size' => 'a4', 'margins' => 10, 'fontSize' => 12])->paragraph('x')->output();
        $this->assertStringContainsString('/MediaBox [0 0 841.89 595.28]', $landscape);

        $letter = self::document(['size' => 'Letter', 'margins' => ['top' => 30, 'left' => 25]])->paragraph('x')->output();
        $this->assertStringContainsString('/MediaBox [0 0 612.00 792.00]', $letter);
    }

    public function testBadInputIsRefusedWithAPdfException(): void
    {
        $refused = function (callable $call, string $expect): void {
            try {
                $call();
                $this->fail("expected a PdfException mentioning '{$expect}'");
            } catch (PdfException $e) {
                $this->assertStringContainsString($expect, $e->getMessage());
            }
        };

        $service = self::service();

        $refused(fn () => $service->document(['colour' => 'red']), 'Unknown document option: colour');
        $refused(fn () => $service->document(['size' => 'B5']), 'Unknown page size');
        $refused(fn () => $service->document(['orientation' => 'sideways']), 'Unknown orientation');
        $refused(fn () => $service->document(['fontSize' => 40]), 'fontSize');
        $refused(fn () => $service->document(['margins' => 150]), 'margin');
        $refused(fn () => $service->document(['margins' => ['inner' => 10]]), 'Unknown margins option');
        $refused(fn () => $service->document(['title' => ['an array']]), 'title must be a string');

        $refused(fn () => self::document()->heading('x', 4), 'heading level');
        $refused(fn () => self::document()->heading('x', 1, ['colour' => 'red']), 'Unknown heading option');
        $refused(fn () => self::document()->paragraph('x', ['align' => 'justify']), 'Unknown align');
        $refused(fn () => self::document()->paragraph('x', ['style' => 'underline']), 'Unknown style');
        $refused(fn () => self::document()->paragraph('x', ['style' => 'italic']), 'Unknown style');
        $refused(fn () => self::document()->paragraph('x', ['size' => 'huge']), 'Unknown size');
        $refused(fn () => self::document()->keyValues([['rows' => [['only one']]]]), 'must be [label, value]');
        $refused(fn () => self::document()->keyValues([['heading' => 'x']]), 'Unknown keyValues block option');
        $refused(fn () => self::document()->keyValues([[]], ['layout' => 'grid']), 'Unknown layout');
        $refused(fn () => self::document()->table([], []), 'at least one column');
        $refused(fn () => self::document()->table([['label' => 'A'], ['label' => 'B']], [['one']]), 'Table row 1 must have 2 values');
        $refused(fn () => self::document()->table([['label' => 'A', 'colour' => 'red']], []), 'Unknown table column option');
        $refused(fn () => self::document()->table([['label' => 'A', 'width' => -5]], []), 'width must be a positive number');
        $refused(fn () => self::document()->table([['label' => 'A']], [[['nested']]]), 'must be text or a number');
        $refused(fn () => self::document()->totals([['label only']]), 'Totals row 1');
        $refused(fn () => self::document()->totals([], ['width' => 5]), 'totals width');
        $refused(fn () => self::document()->spacer(-1), 'spacer');

        // A table with no rows is just its header; fixed widths wider than the page are scaled to fit.
        $pdf = self::document()->table([['label' => 'Wide A', 'width' => 300], ['label' => 'Wide B', 'width' => 300]], [])->output();
        $this->assertIsAWellFormedPdf($pdf);
        $this->assertNotSame([], self::pagesShowing($pdf, 'Wide B'));
    }

    public function testAFinishedDocumentCannotBeUsedAgain(): void
    {
        $document = self::document()->paragraph('once');
        $this->assertStringStartsWith('%PDF-', $document->output());

        foreach ([
            fn () => $document->output(),
            fn () => $document->paragraph('again'),
            fn () => $document->heading('again'),
            fn () => $document->spacer(),
        ] as $call) {
            try {
                $call();
                $this->fail('a finished document must refuse further calls');
            } catch (PdfException $e) {
                $this->assertStringContainsString('already been output', $e->getMessage());
            }
        }

        // Each document is independent of the others.
        $service = self::service();
        $first   = $service->document(['compress' => false])->paragraph('first only');
        $second  = $service->document(['compress' => false])->paragraph('second only');

        $this->assertSame([], self::pagesShowing($second->output(), 'first only'));
        $this->assertNotSame([], self::pagesShowing($first->output(), 'first only'));
    }

    /** Callers get bytes and a builder; the PDF library never leaves App_skeleton\Pdf. */
    public function testTheLibraryIsNotExposed(): void
    {
        foreach ([Pdf::class, Document::class] as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertFalse($reflection->isSubclassOf(\FPDF::class), "{$class} is not an FPDF");
            $this->assertSame([], $reflection->getProperties(ReflectionProperty::IS_PUBLIC), "{$class} has no public property");

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $types = (string) $method->getReturnType() . ' ' . implode(' ', array_map(static fn (ReflectionParameter $p): string => (string) $p->getType(), $method->getParameters()));

                $this->assertStringNotContainsStringIgnoringCase('fpdf', $types, "{$class}::{$method->getName()}()");
                $this->assertStringNotContainsString('Sheet', $types, "{$class}::{$method->getName()}()");
            }
        }

        $names = static fn (string $class): array => array_values(array_filter(
            array_map(static fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC)),
            static fn (string $name): bool => $name !== '__construct'
        ));

        $this->assertEqualsCanonicalizing(['document', 'supports', 'interfaceVersion'], $names(Pdf::class), 'the service of interface 1.0');
        $this->assertEqualsCanonicalizing(['heading', 'paragraph', 'keyValues', 'table', 'totals', 'spacer', 'output'], $names(Document::class), 'the builder of interface 1.0');
    }
}
