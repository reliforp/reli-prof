<?php
// Stress reli's heap walker against a mix of PHP 8.1+ shapes that
// have non-trivial zend internals: Fibers (have their own coroutine
// stack mmap), backed enums (zend_object with internal value slot),
// readonly properties (CLASS_PROPERTY_FLAG_READONLY), and #[\AllowDynamicProperties].
//
// Repro target — every reli code path (live capture, dump→analyze,
// SQLite output, JSON output) should produce integrity-clean output
// against this and ideally the same row-count / shape as a regular
// object target.

enum Status: string {
    case Pending = 'pending';
    case Active  = 'active';
    case Closed  = 'closed';
}

enum Priority: int {
    case Low  = 1;
    case High = 2;
}

#[\AllowDynamicProperties]
class DynObj {
    public string $base_field;
    public function __construct(string $b) { $this->base_field = $b; }
}

final class ReadonlyTask {
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly Status $status,
        public readonly Priority $priority,
        public readonly array $tags,
        public readonly ?ReadonlyTask $parent = null,
    ) {}
}

$count = (int)($argv[1] ?? 1500);

$tasks = [];
$prev = null;
for ($i = 0; $i < $count; $i++) {
    $task = new ReadonlyTask(
        id: $i,
        name: "task_$i",
        status: [Status::Pending, Status::Active, Status::Closed][$i % 3],
        priority: $i % 2 === 0 ? Priority::Low : Priority::High,
        tags: ['t-' . ($i % 7), 't-' . ($i % 11), str_repeat('x', 32)],
        parent: $prev,
    );
    $prev = $task;
    $tasks[] = $task;
}

// AllowDynamicProperties: 500 instances each with 4 dynamic props
$dyn = [];
for ($i = 0; $i < 500; $i++) {
    $o = new DynObj("base-$i");
    $o->extra1 = "x1-$i";
    $o->extra2 = ['nested' => $i, 'tag' => 'tag-' . ($i % 5)];
    $o->extra3 = $tasks[$i % count($tasks)] ?? null;
    $o->extra4 = Status::Active;
    $dyn[] = $o;
}

// Suspended fibers — each retains its captured locals, including a
// reference to the enclosing context. Different internal layout from
// generators because Fiber has its own zend_fiber_context with stack.
$fibers = [];
for ($i = 0; $i < 50; $i++) {
    $fib = new Fiber(function (int $n) use ($tasks): void {
        $local_buf = [];
        $local_buf[] = $tasks[$n % count($tasks)];
        Fiber::suspend($local_buf);   // park here
        // unreachable until resumed
    });
    $fib->start($i);
    $fibers[] = $fib;
}

fwrite(STDERR, "READY pid=" . getmypid()
    . " readonly_tasks=$count dyn_objs=" . count($dyn)
    . " suspended_fibers=" . count($fibers)
    . " mem=" . memory_get_usage() . "\n");
file_put_contents(getenv('RELI_TARGET_PID_FILE') ?: '/tmp/sqlite-validation/target.pid', getmypid());
while (true) { sleep(60); }
