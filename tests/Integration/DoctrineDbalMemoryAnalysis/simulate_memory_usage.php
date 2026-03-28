<?php
/**
 * Analyze Doctrine DBAL QueryBuilder memory.
 *
 * Related to nextcloud/server#59018 where QueryBuilder reached 5GB.
 * Each QueryBuilder accumulates parameters, expressions, and part objects.
 * Test: create many QueryBuilders in a loop without reuse.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;

$pid = getmypid();
echo "PID: $pid\n\n";

$conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$conn->executeStatement('CREATE TABLE users (id INT, name TEXT, email TEXT, status TEXT, created_at TEXT)');
$conn->executeStatement('CREATE TABLE orders (id INT, user_id INT, total REAL, status TEXT)');

$memBaseline = memory_get_usage(true);

// Scenario: Create many QueryBuilders (simulating a batch operation that
// creates a new QB per iteration instead of resetting)
$numQueries = 10000;
$builders = [];
echo "Creating $numQueries QueryBuilders...\n";

for ($i = 0; $i < $numQueries; $i++) {
    $qb = $conn->createQueryBuilder();
    $qb->select('u.id', 'u.name', 'u.email', 'o.total')
       ->from('users', 'u')
       ->leftJoin('u', 'orders', 'o', 'u.id = o.user_id')
       ->where('u.status = :status')
       ->andWhere('o.total > :min_total')
       ->orderBy('u.name', 'ASC')
       ->setParameter('status', 'active')
       ->setParameter('min_total', rand(10, 1000));
    $builders[] = $qb;

    if (($i + 1) % 2000 === 0) {
        $memNow = memory_get_usage(true);
        echo "  After " . ($i+1) . ": " . round($memNow / 1024 / 1024, 2) . " MB\n";
    }
}

$memAfter = memory_get_usage(true);
echo "\nFinal: " . round($memAfter / 1024 / 1024, 2) . " MB\n";
echo "Delta: +" . round(($memAfter - $memBaseline) / 1024 / 1024, 2) . " MB\n";
echo "Per QB: ~" . round(($memAfter - $memBaseline) / $numQueries) . " bytes\n";

echo "\nREADY_FOR_ANALYSIS\n";
$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
