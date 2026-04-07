<?php
/**
 * Case 8: Doctrine ORM Entity Manager Identity Map Leak
 * Ref: doctrine/orm#8891
 * Root cause: EntityManager identity map + SQL logger retain references despite clear().
 */
class Entity {
    public function __construct(
        public int $id,
        public string $name,
        public string $data,
        public array $metadata = [],
    ) {}
}

class SQLLogger {
    public array $queries = []; // Never cleared
    public function log(string $sql, array $params): void {
        $this->queries[] = ['sql' => $sql, 'params' => $params, 'time' => microtime(true)];
    }
}

class IdentityMap {
    /** @var array<string, array<int, Entity>> */
    private array $map = [];

    public function put(string $class, Entity $entity): void {
        $this->map[$class][$entity->id] = $entity;
    }
    public function get(string $class, int $id): ?Entity {
        return $this->map[$class][$id] ?? null;
    }
    public function clear(): void {
        $this->map = [];
    }
    public function count(): int {
        $c = 0;
        foreach ($this->map as $entities) $c += count($entities);
        return $c;
    }
}

class EntityManager {
    private IdentityMap $identityMap;
    private SQLLogger $logger;
    private array $unitOfWork = []; // Tracks changes

    public function __construct() {
        $this->identityMap = new IdentityMap();
        $this->logger = new SQLLogger();
    }

    public function find(string $class, int $id): Entity {
        $cached = $this->identityMap->get($class, $id);
        if ($cached) return $cached;

        $this->logger->log("SELECT * FROM entities WHERE id = ?", [$id]);
        $entity = new Entity($id, "Entity_{$id}", str_repeat("data_{$id}_", 50), [
            'created_at' => date('Y-m-d'), 'tags' => range(0, 10),
        ]);
        $this->identityMap->put($class, $entity);
        $this->unitOfWork[$class][$id] = 'managed';
        return $entity;
    }

    public function clear(): void {
        $this->identityMap->clear();
        $this->unitOfWork = [];
        // Bug: SQLLogger is NOT cleared
    }

    public function getQueryCount(): int { return count($this->logger->queries); }
}

$em = new EntityManager();

posix_kill(posix_getpid(), SIGSTOP);

for ($batch = 0; $batch < 50; $batch++) {
    for ($i = 0; $i < 200; $i++) {
        $id = $batch * 200 + $i;
        $em->find(Entity::class, $id);
    }
    $em->clear(); // Clears identity map but not logger
}
echo "Queries logged: " . $em->getQueryCount() . "\n";
echo "Memory: " . number_format(memory_get_usage(true)) . "\n";
sleep(300);
