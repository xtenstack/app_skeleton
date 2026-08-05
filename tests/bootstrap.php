<?php
declare(strict_types=1);

/**
 * Deliberately thin — most of this project's own boot sequence
 * (app/bootstrap_web.php) is request-handling glue (superglobals, an
 * exception handler that emits an HTTP response, echo'ing content) that
 * tests have no use for and every test file needs a different slice of
 * anyway: tests/Unit/SoftDeleteTest.php only needs 'db' + model
 * autoloading, tests/Feature/*Test.php don't touch Phalcon's DI at all
 * (they're real HTTP requests against the actually-running stack, same
 * philosophy as this project's Docker Compose smoke test in
 * .github/workflows/build.yml — see docs/CONTRIBUTING.md's Testing
 * section for why "real, not mocked" is the rule here, not the
 * exception). This file just gets autoloading + path constants ready.
 */
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

require BASE_PATH . '/vendor/autoload.php';

// Not PSR-4-autoloaded (no composer autoload-dev mapping for tests/) —
// a plain require is simpler than adding one just for a single helper
// class shared by the Feature suite.
require __DIR__ . '/Feature/HttpClient.php';
