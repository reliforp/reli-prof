<?php
declare(strict_types=1);

// Idle target: busy enough to have stable execute_data, light enough not to
// dominate CPU. Used as a sampling target by the live bench.
$arr = [];
while (true) {
    $arr[] = str_repeat('x', 32);
    if (count($arr) > 256) {
        $arr = array_slice($arr, -32);
    }
    usleep(1000);
}
