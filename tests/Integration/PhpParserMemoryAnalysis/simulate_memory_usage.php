<?php
/**
 * Analyze nikic/PHP-Parser AST memory usage.
 *
 * PHP-Parser creates a Node for every element in the source code.
 * Large files can create massive ASTs.
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\NodeDumper;

$pid = getmypid();
echo "PID: $pid\n\n";

// Generate a large PHP file
function generateLargePhpCode(int $numClasses, int $methodsPerClass): string {
    $code = "<?php\n\n";
    for ($c = 0; $c < $numClasses; $c++) {
        $code .= "class TestClass_{$c}\n{\n";
        for ($p = 0; $p < 5; $p++) {
            $code .= "    private string \$prop_{$p} = 'default_{$p}';\n";
        }
        for ($m = 0; $m < $methodsPerClass; $m++) {
            $code .= "\n    public function method_{$m}(int \$arg_{$m}): string\n    {\n";
            $code .= "        \$result = '';\n";
            $code .= "        for (\$i = 0; \$i < \$arg_{$m}; \$i++) {\n";
            $code .= "            \$result .= \"item_{\$i}_\" . \$this->prop_0;\n";
            $code .= "            if (\$i > 10) {\n";
            $code .= "                return substr(\$result, 0, 100);\n";
            $code .= "            }\n";
            $code .= "        }\n";
            $code .= "        return \$result;\n";
            $code .= "    }\n";
        }
        $code .= "}\n\n";
    }
    return $code;
}

$memBaseline = memory_get_usage(true);

$numClasses = 200;
$methodsPerClass = 20;
$code = generateLargePhpCode($numClasses, $methodsPerClass);
echo "PHP code size: " . round(strlen($code) / 1024) . " KB ({$numClasses} classes × {$methodsPerClass} methods)\n";
echo "Memory for code string: " . round((memory_get_usage(true) - $memBaseline) / 1024 / 1024, 2) . " MB\n\n";

$parser = (new ParserFactory)->createForNewestSupportedVersion();

$memBeforeParse = memory_get_usage(true);
$ast = $parser->parse($code);
$memAfterParse = memory_get_usage(true);

echo "AST nodes: " . countNodes($ast) . "\n";
echo "Memory before parse: " . round($memBeforeParse / 1024 / 1024, 2) . " MB\n";
echo "Memory after parse: " . round($memAfterParse / 1024 / 1024, 2) . " MB\n";
echo "Delta (AST): +" . round(($memAfterParse - $memBeforeParse) / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

function countNodes($nodes): int {
    $count = 0;
    foreach ($nodes as $node) {
        if ($node instanceof PhpParser\Node) {
            $count++;
            foreach ($node->getSubNodeNames() as $name) {
                $sub = $node->$name;
                if (is_array($sub)) {
                    $count += countNodes($sub);
                } elseif ($sub instanceof PhpParser\Node) {
                    $count += countNodes([$sub]);
                }
            }
        }
    }
    return $count;
}

echo "\nREADY_FOR_ANALYSIS\n";

// Self-diagnose
$reliRoot = dirname(__DIR__, 3);
$jsonFile = '/tmp/reli_phpparser_self.json';
$cmd = PHP_BINARY . " -d memory_limit=4G " . escapeshellarg("$reliRoot/reli")
    . " inspector:memory -p " . getmypid() . " --no-stop-process"
    . " > " . escapeshellarg($jsonFile) . " 2>/dev/null";
system($cmd, $exitCode);

ini_set('memory_limit', '4G');
if ($exitCode === 0 && file_exists($jsonFile) && filesize($jsonFile) > 100) {
    $json = json_decode(file_get_contents($jsonFile), true);
    if ($json) {
        $s = $json['summary'][0];
        echo sprintf("\nHeap: %.2f MB, analyzed: %.1f%%\n",
            $s['zend_mm_heap_usage']/1024/1024, $s['heap_memory_analyzed_percentage']);
        $types = $json['location_types_summary'];
        uasort($types, fn($a, $b) => $b['memory_usage'] <=> $a['memory_usage']);
        echo "\nTYPES:\n";
        foreach (array_slice($types, 0, 8, true) as $t => $i) {
            echo sprintf("  %7d × %-40s %8.2f MB\n", $i['count'], $t, $i['memory_usage']/1024/1024);
        }
        $classes = $json['class_objects_summary'];
        uasort($classes, fn($a, $b) => $b['count'] <=> $a['count']);
        echo "\nCLASSES (by count):\n";
        $idx = 0;
        foreach ($classes as $c => $i) {
            if ($idx++ >= 15) break;
            $short = preg_replace('/^PhpParser\\\\/', 'PP\\', $c);
            echo sprintf("  %7d × %-50s %8.1f KB\n", $i['count'], $short, $i['memory_usage']/1024);
        }
    }
} else {
    echo "reli-prof self-diagnosis failed (exit: $exitCode)\n";
    // Fallback: at least show top-level info
}
@unlink($jsonFile);
