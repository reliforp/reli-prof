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

namespace Reli\Inspector\Daemon\Dispatcher;

use Reli\Lib\PhpInternals\ZendTypeReader;

final class TargetProcessDescriptor
{
    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     * @param int $cg_address compiler-globals address, or 0 when not
     *     resolved. Populated by the searcher only when the daemon was
     *     launched with a feature that needs CG (e.g. `inspector:trace
     *     --trace-var` specs that reference `static::` or `func_static::`
     *     scopes). Consumers MUST treat 0 as "not available" and skip
     *     CG-dependent operations.
     */
    public function __construct(
        public int $pid,
        public int $eg_address,
        public int $sg_address,
        public string $php_version,
        public int $cg_address = 0,
    ) {
    }

    public static function getInvalid(): self
    {
        /** @var ?self $invalid */
        static $invalid = null;
        $invalid ??= new self(0, 0, 0, ZendTypeReader::V80);
        return $invalid;
    }
}
