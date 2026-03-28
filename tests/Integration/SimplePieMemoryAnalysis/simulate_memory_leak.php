<?php
/**
 * Reproduce SimplePie memory leak (simplepie/simplepie#874).
 *
 * The bug: SimplePie::__destruct() and Item::__destruct() only clean up
 * references when gc_enabled() returns false. Under normal PHP config
 * (GC enabled), Item::$feed retains a back-reference to the parent
 * SimplePie object, preventing memory from being freed on unset().
 *
 * Usage: php simulate_memory_leak.php
 */

require_once __DIR__ . '/vendor/autoload.php';

$pid = getmypid();
echo "PID: $pid\n";
echo "GC enabled: " . (gc_enabled() ? 'yes' : 'no') . "\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n\n";

// Generate a large RSS feed in memory
function generateLargeRssFeed(int $numItems, int $descriptionSize = 2000): string {
    $rss = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $rss .= '<rss version="2.0"><channel>' . "\n";
    $rss .= '<title>Test Feed</title><link>http://example.com</link>' . "\n";
    $rss .= '<description>Large feed for memory testing</description>' . "\n";

    for ($i = 0; $i < $numItems; $i++) {
        $desc = str_repeat("Item $i description text. ", $descriptionSize / 30);
        $rss .= "<item>\n";
        $rss .= "  <title>Item $i - " . bin2hex(random_bytes(20)) . "</title>\n";
        $rss .= "  <link>http://example.com/item/$i</link>\n";
        $rss .= "  <description><![CDATA[$desc]]></description>\n";
        $rss .= "  <pubDate>" . date('r', time() - $i * 3600) . "</pubDate>\n";
        $rss .= "  <guid>http://example.com/item/$i</guid>\n";
        $rss .= "</item>\n";
    }

    $rss .= '</channel></rss>';
    return $rss;
}

$numItems = 500;
$descSize = 4000;
echo "Generating RSS feed with $numItems items (~" . round($numItems * $descSize / 1024) . " KB)...\n";
$feedXml = generateLargeRssFeed($numItems, $descSize);
echo "Feed size: " . round(strlen($feedXml) / 1024) . " KB\n";

$memBaseline = memory_get_usage(true);
echo "Baseline memory: " . round($memBaseline / 1024 / 1024, 2) . " MB\n\n";

// Parse multiple feeds to demonstrate accumulation
$feedCount = 10;
echo "Parsing $feedCount feeds sequentially (unset after each)...\n\n";

for ($f = 0; $f < $feedCount; $f++) {
    $feed = new \SimplePie\SimplePie();
    $feed->set_raw_data($feedXml);
    $feed->enable_cache(false);
    $feed->init();

    $items = $feed->get_items();
    $itemCount = count($items);

    // Access items to force full parsing
    foreach ($items as $item) {
        $item->get_title();
        $item->get_description();
    }

    unset($items, $feed);

    $memNow = memory_get_usage(true);
    $memDelta = $memNow - $memBaseline;
    echo sprintf("  Feed %2d: %d items parsed, memory: %.2f MB (delta: +%.2f MB)\n",
        $f + 1, $itemCount, $memNow / 1024 / 1024, $memDelta / 1024 / 1024);
}

echo "\nFinal memory: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "Peak memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nRunning gc_collect_cycles()...\n";
$collected = gc_collect_cycles();
echo "Cycles collected: $collected\n";
echo "Memory after GC: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";
echo "Attach: sudo php /home/user/reli-prof/reli inspector:memory -p $pid --output-format=sqlite3 --output=/tmp/reli_simplepie.sqlite3\n";
sleep(60);
