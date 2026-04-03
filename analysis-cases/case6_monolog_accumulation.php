<?php
/**
 * Case 6: Monolog Logger Accumulation in Long-Running Process
 * Ref: php/php-src#20724
 * Root cause: Monolog caches all logged messages; deprecation notices in PHP 8.4 amplify this.
 */
class MonologHandler {
    private array $records = [];
    public function handle(array $record): void {
        $this->records[] = $record;
    }
    public function getRecordCount(): int { return count($this->records); }
}

class DeprecationLogger {
    private MonologHandler $handler;
    public function __construct(MonologHandler $handler) { $this->handler = $handler; }
    public function logDeprecation(string $message, array $context = []): void {
        $this->handler->handle([
            'message' => $message,
            'level' => 'deprecation',
            'context' => $context,
            'datetime' => date('Y-m-d H:i:s'),
            'extra' => ['trace' => array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 0, 5)],
        ]);
    }
}

class BatchProcessor {
    private DeprecationLogger $logger;
    private array $results = [];
    public function __construct(DeprecationLogger $logger) { $this->logger = $logger; }
    public function processItem(int $id): void {
        // Each item triggers deprecation notices
        $this->logger->logDeprecation("Method getData() is deprecated", ['item_id' => $id]);
        $this->logger->logDeprecation("Return type will change in 9.0", ['item_id' => $id]);
        $this->results[] = ['id' => $id, 'status' => 'processed', 'data' => str_repeat('x', 200)];
    }
}

$handler = new MonologHandler();
$logger = new DeprecationLogger($handler);
$processor = new BatchProcessor($logger);

posix_kill(posix_getpid(), SIGSTOP);

for ($i = 0; $i < 5000; $i++) {
    $processor->processItem($i);
}

echo "Records: " . $handler->getRecordCount() . "\n";
echo "Memory: " . number_format(memory_get_usage(true)) . "\n";
sleep(300);
