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

namespace Reli\Inspector\Watch\Daemon\Searcher;

use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Lib\PhpInternals\ZendTypeReader;

/**
 * Extended descriptor for watch daemon that includes CG address.
 */
final class WatchTargetDescriptor
{
    /** @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */
    public function __construct(
        public int $pid,
        public int $eg_address,
        public int $sg_address,
        public int $cg_address,
        public string $php_version,
    ) {
    }

    public function toBaseDescriptor(): TargetProcessDescriptor
    {
        return new TargetProcessDescriptor(
            $this->pid,
            $this->eg_address,
            $this->sg_address,
            $this->php_version,
            $this->cg_address,
        );
    }

    public static function getInvalid(): self
    {
        /** @var ?self $invalid */
        static $invalid = null;
        $invalid ??= new self(0, 0, 0, 0, ZendTypeReader::V80);
        return $invalid;
    }
}
