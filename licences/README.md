# Third-party dependencies

This project depends on the following third-party libraries at runtime:

| Library | License | Notes |
|---------|---------|-------|
| wikimedia/composer-merge-plugin | MIT | Ships its own LICENSE, not duplicated here |
| setasign/fpdf (FPDF 1.82, by Olivier Plathey) | FPDF's own short permissive licence; Packagist declares it MIT | Behind the `pdf` service (`App_skeleton\Pdf`). Its licence text is copied unchanged from the package's `license.txt` into [FPDF.txt](FPDF.txt) |
| DejaVu Sans and DejaVu Sans Bold 2.37 (dejavu-fonts project) | Bitstream Vera Fonts licence, with the Arev Fonts licence for some glyphs; DejaVu's own changes are public domain. Permissive; not MIT | The typeface the `pdf` service embeds in the documents it makes. The fonts are **in this repository**, cut down per code page, in `app/common/library/Pdf/fonts/` (made by `bin/make-pdf-fonts.php`). The licence text is copied unchanged from the release's `LICENSE` file into [DejaVu-Fonts.txt](DejaVu-Fonts.txt); it must stay with any copy of the fonts, and modified fonts must not use the names "Bitstream" or "Vera" |

Composer-installed libraries keep their licences in their package directories within `vendor/`. FPDF's and the fonts' licence texts are also kept here because the fonts are shipped in this repository and FPDF's licence is not one of the standard texts.
