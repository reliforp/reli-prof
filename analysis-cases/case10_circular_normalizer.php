<?php
/**
 * Case 10: API Platform Circular Dependency / Normalizer Chain
 * Ref: api-platform/core#5016
 * Root cause: Circular dependency in normalizer chain causes deep object graph.
 */
class NormalizerInterface {}

class SerializerContext {
    public array $attributes = [];
    public array $groups = [];
    public ?string $format = null;
    public array $circularReferenceMap = [];
}

class ItemNormalizer {
    /** @var DataTransformer[] */
    private array $transformers;
    private array $normalizedCache = [];

    public function __construct(array $transformers) {
        $this->transformers = $transformers;
    }

    public function normalize(mixed $data, SerializerContext $ctx): array {
        $result = [];
        foreach ($this->transformers as $transformer) {
            $result = array_merge($result, $transformer->transform($data, $ctx));
        }
        $this->normalizedCache[] = $result;
        return $result;
    }
}

class DataTransformer {
    private ItemNormalizer $normalizer; // Circular: transformer -> normalizer -> transformer
    private string $name;

    public function __construct(string $name) { $this->name = $name; }
    public function setNormalizer(ItemNormalizer $n): void { $this->normalizer = $n; }

    public function transform(mixed $data, SerializerContext $ctx): array {
        return [
            'transformer' => $this->name,
            'data' => is_array($data) ? $data : ['value' => (string)$data],
            'context' => clone $ctx,
        ];
    }
}

// Build circular dependency chain
$t1 = new DataTransformer('json_ld');
$t2 = new DataTransformer('hal');
$t3 = new DataTransformer('jsonapi');
$normalizer = new ItemNormalizer([$t1, $t2, $t3]);
$t1->setNormalizer($normalizer);
$t2->setNormalizer($normalizer);
$t3->setNormalizer($normalizer);

posix_kill(posix_getpid(), SIGSTOP);

for ($i = 0; $i < 3000; $i++) {
    $ctx = new SerializerContext();
    $ctx->attributes = ['id', 'name', 'description'];
    $ctx->groups = ['read', 'item'];
    $ctx->format = 'jsonld';
    $normalizer->normalize(['id' => $i, 'name' => "Item {$i}", 'desc' => str_repeat('x', 300)], $ctx);
}
echo "Memory: " . number_format(memory_get_usage(true)) . "\n";
sleep(300);
