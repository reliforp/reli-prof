<?php
// Reproduces CuyZ/Valinor#800 shape:
// static cache keyed by spl_object_hash($closure) grows unboundedly because
// the closures live as keys (string) but reflection objects accumulate.
// This stresses object-graph tables: many distinct objects, all reachable
// from one root array.

class ReflectionStub {
    public string $signature;
    public array $params;
    public ?ReflectionStub $next = null;
    public function __construct(string $sig, array $params) {
        $this->signature = $sig;
        $this->params = $params;
    }
}

$count = (int)($argv[1] ?? 8000);
$cache = [];

$prev = null;
for ($i = 0; $i < $count; $i++) {
    // Build a chain so each object reaches the next, exercising graph traversal.
    $obj = new ReflectionStub(
        "Closure#$i(string \$arg" . ($i % 23) . ", int = $i)",
        ['arg' . ($i % 23) => 'string', 'opt' => $i],
    );
    $obj->next = $prev;
    $prev = $obj;
    $cache['hash_' . str_pad((string)$i, 12, '0', STR_PAD_LEFT)] = $obj;
}

fwrite(STDERR, "READY pid=" . getmypid() . " count=$count mem=" . memory_get_usage() . "\n");
file_put_contents(getenv("RELI_TARGET_PID_FILE") ?: "/tmp/sqlite-validation/target.pid", getmypid());

while (true) {
    sleep(60);
}
