<?php

declare(strict_types=1);

/**
 * Regenerates the font files the `pdf` service embeds
 * (app/common/library/Pdf/fonts/), from the DejaVu Sans release they were
 * made from. They are committed, so this is only needed to add a code
 * page, or to prove the committed files are what this script produces.
 *
 *   1. Download the official DejaVu fonts 2.37 release and unpack it:
 *      https://github.com/dejavu-fonts/dejavu-fonts/releases/download/version_2_37/dejavu-fonts-ttf-2.37.tar.bz2
 *   2. composer install   (FPDF's makefont tool is in vendor/setasign/fpdf)
 *   3. php bin/make-pdf-fonts.php /path/to/dejavu-fonts-ttf-2.37/ttf
 *
 * For each weight (regular, bold) and each code page below it runs
 * FPDF's own makefont, which writes a definition file (.php: widths,
 * encoding, Unicode map) and the font program cut down to the glyphs of
 * that code page and compressed (.z). FPDF's fonts are single-byte, so
 * one file pair covers one code page; the service picks the page per run
 * of text (App_skeleton\Pdf\Text::runs()).
 *
 * The only change made to makefont's output is the font's name in the
 * definition file, which gets the code page appended
 * ("DejaVuSans-cp1251"), so the subsets embedded in one PDF have
 * different names.
 *
 * makefont as shipped in FPDF 1.8.2 predates PHP 8: its TrueType parser
 * counts a list it has not initialised yet, which PHP 8 refuses. This
 * script therefore runs a temporary copy of the tool with that one
 * property initialised (see MAKEFONT_FIX below); vendor/ is not touched.
 *
 * The .z files are zlib streams: a different zlib version may compress
 * to different bytes with the same content.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

const CODE_PAGES = ['cp1252', 'cp1250', 'cp1251', 'cp1253', 'cp1254', 'cp1257'];

// DejaVu fonts 2.37, as released.
const SOURCES = [
    'DejaVuSans.ttf'      => ['sha256' => '7da195a74c55bef988d0d48f9508bd5d849425c1770dba5d7bfc6ce9ed848954', 'output' => 'DejaVuSans'],
    'DejaVuSans-Bold.ttf' => ['sha256' => 'e6476c1b80502924294eed40894c5b18e06c181444ca953e5334262df9c27724', 'output' => 'DejaVuSans-Bold'],
];

// In ttfparser.php's Subset(): start the list AddGlyph() counts.
const MAKEFONT_FIX = [
    "\t\t\$this->AddGlyph(0);\n\t\t\$this->subsettedChars = array();",
    "\t\t\$this->subsettedGlyphs = array();\n\t\t\$this->AddGlyph(0);\n\t\t\$this->subsettedChars = array();",
];

$root     = dirname(__DIR__);
$makefont = $root . '/vendor/setasign/fpdf/makefont/makefont.php';
$target   = $root . '/app/common/library/Pdf/fonts';
$source   = rtrim((string) ($argv[1] ?? ''), '/');

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

if ($source === '' || !is_dir($source)) {
    $fail('Usage: php bin/make-pdf-fonts.php /path/to/dejavu-fonts-ttf-2.37/ttf');
}

if (!is_file($makefont)) {
    $fail('FPDF is not installed: run composer install first.');
}

if (!function_exists('gzcompress')) {
    $fail('The zlib extension is needed to compress the font files.');
}

$work = sys_get_temp_dir() . '/make-pdf-fonts-' . bin2hex(random_bytes(4));
mkdir($work);
mkdir($work . '/makefont');

// The temporary copy of the tool (its code page maps sit beside it).
foreach (glob(dirname($makefont) . '/*') ?: [] as $file) {
    copy($file, $work . '/makefont/' . basename($file));
}

$parser = (string) file_get_contents($work . '/makefont/ttfparser.php');

if (substr_count($parser, MAKEFONT_FIX[0]) === 1) {
    file_put_contents($work . '/makefont/ttfparser.php', str_replace(MAKEFONT_FIX[0], MAKEFONT_FIX[1], $parser));
} elseif (!str_contains($parser, '$this->subsettedGlyphs = array();')) {
    $fail('ttfparser.php is not the one from FPDF 1.8.2: check whether MAKEFONT_FIX is still needed.');
}

$makefont = $work . '/makefont/makefont.php';

foreach (SOURCES as $ttf => $font) {
    $path = $source . '/' . $ttf;

    if (!is_file($path)) {
        $fail("{$ttf} is not in {$source}");
    }

    if (hash_file('sha256', $path) !== $font['sha256']) {
        $fail("{$ttf} is not the file from DejaVu fonts 2.37 (its SHA-256 differs).");
    }

    foreach (CODE_PAGES as $codePage) {
        $name = $font['output'] . '-' . $codePage;

        copy($path, "{$work}/{$name}.ttf");

        // makefont writes into the working directory. Embedded, and cut down to the code page's glyphs.
        $output = [];
        $status = 0;
        exec('cd ' . escapeshellarg($work) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($makefont) . ' ' . escapeshellarg("{$name}.ttf") . ' ' . escapeshellarg($codePage) . ' true true 2>&1', $output, $status);

        foreach ($output as $line) {
            // Its progress lines are not worth repeating; a warning (a glyph the font lacks) is.
            if (stripos($line, 'warning') !== false || stripos($line, 'error') !== false || stripos($line, 'notice') !== false) {
                echo "  {$name}: {$line}\n";
            }
        }

        if ($status !== 0 || !is_file("{$work}/{$name}.php") || !is_file("{$work}/{$name}.z")) {
            $fail("makefont failed for {$name}:\n" . implode("\n", $output));
        }

        $definition = (string) file_get_contents("{$work}/{$name}.php");
        $renamed    = preg_replace('/^\$name = \'[^\']+\';$/m', "\$name = '{$name}';", $definition, 1, $count);

        if ($count !== 1) {
            $fail("Unexpected definition file for {$name}: no \$name line.");
        }

        file_put_contents("{$target}/{$name}.php", $renamed);
        copy("{$work}/{$name}.z", "{$target}/{$name}.z");

        printf("%-28s %7d bytes\n", "{$name}.z", filesize("{$target}/{$name}.z"));
    }
}

array_map('unlink', array_filter(array_merge(glob($work . '/makefont/*') ?: [], glob($work . '/*') ?: []), 'is_file'));
rmdir($work . '/makefont');
rmdir($work);

echo 'Wrote ' . count(SOURCES) * count(CODE_PAGES) . " fonts to app/common/library/Pdf/fonts/\n";
