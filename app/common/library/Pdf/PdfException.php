<?php
declare(strict_types=1);

namespace App_skeleton\Pdf;

/**
 * The one exception that leaves the `pdf` service and its documents: a
 * bad option or argument, a call on a document that has already been
 * output, or a failure inside the PDF library (then getPrevious() is the
 * library's own exception).
 */
class PdfException extends \RuntimeException
{
}
