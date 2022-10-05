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

namespace PhpProfiler\Lib\PhpInternals\Types\Zend;

use FFI\CData;
use PhpProfiler\Lib\FFI\CastedTypeProvider;
use PhpProfiler\Lib\PhpInternals\CastedCData;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;

class ZendCastedTypeProvider implements CastedTypeProvider
{
    public function __construct(
        private ZendTypeReader $zend_type_reader,
    ) {
    }

    public function readAs(string $ctype_name, CData $buffer): CastedCData
    {
        /** @var CastedCData<CData> */
        return $this->zend_type_reader->readAs($ctype_name, $buffer);
    }
}
