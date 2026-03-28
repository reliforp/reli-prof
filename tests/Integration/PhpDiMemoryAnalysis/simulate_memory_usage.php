<?php
/**
 * Analyze PHP-DI container memory with many definitions.
 *
 * DI containers hold service definitions, resolved instances, and
 * autowiring metadata. Large apps can have 1000+ services.
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use DI\ContainerBuilder;

$pid = getmypid();
echo "PID: $pid\n\n";

$memBaseline = memory_get_usage(true);

// Generate many service classes
$numServices = 1000;
$code = "";
for ($i = 0; $i < $numServices; $i++) {
    $deps = [];
    // Each service depends on 0-3 other services
    $numDeps = min($i, rand(0, 3));
    for ($d = 0; $d < $numDeps; $d++) {
        $depIdx = rand(0, $i - 1);
        $deps[] = "Service_{$depIdx}";
    }

    $depParams = implode(', ', array_map(fn($d) => "public readonly $d \$dep_$d", array_unique($deps)));
    $code .= "class Service_{$i} { public function __construct($depParams) {} }\n";
}
eval($code);

echo "Defined $numServices service classes\n";

// Build container with definitions
$builder = new ContainerBuilder();
$definitions = [];
for ($i = 0; $i < $numServices; $i++) {
    $definitions["Service_{$i}"] = \DI\autowire("Service_{$i}");
}
$builder->addDefinitions($definitions);
$container = $builder->build();

$memAfterBuild = memory_get_usage(true);
echo "After container build: " . round($memAfterBuild / 1024 / 1024, 2) . " MB\n";

// Resolve all services to populate the container cache
echo "Resolving all $numServices services...\n";
for ($i = 0; $i < $numServices; $i++) {
    $container->get("Service_{$i}");
}

$memAfterResolve = memory_get_usage(true);
echo "After all resolved: " . round($memAfterResolve / 1024 / 1024, 2) . " MB\n";
echo "Delta: +" . round(($memAfterResolve - $memBaseline) / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";
$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
