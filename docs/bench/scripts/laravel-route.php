<?php
// Real-world-ish benchmark: bootstrap a Laravel skeleton and
// dispatch the welcome route N times via the HTTP kernel. Doesn't
// spin up a real HTTP server — calls the framework directly. Each
// iteration goes through routing, middleware, view rendering.
//
// Usage: php laravel-route.php <iters> [laravel-app-dir]

$iters = (int)($argv[1] ?? 50);
$app_dir = $argv[2] ?? '/tmp/bench-laravel';

if (!is_dir($app_dir)) {
    fprintf(STDERR, "no Laravel app at %s\n", $app_dir);
    exit(1);
}

require $app_dir . '/vendor/autoload.php';
$app = require $app_dir . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$t = microtime(true);
for ($i = 0; $i < $iters; $i++) {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
}
$elapsed = microtime(true) - $t;
fprintf(STDERR, "laravel-route iters=%d in %.3fs\n", $iters, $elapsed);
