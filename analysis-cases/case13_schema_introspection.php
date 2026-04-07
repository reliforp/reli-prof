<?php
/**
 * Case 13: Schema Introspection Quadratic Memory Growth
 * Ref: doctrine/dbal#5588
 * Root cause: Schema manager appends index/constraint info without clearing between iterations.
 */
class Column {
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = false,
        public ?string $default = null,
    ) {}
}

class Index {
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false,
    ) {}
}

class ForeignKey {
    public function __construct(
        public string $name,
        public string $localColumn,
        public string $foreignTable,
        public string $foreignColumn,
    ) {}
}

class Table {
    /** @var Column[] */ public array $columns = [];
    /** @var Index[] */ public array $indexes = [];
    /** @var ForeignKey[] */ public array $foreignKeys = [];
    public function __construct(public string $name) {}
}

class SchemaManager {
    private array $indexBuffer = []; // Bug: never cleared between calls
    private array $tableCache = [];

    public function introspectTable(string $name): Table {
        $table = new Table($name);
        for ($i = 0; $i < 10; $i++) {
            $table->columns[] = new Column("col_{$i}", 'varchar', $i % 2 === 0, "default_{$i}");
        }
        for ($i = 0; $i < 3; $i++) {
            $idx = new Index("idx_{$name}_{$i}", ["col_{$i}"], $i === 0);
            $table->indexes[] = $idx;
            $this->indexBuffer[] = $idx; // Accumulates across all calls
        }
        if (rand(0, 1)) {
            $fk = new ForeignKey("fk_{$name}", "col_0", "other_table", "id");
            $table->foreignKeys[] = $fk;
        }
        $this->tableCache[$name] = $table;
        return $table;
    }

    public function getBufferSize(): int { return count($this->indexBuffer); }
}

$manager = new SchemaManager();

posix_kill(posix_getpid(), SIGSTOP);

// Simulate test suite running RefreshDatabase repeatedly
for ($iteration = 0; $iteration < 100; $iteration++) {
    $tables = ['users', 'posts', 'comments', 'tags', 'categories',
               'media', 'settings', 'logs', 'sessions', 'cache'];
    foreach ($tables as $table) {
        $manager->introspectTable("{$table}_{$iteration}");
    }
}
echo "Index buffer: " . $manager->getBufferSize() . "\n";
echo "Memory: " . number_format(memory_get_usage(true)) . "\n";
sleep(300);
