<?php
/**
 * Analyze league/commonmark memory usage with large Markdown documents.
 *
 * CommonMark builds a full AST (Abstract Syntax Tree) from the Markdown,
 * then renders it. Each block/inline becomes a Node object with
 * parent/child/sibling pointers — potential for high object overhead.
 */

require_once __DIR__ . '/vendor/autoload.php';

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\GithubFlavoredMarkdownConverter;

$pid = getmypid();
echo "PID: $pid\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n\n";

// Generate a large Markdown document
function generateMarkdown(int $numSections): string {
    $md = "# Large Markdown Document\n\n";
    $md .= "This document tests memory usage of CommonMark parsing.\n\n";

    for ($s = 0; $s < $numSections; $s++) {
        $md .= "## Section $s\n\n";

        // Paragraphs with inline formatting
        for ($p = 0; $p < 3; $p++) {
            $md .= "This is paragraph $p in section $s. ";
            $md .= "It contains **bold text**, *italic text*, `inline code`, ";
            $md .= "and [a link](https://example.com/$s/$p). ";
            $md .= "Here is more text with ~~strikethrough~~ and some ";
            $md .= "additional content to make it longer.\n\n";
        }

        // Lists
        $md .= "- Item 1 with **bold**\n";
        $md .= "- Item 2 with *italic*\n";
        $md .= "  - Nested item A\n";
        $md .= "  - Nested item B with `code`\n";
        $md .= "- Item 3\n\n";

        // Code block
        $md .= "```php\n";
        $md .= "function example_{$s}() {\n";
        $md .= "    return 'Hello from section $s';\n";
        $md .= "}\n";
        $md .= "```\n\n";

        // Table (GFM extension)
        $md .= "| Column A | Column B | Column C |\n";
        $md .= "|----------|----------|----------|\n";
        for ($r = 0; $r < 5; $r++) {
            $md .= "| Cell {$s}_{$r}_A | Cell {$s}_{$r}_B | Cell {$s}_{$r}_C |\n";
        }
        $md .= "\n";

        // Blockquote
        $md .= "> This is a blockquote in section $s.\n";
        $md .= "> It spans multiple lines.\n\n";
    }

    return $md;
}

$memBaseline = memory_get_usage(true);

// Test with GFM (tables, strikethrough, etc. — more node types)
$numSections = 2000;
$markdown = generateMarkdown($numSections);
echo "Markdown size: " . round(strlen($markdown) / 1024) . " KB ($numSections sections)\n";

$converter = new GithubFlavoredMarkdownConverter();

$memBeforeParse = memory_get_usage(true);
$html = $converter->convert($markdown);
$memAfterParse = memory_get_usage(true);

echo "HTML size: " . round(strlen((string)$html) / 1024) . " KB\n";
echo "Memory before parse: " . round($memBeforeParse / 1024 / 1024, 2) . " MB\n";
echo "Memory after parse: " . round($memAfterParse / 1024 / 1024, 2) . " MB\n";
echo "Delta: +" . round(($memAfterParse - $memBeforeParse) / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";
echo "Attach: sudo php -d memory_limit=4G $reliRoot/reli inspector:memory -p $pid --output-format=sqlite3 --output=/tmp/reli_commonmark.sqlite3\n";

// Self-analyze with reli-prof (JSON — may OOM on large targets)
$reliRoot = dirname(__DIR__, 3);
$pid = getmypid();
$jsonFile = __DIR__ . '/commonmark_analysis.json';
$cmd = PHP_BINARY . " -d memory_limit=2G " . escapeshellarg("$reliRoot/reli")
    . " inspector:memory -p $pid --no-stop-process"
    . " > " . escapeshellarg($jsonFile) . " 2>" . escapeshellarg(__DIR__ . '/reli_stderr.txt');
system($cmd, $exitCode);

if ($exitCode === 0 && file_exists($jsonFile)) {
    $json = json_decode(file_get_contents($jsonFile), true);
    if ($json) {
        $s = $json['summary'][0];
        echo sprintf("\n=== reli-prof: Heap %.2f MB, analyzed %.1f%% ===\n",
            $s['zend_mm_heap_usage']/1024/1024, $s['heap_memory_analyzed_percentage']);

        $classes = $json['class_objects_summary'];
        uasort($classes, fn($a, $b) => $b['count'] <=> $a['count']);
        echo "\nTOP CLASSES (by count):\n";
        $i = 0;
        foreach ($classes as $c => $info) {
            if ($i++ >= 15) break;
            $short = preg_replace('/^League\\\\CommonMark\\\\/', 'CM\\', $c);
            echo sprintf("  %7d × %-55s %8.1f KB\n", $info['count'], $short, $info['memory_usage']/1024);
        }

        $types = $json['location_types_summary'];
        uasort($types, fn($a, $b) => $b['memory_usage'] <=> $a['memory_usage']);
        echo "\nTOP TYPES:\n";
        $i = 0;
        foreach ($types as $t => $info) {
            if ($i++ >= 5) break;
            echo sprintf("  %7d × %-40s %8.2f MB\n", $info['count'], $t, $info['memory_usage']/1024/1024);
        }
    }
} else {
    echo "reli-prof failed (exit: $exitCode)\n";
}

@unlink($jsonFile);
