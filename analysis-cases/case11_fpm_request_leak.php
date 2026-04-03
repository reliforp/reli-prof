<?php
/**
 * Case 11: PHP-FPM Request-Level Memory Not Released
 * Ref: php/php-src#13775
 * Simulates FPM worker handling many requests where per-request allocations persist.
 */
class SessionStore {
    private static array $sessions = []; // Static: persists across "requests"
    public static function set(string $id, array $data): void {
        self::$sessions[$id] = $data;
    }
    public static function count(): int { return count(self::$sessions); }
}

class RequestHandler {
    private array $middleware = [];

    public function addMiddleware(callable $fn): void { $this->middleware[] = $fn; }

    public function handle(array $request): array {
        $response = $request;
        foreach ($this->middleware as $mw) {
            $response = $mw($response);
        }
        return $response;
    }
}

function simulateRequest(int $requestId): array {
    return [
        'id' => $requestId,
        'uri' => "/api/v1/resource/{$requestId}",
        'body' => str_repeat("payload_", 100),
        'headers' => ['Content-Type' => 'application/json', 'X-Request-Id' => uniqid()],
        'session' => ['user_id' => $requestId % 100, 'token' => bin2hex(random_bytes(16))],
    ];
}

$handler = new RequestHandler();
$handler->addMiddleware(function (array $req) {
    // Session middleware: stores session data (leak)
    SessionStore::set($req['headers']['X-Request-Id'], $req['session']);
    return $req;
});
$handler->addMiddleware(function (array $req) {
    // Logging middleware: accumulates
    $req['_log'] = ['processed_at' => microtime(true), 'memory' => memory_get_usage()];
    return $req;
});

posix_kill(posix_getpid(), SIGSTOP);

for ($i = 0; $i < 5000; $i++) {
    $request = simulateRequest($i);
    $handler->handle($request);
}
echo "Sessions: " . SessionStore::count() . "\n";
echo "Memory: " . number_format(memory_get_usage(true)) . "\n";
sleep(300);
