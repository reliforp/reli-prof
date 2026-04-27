<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

assert(PHP_ZTS);
assert(extension_loaded('parallel'));

$host_pid = getmypid();
fwrite(STDOUT, "host_pid={$host_pid}\n");

$rt = new parallel\Runtime();
$future = $rt->run(function (): array {
    return ['pid' => getmypid(), 'zts' => PHP_ZTS];
});
$worker = $future->value();

fwrite(STDOUT, sprintf(
    "worker_pid=%d worker_zts=%s same_pid=%s\n",
    $worker['pid'],
    var_export($worker['zts'], true),
    var_export($host_pid === $worker['pid'], true),
));
