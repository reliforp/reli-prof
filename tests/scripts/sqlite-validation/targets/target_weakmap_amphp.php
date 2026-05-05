<?php
// Reproduces amphp/file#88 shape: WeakMap holding objects that hold
// callbacks/files. WeakMap is a special PHP internal class with its
// own zend_weakref_object_storage. Verifying reli can dump heaps that
// contain it without erroring or skipping the entries.

if (!class_exists('WeakMap')) {
    fwrite(STDERR, "WeakMap not available\n");
    exit(1);
}

class Connection {
    public string $payload;
    public ?\Closure $on_close = null;
    public array $tags;
    public function __construct(int $i) {
        $this->payload = str_repeat("p$i ", 64);
        $this->tags = ['conn-' . $i, 'flow-' . ($i % 7), 'group-' . ($i % 19)];
    }
}

$count = (int)($argv[1] ?? 5000);

$weak  = new WeakMap();
$strong_holder = []; // hold strong refs so the WeakMap entries stay alive

for ($i = 0; $i < $count; $i++) {
    $c = new Connection($i);
    // Closure that captures $c via use by reference — same shape as
    // amphp's "openFile leak via WeakMap entry holding closure" bug.
    $c->on_close = function () use ($c) { return $c->payload; };
    $weak[$c] = ['arrived' => microtime(true), 'tags' => $c->tags];
    $strong_holder[] = $c;
}

fwrite(STDERR, "READY pid=" . getmypid() . " weakmap_size=" . count($weak)
    . " mem=" . memory_get_usage() . "\n");
file_put_contents(getenv('RELI_TARGET_PID_FILE') ?: "/tmp/sqlite-validation/target.pid", getmypid());
while (true) { sleep(60); }
