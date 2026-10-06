<?php
declare(strict_types=1);

use Phalcon\Events\Manager as EventsManager;
use Phalcon\Html\Escaper;
use Phalcon\Flash\Session as Flash;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\Router;
use Phalcon\Mvc\Url as UrlResolver;

/**
 * Registering a router. Defaults (module/namespace/controller/action) only
 * ever apply when NO route pattern matches at all (Phalcon's own fallback
 * behaviour) — every real route below is matched explicitly regardless.
 * Pointed at backend/index/notFound rather than left at Phalcon's implicit
 * index/index default so a mistyped URL renders an actual 404 instead of
 * silently succeeding (or, as it did until this change with the default
 * module set to 'api', hitting the API module's auth gate and returning a
 * raw 401 JSON body — see REQ-024).
 */
$di->setShared('router', function () {
    $router = new Router();
    $router->setDefaultModule('backend');
    $router->setDefaultNamespace('App_skeleton\Modules\Backend\Controllers');
    $router->setDefaultController('index');
    $router->setDefaultAction('notFound');

    return $router;
});

/**
 * The URL component is used to generate all kinds of URLs in the application
 */
$di->setShared('url', function () {
    $config = $this->getConfig();

    $url = new UrlResolver();
    $url->setBaseUri($config->application->baseUri);

    return $url;
});

/**
 * Register the session flash service with the Twitter Bootstrap classes
 */
$di->setShared('flash', function () {
    $escaper = new Escaper();
    $flash = new Flash($escaper);
    $flash->setImplicitFlush(false);
    $flash->setCssClasses([
        'error'   => 'alert alert-danger',
        'success' => 'alert alert-success',
        'notice'  => 'alert alert-info',
        'warning' => 'alert alert-warning',
    ]);

    return $flash;
});

/**
 * Set the default namespace for dispatcher, and give every module a
 * uniform 404/500 fallback. Phalcon's Dispatcher fires
 * 'dispatch:beforeException' for both routing failures (unknown
 * controller/action — Dispatcher\Exception) and any Throwable an action
 * itself lets escape, so one listener covers both "page doesn't exist" and
 * "page blew up" without each module having to handle it separately. Each
 * built-in module's own IndexController::notFoundAction()/serverErrorAction()
 * renders the response appropriately for that module (HTML for backend,
 * JSON for api) since forward() re-dispatches within the current module by
 * default. The bootstrap_web.php top-level try/catch remains the true
 * last-resort for anything that happens outside a dispatch cycle entirely
 * (or a forward loop here failing) — this listener is the common case.
 */
$di->setShared('dispatcher', function () {
    $dispatcher = new Dispatcher();
    $dispatcher->setDefaultNamespace('App_skeleton\Modules\Backend\Controllers');

    $eventsManager = new EventsManager();
    // The URL left out a parameter the action needs (/backend/users/edit
    // with no id). Left alone, Phalcon calls editAction() with nothing to
    // pass and PHP throws ArgumentCountError, an \Error, which Phalcon's
    // dispatcher doesn't hand to beforeException below: it escapes to the
    // bootstrap's last-resort 500. A URL that is missing its id is a page
    // that doesn't exist, so it is answered like any other unknown page,
    // for every module. This runs after the controller is constructed, so
    // the controller's own login/role gate still decides first. Decided
    // from the action's signature before it runs, so an ArgumentCountError
    // raised deeper inside an action's own code is still a logged 500.
    $eventsManager->attach('dispatch:beforeExecuteRoute', function ($event, $dispatcher) {
        try {
            $required = (new \ReflectionMethod($dispatcher->getActiveController(), $dispatcher->getActiveMethod()))
                ->getNumberOfRequiredParameters();
        } catch (\ReflectionException $e) {
            return true;
        }

        if ($required > count($dispatcher->getParams())) {
            $dispatcher->forward(['controller' => 'index', 'action' => 'notFound']);

            return false;
        }

        return true;
    });
    $eventsManager->attach('dispatch:beforeException', function ($event, $dispatcher, $exception) {
        $alreadyOnErrorPage = $dispatcher->getControllerName() === 'index'
            && in_array($dispatcher->getActionName(), ['notFound', 'serverError'], true);

        if ($alreadyOnErrorPage) {
            return true;
        }

        if ($exception instanceof \Phalcon\Mvc\Dispatcher\Exception
            && in_array($exception->getCode(), [
                \Phalcon\Mvc\Dispatcher\Exception::EXCEPTION_HANDLER_NOT_FOUND,
                \Phalcon\Mvc\Dispatcher\Exception::EXCEPTION_ACTION_NOT_FOUND,
            ], true)
        ) {
            $dispatcher->forward(['controller' => 'index', 'action' => 'notFound']);

            return false;
        }

        error_log(sprintf('%s: %s in %s:%d', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()) . "\n" . $exception->getTraceAsString());

        // Structured copy for the browsable Error Log admin view (project
        // audit, Tier 3 — lightweight self-hosted monitoring rather than an
        // external service). Wrapped in its own try/catch deliberately: if
        // the DB itself is what's unhealthy, logging the error must never
        // become a second, worse crash — the plain error_log() call above
        // already covers that scenario regardless of whether this succeeds.
        try {
            $entry                 = new \ErrorLog();
            $entry->exception_class = get_class($exception);
            $entry->message         = $exception->getMessage();
            $entry->file            = $exception->getFile();
            $entry->line            = $exception->getLine();
            $entry->trace           = $exception->getTraceAsString();
            $entry->request_method  = $_SERVER['REQUEST_METHOD'] ?? null;
            $entry->request_uri     = $_SERVER['REQUEST_URI'] ?? null;
            $entry->user_id         = $dispatcher->getDI()->getShared('currentPrincipal')->userId();
            $entry->save();
        } catch (\Throwable $loggingFailure) {
            error_log('ErrorLog write failed: ' . $loggingFailure->getMessage());
        }

        $dispatcher->forward(['controller' => 'index', 'action' => 'serverError']);

        return false;
    });
    $dispatcher->setEventsManager($eventsManager);

    return $dispatcher;
});
