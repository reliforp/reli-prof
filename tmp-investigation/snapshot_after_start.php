<?php
// Variant 2 — bump memory_limit so session_start() succeeds, then take a
// labelled snapshot. This is the "diagnostic mode" you'd ship to production
// for phpMyAdmin issue 18455: the dump pinpoints which $_SESSION key holds
// the bloat while every zval is still reachable from EG.
//
// In the broken (64M) case the OOM-time dump shows 59 MB of the heap as
// "unaccounted" because session module RSHUTDOWN already detached the partial
// unserialize result before MemoryLimitHandler runs.

require __DIR__ . '/../vendor/autoload.php';

ini_set('memory_limit', '256M');

session_save_path(__DIR__ . '/sessions');
session_id('oomrepro1234');
ini_set('session.serialize_handler', 'php_serialize');
ini_set('session.use_cookies',       '0');
ini_set('session.use_only_cookies',  '0');
ini_set('session.use_strict_mode',   '0');

$client = new \Reli\Sidecar\Client\SidecarClient(
    socket_path: getenv('RELI_SIDECAR_SOCKET') ?: null,
);

$before = $client->snapshot('before-session-start');
fwrite(STDERR, "[v2] before snapshot: " . ($before?->path ?? 'failed') . "\n");

session_start();
fwrite(STDERR, "[v2] session_start OK, peak=" . (memory_get_peak_usage(true) >> 20) . " MiB\n");

$after = $client->snapshot('after-session-start');
fwrite(STDERR, "[v2] after snapshot: " . ($after?->path ?? 'failed') . "\n");
