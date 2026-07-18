<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class SystemLogController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    private const MAX_BYTES = 200000;

    public function indexAction()
    {
        $path = BASE_PATH . '/logs/app.log';

        if (!is_file($path)) {
            $this->view->contents = '';
            $this->view->truncated = false;

            return;
        }

        $size = filesize($path);
        $handle = fopen($path, 'rb');

        if ($size > self::MAX_BYTES) {
            fseek($handle, -self::MAX_BYTES, SEEK_END);
            $this->view->truncated = true;
        } else {
            $this->view->truncated = false;
        }

        $contents = stream_get_contents($handle);
        fclose($handle);

        // Natural file order (oldest at top, newest at bottom), like `tail`.
        // PHP's error_log() writes one timestamp per call and embeds a
        // multi-line trace under it, so this doesn't try to parse entry
        // boundaries — it's just the raw tail of the file.
        $this->view->contents = $contents;
    }
}
