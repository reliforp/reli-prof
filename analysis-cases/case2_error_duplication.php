<?php
/**
 * Case 2: PHPStan-like Error Duplication Memory Exhaustion
 * Ref: phpstan/phpstan#13813
 *
 * Simulates exponential error/warning accumulation in a static analysis context
 * where the same error gets duplicated in memory instead of being deduplicated.
 */

class AnalysisError {
    public function __construct(
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        public readonly array $context = [],
    ) {}
}

class ErrorCollector {
    /** @var AnalysisError[] */
    private array $errors = [];

    public function addError(AnalysisError $error): void {
        // Bug: no deduplication - same error added multiple times
        $this->errors[] = $error;
    }

    public function getCount(): int {
        return count($this->errors);
    }
}

class TypeAnalyzer {
    private ErrorCollector $collector;
    private int $recursionDepth = 0;

    public function __construct(ErrorCollector $collector) {
        $this->collector = $collector;
    }

    public function analyzeType(string $typeName, array $scope): void {
        if ($this->recursionDepth > 15) return;
        $this->recursionDepth++;

        // Simulate finding undefined variable - error gets duplicated per branch
        foreach ($scope['branches'] ?? [] as $branch) {
            $this->collector->addError(new AnalysisError(
                "Undefined variable \$y",
                "src/Type/ObjectType.php",
                1589,
                ['branch' => $branch, 'type' => $typeName, 'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)],
            ));

            // Recursive analysis multiplies the error
            if (isset($branch['subBranches'])) {
                $this->analyzeType($typeName, ['branches' => $branch['subBranches']]);
            }
        }

        $this->recursionDepth--;
    }
}

// Build a deeply nested branch structure
function buildBranches(int $depth, int $width): array {
    if ($depth <= 0) return [];
    $branches = [];
    for ($i = 0; $i < $width; $i++) {
        $branches[] = [
            'name' => "branch_{$depth}_{$i}",
            'subBranches' => buildBranches($depth - 1, $width),
        ];
    }
    return $branches;
}

$collector = new ErrorCollector();
$analyzer = new TypeAnalyzer($collector);
$scope = ['branches' => buildBranches(8, 3)];

posix_kill(posix_getpid(), SIGSTOP);

$analyzer->analyzeType('ObjectType', $scope);

echo "Total errors collected: " . $collector->getCount() . "\n";
echo "Memory used: " . number_format(memory_get_usage(true)) . " bytes\n";

sleep(300);
