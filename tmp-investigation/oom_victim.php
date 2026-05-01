<?php
// Victim process: this is the script that mimics phpMyAdmin's
// `Session.php:204` — a plain `session_start()` against a session file whose
// unserialize cost exceeds memory_limit.
//
// We register Reli's MemoryLimitHandler *before* the offending call so that
// when the fatal hits, the shutdown handler fires a sidecar dump request.

require __DIR__ . '/../vendor/autoload.php';

// Make the OOM cheap to reproduce: 64 MiB.
ini_set('memory_limit', '64M');

// Sidecar socket path is passed in via env (set by the runner script).
$socket = getenv('RELI_SIDECAR_SOCKET') ?: null;

\Reli\Sidecar\Client\MemoryLimitHandler::register(
    socket_path: $socket,
    on_response: function (\Reli\Sidecar\Client\SidecarClientResponse $r) {
        error_log(sprintf(
            "[victim %d] reli sidecar dump: %s (%d bytes)",
            getmypid(), $r->path ?? '<no path>', $r->bytes ?? -1
        ));
    },
    on_error: function (string $msg) {
        error_log("[victim] sidecar error: {$msg}");
    },
    timeout_seconds: 15,
);

// Debug: see what error_get_last looks like at shutdown.
register_shutdown_function(static function () {
    $e = error_get_last();
    error_log('[shutdown] error_get_last: ' . var_export($e, true));
});

// Trap warnings that fire during shutdown so we can see whether they pass
// through set_error_handler (and therefore can be filtered before they
// shadow error_get_last).
set_error_handler(static function (int $errno, string $msg, ?string $file, ?int $line): bool {
    error_log("[error_handler] errno={$errno} msg={$msg}");
    if (str_contains($msg, 'Failed to decode session object')) {
        // Pretend we handled it so the engine doesn't push it onto
        // error_get_last and shadow the original OOM.
        return true;
    }
    return false;
});

session_save_path(__DIR__ . '/sessions');
session_id('oomrepro1234');
ini_set('session.serialize_handler', 'php_serialize');
ini_set('session.use_cookies',       '0');
ini_set('session.use_only_cookies',  '0');
ini_set('session.use_strict_mode',   '0');

fwrite(STDERR, "[victim {$argv[0]} pid=" . getmypid() . "] calling session_start()\n");

// This is the analogue of phpMyAdmin's Session.php:204
session_start();

// If we ever get here, the session was small enough to fit. Print something
// so the test fails loudly instead of silently passing.
fwrite(STDERR, "[victim] session_start returned — payload fit in memory_limit, increase the seeder\n");
exit(0);
