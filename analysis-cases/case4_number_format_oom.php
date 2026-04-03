<?php
/**
 * Case 4: Unbounded Allocation via number_format()
 * Ref: php/php-src#17384
 *
 * Simulates the pattern of user input causing unbounded memory allocation.
 * Instead of reproducing the exact bug (which would crash), we simulate
 * a similar pattern: unvalidated numeric parameters causing excessive allocation.
 */

class ReportGenerator {
    private array $formattedData = [];

    public function formatNumber(float $value, int $decimals): string {
        // No validation on $decimals - user input passed directly
        return number_format($value, $decimals);
    }

    public function generateReport(array $data, array $formatConfig): void {
        foreach ($data as $row) {
            $formatted = [];
            foreach ($row as $key => $value) {
                if (is_float($value) || is_int($value)) {
                    $decimals = $formatConfig[$key] ?? 2;
                    // Large decimal values cause massive string allocations
                    $formatted[$key] = str_repeat('0', min($decimals, 100000));
                } else {
                    $formatted[$key] = (string)$value;
                }
            }
            $this->formattedData[] = $formatted;
        }
    }

    public function getMemoryUsage(): int {
        return memory_get_usage(true);
    }
}

// Simulate processing with progressively larger format configs
$generator = new ReportGenerator();
$data = [];
for ($i = 0; $i < 100; $i++) {
    $data[] = [
        'amount' => mt_rand(1, 10000) / 100.0,
        'rate' => mt_rand(1, 100) / 1000.0,
        'total' => mt_rand(1, 1000000) / 100.0,
    ];
}

posix_kill(posix_getpid(), SIGSTOP);

// Simulate bad user input: excessive decimal places
$badConfig = ['amount' => 50000, 'rate' => 50000, 'total' => 50000];
$generator->generateReport($data, $badConfig);

echo "After report generation:\n";
echo "Memory: " . number_format($generator->getMemoryUsage()) . " bytes\n";
echo "Rows processed: " . count($data) . "\n";

sleep(300);
