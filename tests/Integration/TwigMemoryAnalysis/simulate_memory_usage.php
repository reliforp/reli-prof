<?php
/**
 * Analyze Twig template engine memory usage.
 *
 * Twig compiles templates into PHP classes and caches them.
 * Each template becomes a compiled class with nodes, tokens, etc.
 * This tests memory with many unique templates rendered in a loop.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\ArrayLoader;

$pid = getmypid();
echo "PID: $pid\n\n";

$memBaseline = memory_get_usage(true);

// Create many unique templates
$numTemplates = 5000;
$templates = [];
for ($i = 0; $i < $numTemplates; $i++) {
    $templates["template_$i.html"] = "
<div class=\"section-$i\">
    <h2>{{ title_$i }}</h2>
    <p>{{ description_$i }}</p>
    {% for item in items_$i %}
        <span class=\"item\">{{ item.name }} - {{ item.value }}</span>
        {% if item.active %}
            <strong>Active</strong>
        {% endif %}
    {% endfor %}
    {% if show_footer_$i %}
        <footer>{{ footer_text_$i }}</footer>
    {% endif %}
</div>";
}

$loader = new ArrayLoader($templates);
$twig = new Environment($loader, ['cache' => false, 'auto_reload' => false]);

echo "Created $numTemplates templates\n";
echo "Memory after template creation: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n\n";

// Render all templates to force compilation
echo "Rendering all templates...\n";
for ($i = 0; $i < $numTemplates; $i++) {
    $context = [
        "title_$i" => "Title $i",
        "description_$i" => "Description for item $i with some extra text",
        "items_$i" => array_map(fn($j) => [
            'name' => "Item $j",
            'value' => rand(1, 1000),
            'active' => $j % 2 === 0,
        ], range(0, 4)),
        "show_footer_$i" => $i % 3 === 0,
        "footer_text_$i" => "Footer $i",
    ];
    $twig->render("template_$i.html", $context);

    if (($i + 1) % 1000 === 0) {
        echo "  Rendered " . ($i + 1) . ": " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
    }
}

$memAfter = memory_get_usage(true);
echo "\nFinal: " . round($memAfter / 1024 / 1024, 2) . " MB\n";
echo "Delta: +" . round(($memAfter - $memBaseline) / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "Per template: ~" . round(($memAfter - $memBaseline) / $numTemplates) . " bytes\n";

echo "\nREADY_FOR_ANALYSIS\n";
// Keep alive for external reli-prof analysis
$stdin = fopen('php://stdin', 'r');
echo "Press Enter to exit (or wait for reli-prof to finish)...\n";
stream_set_blocking($stdin, false);
for ($wait = 0; $wait < 120; $wait++) {
    if (fgets($stdin)) break;
    sleep(1);
}
