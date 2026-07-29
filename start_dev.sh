#! /bin/zsh
# -d max_execution_time is required here, not just in php.ini: the CLI SAPI
# (which `php -S` uses) silently resets max_execution_time to 0 at startup
# regardless of what php.ini says. 0 is also specifically the value that
# does NOT work around the Apple Silicon ITIMER_PROF bug (php-src#12814) --
# an explicit huge-but-finite value is what actually routes around it.
php_opts=(-d max_execution_time=99999999)
workers=""

for arg in "$@"; do
    case "$arg" in
        --trace)
            echo "Xdebug tracing armed for every request -> logs/*.xt"
            php_opts+=(-d xdebug.start_with_request=yes)
            ;;
        --workers)
            workers=4
            ;;
        --workers=*)
            workers="${arg#--workers=}"
            ;;
    esac
done

if [[ -n "$workers" ]]; then
    echo "Running with $workers worker processes (PHP_CLI_SERVER_WORKERS=$workers) -- one crashing won't take down the rest"
fi

if [[ -n "$workers" ]]; then
    PHP_CLI_SERVER_WORKERS=$workers php "${php_opts[@]}" -S localhost:8080 -t public bin/dev-router.php
else
    php "${php_opts[@]}" -S localhost:8080 -t public bin/dev-router.php
fi
