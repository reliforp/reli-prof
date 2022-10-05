<?php

/**
 * This file is part of the sj-i/ package.
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
    /** @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */
    public function __construct(
        public int $pid,
        public int $eg_address,
        public int $sg_address,
        public string $php_version,
    ) {
    }

    public static function getInvalid(): self
    {
        static $invalid = null;
        /** @var self */
        $invalid ??= new self(0, 0, 0, ZendTypeReader::V80);
        return $invalid;
    }
}
