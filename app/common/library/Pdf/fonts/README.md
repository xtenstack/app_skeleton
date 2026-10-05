# Fonts embedded by the `pdf` service

Generated files. Do not edit them by hand.

These are DejaVu Sans and DejaVu Sans Bold, release 2.37, cut into one
single-byte font per Windows code page, because FPDF's fonts hold 256
characters each:

| Code page | Script |
|---|---|
| cp1252 | Western European |
| cp1250 | Central European |
| cp1251 | Cyrillic |
| cp1253 | Greek |
| cp1254 | Turkish |
| cp1257 | Baltic |

For each there is a regular and a bold font, and each font is two files:
`<name>.php` (FPDF's definition file: metrics, encoding and the map to
Unicode) and `<name>.z` (the font program, holding only that code page's
glyphs, zlib-compressed). `App_skeleton\Pdf\Fonts` reads the definition
files; `App_skeleton\Pdf\Sheet` points FPDF's font path here and embeds a
font in a document the first time a run of text needs it.

## Regenerating them

```bash
# 1. the official release
curl -LO https://github.com/dejavu-fonts/dejavu-fonts/releases/download/version_2_37/dejavu-fonts-ttf-2.37.tar.bz2
tar xjf dejavu-fonts-ttf-2.37.tar.bz2

# 2. from the app_skeleton root, with vendor/ installed
php bin/make-pdf-fonts.php dejavu-fonts-ttf-2.37/ttf
```

The script checks the two `.ttf` files against the SHA-256 of the 2.37
release, runs FPDF's own `makefont` (from `vendor/setasign/fpdf`) for
each weight and code page with embedding and subsetting on, and appends
the code page to the font name in each definition file
(`DejaVuSans-cp1251`). It needs PHP's zlib. To add a code page, add it to
`CODE_PAGES` in the script and to `Fonts::CODE_PAGES`; makefont has maps
for the code pages listed in `vendor/setasign/fpdf/makefont/`.

## Licence

DejaVu fonts are under the Bitstream Vera Fonts licence (and the Arev
Fonts licence for some glyphs); DejaVu's own changes are in the public
domain. The full text is in `licences/DejaVu-Fonts.txt` at the root of
this repository and must stay with any copy of these files.
