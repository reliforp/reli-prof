<?php
// Target that parks itself at a known line, simulating a "captured
// snapshot at the moment of OOM" scenario. The
// --memory-limit-error-file/--memory-limit-error-line options of
// inspector:memory point reli at a specific function frame to
// trace from.

// Build a moderate heap so reli has something to walk.
$rows = [];
for ($i = 0; $i < 2000; $i++) {
    $rows[] = ['id' => $i, 'name' => "row_$i", 'tags' => ['a', 'b', 'c']];
}

class Worker {
    public array $queue = [];
    public function process(string $payload): void {
        $this->queue[] = $payload . str_repeat('x', 64);
        $this->parkAtLine();      // <-- stable line we'll target
    }
    private function parkAtLine(): void {
        // Park here. Reli will be invoked with
        //   --memory-limit-error-file=this_file
        //   --memory-limit-error-line=<this exact line>
        fwrite(STDERR, "READY pid=" . getmypid()
            . " queue=" . count($this->queue)
            . " mem=" . memory_get_usage() . "\n");
        file_put_contents(getenv('RELI_TARGET_PID_FILE') ?: '/tmp/sqlite-validation/target.pid', getmypid());
        while (true) { sleep(60); }    // <-- target line
    }
}

$w = new Worker();
$w->process('payload');
