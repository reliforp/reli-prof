<?php
/**
 * Case 14: Laravel Application Bootstrap Accumulation
 * Ref: laravel/framework#44214
 * Root cause: Bootstrappers accumulate static state across application refreshes in tests.
 */
class ServiceProvider {
    public string $name;
    public array $bindings = [];
    public array $singletons = [];
    private array $bootedCallbacks = [];

    public function __construct(string $name) {
        $this->name = $name;
        // Each provider registers bindings
        for ($i = 0; $i < 5; $i++) {
            $this->bindings["{$name}_binding_{$i}"] = function () use ($name, $i) {
                return new \stdClass(); // Closure captures scope
            };
        }
    }

    public function boot(): void {
        $this->bootedCallbacks[] = function () { /* post-boot callback */ };
    }
}

class Application {
    private array $providers = [];
    private array $bindings = [];
    private array $resolved = [];
    private static array $bootstrappers = []; // Static: persists across flushes

    public function register(ServiceProvider $provider): void {
        $this->providers[] = $provider;
        $this->bindings = array_merge($this->bindings, $provider->bindings);
    }

    public function boot(): void {
        foreach ($this->providers as $provider) {
            $provider->boot();
        }
        self::$bootstrappers[] = $this->providers; // Leak: static accumulation
    }

    public function flush(): void {
        $this->providers = [];
        $this->bindings = [];
        $this->resolved = [];
        // Bug: static $bootstrappers NOT cleared
    }

    public static function getBootstrapCount(): int { return count(self::$bootstrappers); }
}

class ProviderRepository {
    private static ?array $manifest = null;

    public static function loadManifest(): array {
        if (self::$manifest === null) {
            self::$manifest = [];
            $providerNames = [
                'Auth', 'Broadcasting', 'Bus', 'Cache', 'Cookie', 'Database',
                'Encryption', 'Filesystem', 'Foundation', 'Hashing', 'Http',
                'Log', 'Mail', 'Notification', 'Pagination', 'Pipeline',
                'Queue', 'Redis', 'Routing', 'Session', 'Translation', 'Validation', 'View',
            ];
            foreach ($providerNames as $name) {
                self::$manifest[] = ['provider' => $name, 'eager' => true, 'deferred' => []];
            }
        }
        return self::$manifest;
    }
}

posix_kill(posix_getpid(), SIGSTOP);

// Simulate test suite refreshing application 200 times
for ($test = 0; $test < 200; $test++) {
    $app = new Application();
    $manifest = ProviderRepository::loadManifest();
    foreach ($manifest as $entry) {
        $app->register(new ServiceProvider($entry['provider']));
    }
    $app->boot();
    $app->flush();
    // $app goes out of scope but static bootstrappers remain
}
echo "Bootstrap accumulations: " . Application::getBootstrapCount() . "\n";
echo "Memory: " . number_format(memory_get_usage(true)) . "\n";
sleep(300);
