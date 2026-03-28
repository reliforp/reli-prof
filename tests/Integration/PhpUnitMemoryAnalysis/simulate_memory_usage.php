<?php
/**
 * Generate a large test file, run PHPUnit on it, and keep the process alive
 * via a sleep in a shutdown function.
 */

// Keep the process alive after PHPUnit exits
register_shutdown_function(function () {
    $pid = getmypid();
    fwrite(STDERR, "\nREADY_FOR_ANALYSIS PID:$pid\n");
    fwrite(STDERR, "Memory: " . round(memory_get_usage(true)/1024/1024, 2) . " MB\n");
    fwrite(STDERR, "Peak: " . round(memory_get_peak_usage(true)/1024/1024, 2) . " MB\n");
    sleep(120);
});

$reliRoot = dirname(__DIR__, 3);
$numTests = 3000;

// Generate test file
$testCode = "<?php\nuse PHPUnit\\Framework\\TestCase;\n\nclass GeneratedTest extends TestCase {\n";
for ($i = 0; $i < $numTests; $i++) {
    $testCode .= "    public function testCase{$i}(): void {\n";
    $testCode .= "        \$data = array_map(fn(\$x) => \$x * 2, range(1, 100));\n";
    $testCode .= "        \$this->assertCount(100, \$data);\n";
    $testCode .= "        \$this->assertEquals(200, \$data[99]);\n";
    $testCode .= "    }\n";
}
$testCode .= "}\n";

$testFile = sys_get_temp_dir() . '/GeneratedTest_' . getmypid() . '.php';
file_put_contents($testFile, $testCode);

fwrite(STDERR, "PID:" . getmypid() . "\n");
fwrite(STDERR, "Tests: $numTests\n");

// Run PHPUnit in-process
$_SERVER['argv'] = ['phpunit', '--no-configuration', '--no-progress', $testFile];
$_SERVER['argc'] = count($_SERVER['argv']);
require "$reliRoot/vendor/bin/phpunit";
