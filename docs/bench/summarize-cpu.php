<?php
// Summarise docs/bench/results/cpu.csv into a per-(profiler, depth) view
// of *sampler-side CPU*, where "sampler-side" means:
//   - phpspy: the phpspy process directly (separate process).
//   - reli, Excimer, Datadog, baseline: the (combined) target+sampler
//     process. We back out an estimate of the sampler's own cost by
//     subtracting baseline.
//
// Reports median user+sys CPU and median target wall, plus a derived
// "sampler CPU above baseline" column so the in-process and
// external numbers can be read on the same axis.
//
// Usage: php docs/bench/summarize-cpu.php docs/bench/results/cpu.csv

$path = $argv[1] ?? null;
if (!$path) {
    fwrite(STDERR, "usage: php summarize-cpu.php cpu.csv\n");
    exit(1);
}

$rows = [];
$h = fopen($path, 'r');
fgetcsv($h, 0, ',', '"', ''); // header
while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
    if (count($r) < 7) continue;
    $rows[] = [
        'profiler' => $r[0],
        'depth'    => (int)$r[1],
        'run'      => (int)$r[2],
        'wall'     => (float)$r[3],
        'user'     => (float)$r[4],
        'sys'      => (float)$r[5],
        'rss_kb'   => (int)$r[6],
    ];
}
fclose($h);

function median(array $v): float {
    sort($v);
    $n = count($v);
    if ($n === 0) return NAN;
    return $n % 2 ? $v[($n - 1) / 2] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

$by = [];
foreach ($rows as $r) {
    $key = $r['profiler'] . '|' . $r['depth'];
    $by[$key][] = $r;
}

// Aggregate.
$agg = [];
foreach ($by as $key => $rs) {
    [$prof, $depth] = explode('|', $key);
    $agg[$prof][(int)$depth] = [
        'wall'   => median(array_column($rs, 'wall')),
        'user'   => median(array_column($rs, 'user')),
        'sys'    => median(array_column($rs, 'sys')),
        'cpu'    => median(array_map(fn($r) => $r['user'] + $r['sys'], $rs)),
        'rss_kb' => median(array_column($rs, 'rss_kb')),
        'n'      => count($rs),
    ];
}

$depths = [];
foreach ($agg as $prof => $byDepth) {
    foreach (array_keys($byDepth) as $d) $depths[$d] = true;
}
ksort($depths);
$depths = array_keys($depths);

$base = $agg['baseline'] ?? [];

// Wide table: rows = profiler, columns = (depth) → "user+sys / above-baseline".
printf("%-16s", "profiler");
foreach ($depths as $d) printf(" | depth=%-3d (cpu / Δbase)", $d);
echo "\n";

$linewidth = 16 + count($depths) * (10 + 17);
echo str_repeat('-', $linewidth) . "\n";

foreach ($agg as $prof => $byDepth) {
    printf("%-16s", $prof);
    foreach ($depths as $d) {
        $cell = $byDepth[$d] ?? null;
        if ($cell === null) {
            printf(" | %-25s", "n/a");
            continue;
        }
        $cpu = $cell['cpu'];
        $base_cpu = $base[$d]['cpu'] ?? null;
        if ($prof === 'baseline' || $base_cpu === null) {
            printf(" |   %5.2fs                  ", $cpu);
        } elseif ($prof === 'phpspy-1khz') {
            // Already a separate process — its CPU is pure sampler.
            printf(" |   %5.2fs (pure sampler)   ", $cpu);
        } else {
            $delta = $cpu - $base_cpu;
            printf(" |   %5.2fs (Δ %+5.2fs)      ", $cpu, $delta);
        }
    }
    echo "\n";
}

echo "\n";
echo "RSS (kB, max):\n";
printf("%-16s", "profiler");
foreach ($depths as $d) printf(" | depth=%-3d", $d);
echo "\n";
echo str_repeat('-', 16 + count($depths) * 12) . "\n";
foreach ($agg as $prof => $byDepth) {
    printf("%-16s", $prof);
    foreach ($depths as $d) {
        $cell = $byDepth[$d] ?? null;
        printf(" | %9d", $cell['rss_kb'] ?? 0);
    }
    echo "\n";
}
