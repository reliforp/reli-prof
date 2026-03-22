<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Reli\Lib\Session;

use Reli\Lib\Session\Attribute\SessionProtocol;

final class SessionEnumDiscoverer
{
    /**
     * Discover all enums annotated with #[SessionProtocol] under the given directory.
     *
     * @return list<class-string<\UnitEnum>>
     */
    public static function discover(string $srcDir): array
    {
        $enums = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            if (!str_contains($content, 'SessionProtocol')) {
                continue;
            }

            $className = self::extractFullyQualifiedName($content);
            if ($className === null) {
                continue;
            }

            if (!enum_exists($className)) {
                continue;
            }

            $ref = new \ReflectionEnum($className);
            if ($ref->getAttributes(SessionProtocol::class) !== []) {
                $enums[] = $className;
            }
        }

        sort($enums);
        return $enums;
    }

    /**
     * @return class-string|null
     */
    private static function extractFullyQualifiedName(string $content): ?string
    {
        $namespace = null;
        if (preg_match('/^namespace\s+([^\s;]+)/m', $content, $m)) {
            $namespace = $m[1];
        }

        if (preg_match('/^(?:enum|class|interface)\s+(\w+)/m', $content, $m)) {
            $name = $m[1];
            /** @var class-string */
            return $namespace !== null ? "{$namespace}\\{$name}" : $name;
        }

        return null;
    }
}
