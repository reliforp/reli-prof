<?php
// Read raw CSV(s) and produce a per-(config, bench) summary table.
// Reports median, p25, p75, n, and multiplier vs baseline.
//
// Usage: php bench/summarize.php raw.csv [more.csv ...]

$inputs = array_slice($argv, 1);
if (!$inputs) {
    fwrite(STDERR, "usage: php summarize.php raw.csv [...]\n");
    exit(1);
}

$rows = [];
foreach ($inputs as $f) {
    $h = fopen($f, 'r');
    fgetcsv($h, 0, ',', '"', ''); // header
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        if (!isset($r[3]) || $r[3] === '' || $r[3] === 'NA') continue;
        $rows[] = ['config' => $r[0], 'bench' => $r[1], 'sec' => (float)$r[3]];
    }
    fclose($h);
}

$by = [];
foreach ($rows as $r) {
    $by[$r['config']][$r['bench']][] = $r['sec'];
}

function pct(array $v, float $p): float {
    sort($v);
    $n = count($v);
    if ($n === 0) return NAN;
    $i = ($n - 1) * $p;
    $lo = (int)floor($i);
    $hi = (int)ceil($i);
    return $lo === $hi ? $v[$lo] : $v[$lo] + ($v[$hi] - $v[$lo]) * ($i - $lo);
}

$base = [];
foreach ($by['baseline'] ?? [] as $b => $v) $base[$b] = pct($v, 0.5);

$benches = array_keys($base);
$configs = array_keys($by);

// Header.
printf("%-22s %-22s", "config", "bench");
printf(" %5s %8s %8s %8s %8s %8s\n", "n", "p25", "p50", "p75", "iqr/p50", "vs base");
echo str_repeat('-', 22 + 22 + 6 + 9 * 5) . "\n";

foreach ($configs as $c) {
    foreach ($benches as $b) {
        $v = $by[$c][$b] ?? [];
        if (!$v) continue;
        $n = count($v);
        $p25 = pct($v, 0.25);
        $p50 = pct($v, 0.5);
        $p75 = pct($v, 0.75);
        $iqr_pct = $p50 > 0 ? ($p75 - $p25) / $p50 * 100 : 0;
        $mult = isset($base[$b]) && $base[$b] > 0 ? $p50 / $base[$b] : NAN;
        printf("%-22s %-22s %5d %8.3fs %8.3fs %8.3fs %7.1f%% %7.2fx\n",
            $c, $b, $n, $p25, $p50, $p75, $iqr_pct, $mult);
    }
    echo "\n";
}
