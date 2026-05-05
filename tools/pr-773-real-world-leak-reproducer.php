<?php

declare(strict_types=1);

// Reproducer combining real-world PHP memory anti-patterns harvested from
// open GitHub issues found via:
//
//   https://github.com/search?q=memory_get_usage+&type=issues&s=updated&o=desc&state=open
//   https://github.com/search?q=%22allowed+memory+size+of%22&type=issues&s=updated&o=desc&state=open
//
// Patterns mirrored:
//   - CuyZ/Valinor #800, phalcon/cphalcon #16954: static cache growing
//     unboundedly with closure values that capture scope.
//   - thephpleague/flysystem #1856: iterator/generator retainers (the
//     generator frame holds a live reference to the source array even
//     after the user thinks the iteration is done).
//   - glpi-project/glpi #24118: file payload buffered into memory rather
//     than streamed (large strings).
//   - andrefelipeufcg/matrizpermissoes #3, librenms/librenms #19593:
//     hydrate all rows without pagination (large array-of-objects).
//   - Generic ORM/circular-reference shape: every record points back to
//     a "manager" record, plus a 2-cycle at the root.
//
// Used by docs/internals/pr-773-sqlite-output-validation-round3-real-world.md
// to validate the SQLite output of `inspector:memory -f sqlite3` (and the
// new `inspector:memory:export-sqlite` rmem→sqlite path) against a target
// that exhibits all four shapes simultaneously.
//
// Run with:
//   php tools/pr-773-real-world-leak-reproducer.php &
//   PHP_PID=$!
//   php ./reli inspector:memory -p "$PHP_PID" -f sqlite3 -o /tmp/cap.sqlite3 --no-cache

final class StaticCache
{
    /** @var array<string, \Closure> */
    public static array $cache = [];
    public static int $hits = 0;
}

final class UserRecord
{
    /** @param array<string, bool> $perms */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public array $perms,
        public ?UserRecord $manager = null,
    ) {
    }
}

/** @return array<string, bool> */
function makePerms(int $base): array
{
    $out = [];
    for ($i = 0; $i < 32; $i++) {
        $out["perm_{$base}_{$i}"] = ($base + $i) % 7 === 0;
    }
    return $out;
}

// 1) Hydrate "all users without pagination" — large array-of-objects
$users = [];
for ($i = 0; $i < 4000; $i++) {
    $users[$i] = new UserRecord(
        id: $i,
        name: "user_name_{$i}",
        email: "user_{$i}@example.test",
        perms: makePerms($i),
    );
}
foreach ($users as $u) {
    $u->manager = $users[0];
}
$users[0]->manager = $users[1];

// 2) Static cache growing unboundedly with closures that capture scope
for ($k = 0; $k < 1500; $k++) {
    $payload = str_repeat('x', 256);
    StaticCache::$cache["k_{$k}"] = function () use ($payload, $k) {
        StaticCache::$hits++;
        return $payload . $k;
    };
}

// 3) "File buffered into memory" — couple of large strings
$bigA = str_repeat('A', 1 << 20);
$bigB = str_repeat('B', 1 << 19);
$bigC = json_encode(array_fill(0, 5000, ['k' => 'v', 'n' => 42, 's' => str_repeat('s', 32)]));

// 4) Generator iterator retaining references (flysystem-shape)
$gen = (function () use ($users) {
    foreach ($users as $u) {
        yield $u->id => $u;
    }
})();
$gen->current();

fwrite(STDERR, 'ready pid=' . getmypid() . ' mem=' . memory_get_usage(true) . "\n");
$deadline = microtime(true) + 600.0;
while (microtime(true) < $deadline) {
    usleep(100_000);
}
