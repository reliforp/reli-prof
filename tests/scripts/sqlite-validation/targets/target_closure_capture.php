<?php
// Reproduces the very common "closure captures too much in `use`"
// memory leak shape — the Symfony EventDispatcher / Laravel queue
// listener / Reactor handler family of issues. Each closure holds a
// reference to a heavy context object. zend_closure has its own
// internal layout (this_ptr, called_scope, debuginfo) that reli's
// memory-graph code has to handle separately from regular objects.

class HeavyContext {
    public string $blob;
    public array $config;
    public ?HeavyContext $previous = null;
    public function __construct(int $i) {
        $this->blob = str_repeat("h", 256);
        $this->config = [
            'i'   => $i,
            'env' => 'env_' . ($i % 5),
            'opts' => ['retry' => $i % 3, 'tag' => 'tag_' . ($i % 7)],
        ];
    }
}

class EventDispatcher {
    public array $listeners = [];
    public function on(string $event, \Closure $c): void {
        $this->listeners[$event][] = $c;
    }
}

$count = (int)($argv[1] ?? 3000);
$disp  = new EventDispatcher();

$prev = null;
for ($i = 0; $i < $count; $i++) {
    $ctx = new HeavyContext($i);
    $ctx->previous = $prev;
    $prev = $ctx;

    // (1) Closure capturing $ctx by `use` — the standard leak shape
    $disp->on('event_a', function ($payload) use ($ctx) {
        return $ctx->config['i'] . ':' . $payload;
    });
    // (2) Closure with $this-binding (->bindTo or via method) — has
    //     `this_ptr` slot non-null
    $bound = (function ($x) { return $this->config['env'] . '/' . $x; })->bindTo($ctx, HeavyContext::class);
    $disp->on('event_b', $bound);
    // (3) Static closure — `this_ptr` should be null. Different code
    //     path in the closure dumper.
    $disp->on('event_c', static function ($payload) use ($i) { return "static:$i:$payload"; });
    // (4) First-class callable from method (ClosureFromCallable variant)
    $disp->on('event_d', $ctx->previous?->config['opts']['tag'] !== null
        ? \Closure::fromCallable(fn () => $ctx->config)
        : (static fn () => null));
}

fwrite(STDERR, "READY pid=" . getmypid()
    . " listeners_a=" . count($disp->listeners['event_a'])
    . " listeners_b=" . count($disp->listeners['event_b'])
    . " listeners_c=" . count($disp->listeners['event_c'])
    . " listeners_d=" . (isset($disp->listeners['event_d']) ? count($disp->listeners['event_d']) : 0)
    . " mem=" . memory_get_usage() . "\n");
file_put_contents(getenv('RELI_TARGET_PID_FILE') ?: "/tmp/sqlite-validation/target.pid", getmypid());
while (true) { sleep(60); }
