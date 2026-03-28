<?php
/**
 * Analyze webonyx/graphql-php memory with large schemas.
 *
 * GraphQL builds a type system with Type objects, Field objects, and
 * resolvers for every field. Large schemas can consume significant memory.
 */

require_once __DIR__ . '/vendor/autoload.php';

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\GraphQL;

$pid = getmypid();
echo "PID: $pid\n\n";

$memBaseline = memory_get_usage(true);

// Build a large GraphQL schema
$numTypes = 200;
$fieldsPerType = 20;
echo "Building schema: $numTypes types × $fieldsPerType fields = " . ($numTypes * $fieldsPerType) . " fields\n";

$types = [];
for ($t = 0; $t < $numTypes; $t++) {
    $fields = [];
    for ($f = 0; $f < $fieldsPerType; $f++) {
        $fieldType = match ($f % 4) {
            0 => Type::string(),
            1 => Type::int(),
            2 => Type::float(),
            3 => Type::boolean(),
        };
        $fields["field_{$f}"] = [
            'type' => $fieldType,
            'description' => "Field $f of Type $t - description text here",
            'resolve' => fn() => "value_{$t}_{$f}",
        ];
    }

    // Add references to other types (creating relationships)
    if ($t > 0) {
        $fields['related'] = [
            'type' => Type::listOf($types[array_rand($types)]),
            'description' => 'Related items',
        ];
    }

    $types["Type_{$t}"] = new ObjectType([
        'name' => "Type_{$t}",
        'description' => "Auto-generated type $t for memory analysis",
        'fields' => $fields,
    ]);
}

// Create Query root
$queryFields = [];
foreach ($types as $name => $type) {
    $queryFields[lcfirst($name)] = [
        'type' => Type::listOf($type),
        'resolve' => fn() => [],
    ];
}

$schema = new Schema([
    'query' => new ObjectType([
        'name' => 'Query',
        'fields' => $queryFields,
    ]),
]);

$memAfterSchema = memory_get_usage(true);
echo "After schema build: " . round($memAfterSchema / 1024 / 1024, 2) . " MB\n";
echo "Delta: +" . round(($memAfterSchema - $memBaseline) / 1024 / 1024, 2) . " MB\n";

// Execute a query to force type resolution
$query = '{ type_0 { field_0 field_1 field_2 } }';
$result = GraphQL::executeQuery($schema, $query);
$memAfterQuery = memory_get_usage(true);
echo "After query: " . round($memAfterQuery / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";
$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
