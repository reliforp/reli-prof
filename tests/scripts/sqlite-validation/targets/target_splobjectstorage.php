<?php
// Reproduces the Doctrine UnitOfWork / SplObjectStorage shape — used
// pervasively in PSR caches, json-serializer, ddeboer/data-import,
// PHPUnit test doubles, etc. SplObjectStorage internally uses a
// hash-table over object IDs and stores attached data alongside.

class Entity {
    public int $id;
    public string $name;
    public array $children = [];
    public function __construct(int $id) {
        $this->id = $id;
        $this->name = "entity_$id";
    }
}

$count = (int)($argv[1] ?? 4000);

$storage = new SplObjectStorage();
$entities = [];

for ($i = 0; $i < $count; $i++) {
    $e = new Entity($i);
    $entities[] = $e;
    // Attach with associated data — exercises the Bucket's "info" slot
    // alongside the key. Doctrine's UnitOfWork holds one of these for
    // every managed entity.
    $storage[$e] = [
        'state'   => $i % 4,
        'changes' => ['old' => "old_$i", 'new' => "new_$i"],
        'tag'     => 'tag-' . ($i % 11),
    ];
}

// Wire children — every 5th entity gets up to 3 children, creating an
// object graph alongside the SplObjectStorage flat keys
foreach ($entities as $i => $e) {
    if ($i % 5 === 0) {
        for ($k = 1; $k <= 3 && $i + $k < $count; $k++) {
            $e->children[] = $entities[$i + $k];
        }
    }
}

fwrite(STDERR, "READY pid=" . getmypid()
    . " storage_size=" . count($storage)
    . " mem=" . memory_get_usage() . "\n");
file_put_contents(getenv('RELI_TARGET_PID_FILE') ?: "/tmp/sqlite-validation/target.pid", getmypid());
while (true) { sleep(60); }
