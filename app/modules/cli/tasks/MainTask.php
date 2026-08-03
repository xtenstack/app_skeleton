<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

/**
 * Usage: ./run
 *
 * With no task given, lists every registered task's Usage line — read
 * straight from each task class's own docblock rather than a hand-kept
 * list, so a new *Task.php file shows up here automatically as long as it
 * documents itself the same way the existing ones do.
 */
class MainTask extends \Phalcon\Cli\Task
{
    public function mainAction()
    {
        echo 'Usage: ./run <task> <action> [params...]' . PHP_EOL . PHP_EOL;
        echo 'Available tasks:' . PHP_EOL . PHP_EOL;

        $files = glob(__DIR__ . '/*Task.php');
        sort($files);

        foreach ($files as $file) {
            $className = __NAMESPACE__ . '\\' . basename($file, '.php');

            if ($className === self::class || !class_exists($className)) {
                continue;
            }

            $docComment = (new \ReflectionClass($className))->getDocComment();

            if ($docComment === false) {
                continue;
            }

            foreach (explode("\n", $docComment) as $line) {
                $line = trim($line, " \t*/");

                if (stripos($line, 'Usage:') === 0) {
                    $line = trim(substr($line, strlen('Usage:')));
                }

                if (str_starts_with($line, './run ')) {
                    echo '  ' . $line . PHP_EOL;
                }
            }
        }
    }
}
