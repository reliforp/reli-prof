<?php
/**
 * Case 5: Eloquent-like ORM Hydration Memory Exhaustion
 * Ref: firefly-iii/firefly-iii#9864
 *
 * Simulates loading thousands of model instances with eager-loaded
 * relationships, causing memory exhaustion through object hydration.
 */

class Model {
    protected array $attributes = [];
    protected array $original = [];
    protected array $relations = [];
    protected array $casts = [];
    protected ?string $dateFormat = 'Y-m-d H:i:s';

    public function __construct(array $attributes = []) {
        $this->fill($attributes);
        $this->original = $this->attributes;
    }

    public function fill(array $attributes): void {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }

    public function setRelation(string $name, mixed $value): void {
        $this->relations[$name] = $value;
    }
}

class Transaction extends Model {
    public function __construct(array $attributes = []) {
        $attributes['casts'] = [
            'amount' => 'decimal:2',
            'date' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
        parent::__construct($attributes);
    }
}

class Account extends Model {}
class Category extends Model {}
class Tag extends Model {}

class Carbon {
    public string $date;
    public int $timestamp;
    public string $timezone;
    public array $internals = [];

    public function __construct(string $date = '2025-01-01') {
        $this->date = $date;
        $this->timestamp = strtotime($date);
        $this->timezone = 'UTC';
        // Carbon stores lots of internal state
        $this->internals = [
            'locale' => 'en_US',
            'formats' => ['date' => 'Y-m-d', 'time' => 'H:i:s', 'datetime' => 'Y-m-d H:i:s'],
            'translations' => array_fill(0, 50, 'translation_string'),
        ];
    }
}

// Simulate loading 4000 transactions with relations
$transactions = [];

posix_kill(posix_getpid(), SIGSTOP);

for ($i = 0; $i < 4000; $i++) {
    $tx = new Transaction([
        'id' => $i,
        'description' => "Transaction #{$i} - " . str_repeat('x', 200),
        'amount' => mt_rand(100, 100000) / 100.0,
        'date' => new Carbon("2025-02-" . str_pad(($i % 28) + 1, 2, '0', STR_PAD_LEFT)),
        'created_at' => new Carbon("2025-01-15"),
        'updated_at' => new Carbon("2025-02-01"),
        'notes' => str_repeat("Note for transaction {$i}. ", 10),
        'meta' => array_fill(0, 20, "meta_value_{$i}"),
    ]);

    // Eager-loaded relations (each creates new objects)
    $tx->setRelation('source_account', new Account([
        'id' => $i % 50,
        'name' => "Account " . ($i % 50),
        'type' => 'asset',
        'balance' => mt_rand(0, 1000000) / 100.0,
    ]));

    $tx->setRelation('dest_account', new Account([
        'id' => 50 + ($i % 30),
        'name' => "Expense Account " . ($i % 30),
        'type' => 'expense',
    ]));

    $tx->setRelation('category', new Category([
        'id' => $i % 20,
        'name' => "Category " . ($i % 20),
    ]));

    $tx->setRelation('tags', array_map(fn($t) => new Tag([
        'id' => $t,
        'name' => "Tag {$t}",
    ]), range(0, mt_rand(0, 5))));

    $transactions[] = $tx;
}

echo "Loaded " . count($transactions) . " transactions\n";
echo "Memory: " . number_format(memory_get_usage(true)) . " bytes\n";
echo "Peak:   " . number_format(memory_get_peak_usage(true)) . " bytes\n";

sleep(300);
