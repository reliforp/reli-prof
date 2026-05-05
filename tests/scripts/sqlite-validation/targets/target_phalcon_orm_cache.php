<?php
// Reproduces phalcon/cphalcon#16954 shape:
// Model::find() with 'cache' parameter exhausts memory in loops.
// We don't actually need Phalcon — the leak shape is "an array cache keyed
// by serialised query, value is a list of model objects, never bounded."
// Each pseudo-row holds string + int + nested array, so it stresses the
// string/array tables of reli's SQLite output.

class FakeModel {
    public string $name;
    public int $id;
    public array $relations;
    public function __construct(int $id) {
        $this->id = $id;
        $this->name = "row_$id" . str_repeat('x', 32);
        $this->relations = [
            'parent_id' => $id - 1,
            'tags'      => ['tag-' . ($id % 7), 'tag-' . ($id % 11)],
            'meta'      => ['created' => 'date-' . $id, 'note' => 'a'],
        ];
    }
}

$cache = [];
$keys  = ['users', 'posts', 'comments', 'orders', 'sessions'];
$id = 0;
$rows = (int)($argv[1] ?? 5000);

for ($i = 0; $i < $rows; $i++) {
    $key = $keys[$i % count($keys)] . ':q' . $i;
    $cache[$key] = [];
    for ($k = 0; $k < 4; $k++) {
        $cache[$key][] = new FakeModel($id++);
    }
}

fwrite(STDERR, "READY pid=" . getmypid() . " rows=$rows id=$id mem=" . memory_get_usage() . "\n");
file_put_contents(getenv("RELI_TARGET_PID_FILE") ?: "/tmp/sqlite-validation/target.pid", getmypid());

// park
while (true) {
    sleep(60);
}
