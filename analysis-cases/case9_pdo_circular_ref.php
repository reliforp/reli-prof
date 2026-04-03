<?php
/**
 * Case 9: PDO Prepared Statement Circular Reference Leak
 * Ref: doctrine/dbal#3047
 * Root cause: Extending PDO creates circular refs between connection and statements.
 */
class PDOConnectionWrapper extends PDO {
    private array $statementCache = [];

    public function __construct() {
        parent::__construct('sqlite::memory:');
        $this->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, value TEXT)');
        for ($i = 0; $i < 100; $i++) {
            $this->exec("INSERT INTO test VALUES ($i, 'value_$i')");
        }
    }

    public function prepareStatement(string $sql): PDOStatement {
        // Statement retains extra reference to $this (the extended PDO)
        $stmt = $this->prepare($sql);
        $this->statementCache[] = $stmt; // Circular: connection -> stmt -> connection
        return $stmt;
    }
}

posix_kill(posix_getpid(), SIGSTOP);

$conn = new PDOConnectionWrapper();
$results = [];

for ($i = 0; $i < 2000; $i++) {
    $stmt = $conn->prepareStatement("SELECT * FROM test WHERE id = :id");
    $stmt->execute(['id' => $i % 100]);
    $results[] = $stmt->fetch(PDO::FETCH_ASSOC);
    // Statement should be freed but connection holds reference
}

echo "Cached statements: " . count($results) . "\n";
echo "Memory: " . number_format(memory_get_usage(true)) . "\n";
sleep(300);
