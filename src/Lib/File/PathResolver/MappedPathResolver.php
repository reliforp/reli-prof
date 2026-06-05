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

namespace Reli\Lib\File\PathResolver;

final class MappedPathResolver implements ProcessPathResolver
{
    /** @param array<string, string> $path_map */
    public function __construct(
        private array $path_map,
    ) {
        foreach ($this->path_map as $key => $value) {
            if ($key === '') {
                throw new \LogicException('empty key "' . $key . '" on path map is not allowed');
            }
            if ($key[0] !== '/') {
                throw new \LogicException('key "' . $key . '" on path map must start with "/"');
            }
        }
    }

    #[\Override]
    public function resolve(int $pid, string $path): string
    {
        if (isset($this->path_map[$path])) {
            return $this->path_map[$path];
        }
        $original_path = $path;
        while ($path !== '/') {
            $parent = $this->getDirectory($path);
            // dirname() reduces an absolute path towards '/', but a relative
            // or empty path collapses to '.' and then stays '.' forever. Stop
            // as soon as it stops making progress so a non-absolute path (e.g.
            // a garbled link-map entry) can never spin this loop indefinitely.
            if ($parent === $path) {
                break;
            }
            $path = $parent;
            if ($path === '/') {
                break;
            }
            if (isset($this->path_map[$path])) {
                return $this->path_map[$path] . \substr($original_path, \strlen($path));
            }
        }
        if (isset($this->path_map['/'])) {
            return $this->path_map['/'] . $original_path;
        }
        return $original_path;
    }

    private function getDirectory(string $path): string
    {
        $dir = \dirname($path);
        return $dir;
    }
}
