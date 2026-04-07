<?php
/**
 * Case 1: FrankenPHP Worker Mode Memory Leak Simulation
 * Ref: php/frankenphp#1797
 *
 * Simulates memory accumulation between "requests" in a worker loop,
 * where exception handling and debug objects are not properly released.
 */

class RequestContext {
    public string $data;
    public array $debugInfo = [];
    public ?self $previous = null;
}

class VarCloner {
    private array $clonedData = [];

    public function cloneVar(mixed $var): array {
        // Simulate Symfony VarCloner deep-cloning behavior
        $result = [];
        for ($i = 0; $i < 100; $i++) {
            $result[] = str_repeat(serialize($var), 10);
        }
        $this->clonedData[] = $result;
        return $result;
    }
}

class ProfilerStorage {
    private array $profiles = [];

    public function store(array $profile): void {
        $this->profiles[] = $profile;
    }
}

// Simulate worker mode: persistent objects across "requests"
$cloner = new VarCloner();
$storage = new ProfilerStorage();
$previousCtx = null;

// Signal readiness then pause for profiling
posix_kill(posix_getpid(), SIGSTOP);

for ($request = 0; $request < 200; $request++) {
    $ctx = new RequestContext();
    $ctx->data = str_repeat("request_payload_{$request}_", 1000);
    $ctx->previous = $previousCtx; // Linked list leak

    // Simulate dump() creating deep clones
    $debugData = $cloner->cloneVar($ctx);
    $ctx->debugInfo = $debugData;

    // Simulate profiler storing request data
    $storage->store([
        'request' => $request,
        'memory' => memory_get_usage(true),
        'data' => substr($ctx->data, 0, 500),
    ]);

    $previousCtx = $ctx;
}

echo "Final memory: " . number_format(memory_get_usage(true)) . " bytes\n";
