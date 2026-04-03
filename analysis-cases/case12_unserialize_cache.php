<?php
/**
 * Case 12: Unserialize Cache Bloat (HTMLPurifier pattern)
 * Ref: ezyang/htmlpurifier#270
 * Root cause: Repeated unserialize of large cached definitions causes memory bloat.
 */
class CacheDefinition {
    public string $name;
    public array $rules;
    public array $attributes;
    public array $childRules;

    public function __construct(string $name) {
        $this->name = $name;
        $this->rules = [];
        $this->attributes = [];
        $this->childRules = [];
        // Simulate large definition
        for ($i = 0; $i < 50; $i++) {
            $this->rules["rule_{$i}"] = [
                'type' => 'element',
                'allowed_children' => range(0, 20),
                'attributes' => array_fill(0, 10, 'string'),
            ];
            $this->attributes["attr_{$i}"] = ['type' => 'string', 'default' => null, 'required' => false];
        }
    }
}

class DefinitionCache {
    private string $serializedData;
    private array $loadedDefinitions = []; // leak: each unserialize creates new objects

    public function __construct(CacheDefinition $definition) {
        $this->serializedData = serialize($definition);
    }

    public function load(): CacheDefinition {
        // Bug: unserialize creates new objects every time, old ones not freed
        $def = unserialize($this->serializedData);
        $this->loadedDefinitions[] = $def;
        return $def;
    }
}

class HTMLProcessor {
    private DefinitionCache $cache;

    public function __construct(DefinitionCache $cache) { $this->cache = $cache; }

    public function purify(string $html): string {
        $def = $this->cache->load(); // Loads (and leaks) definition each time
        return strip_tags($html, array_keys($def->rules));
    }
}

$cache = new DefinitionCache(new CacheDefinition('HTML'));
$processor = new HTMLProcessor($cache);

posix_kill(posix_getpid(), SIGSTOP);

for ($i = 0; $i < 2000; $i++) {
    $processor->purify("<div class='test'>Content {$i} <b>bold</b> <script>alert(1)</script></div>");
}
echo "Memory: " . number_format(memory_get_usage(true)) . "\n";
sleep(300);
