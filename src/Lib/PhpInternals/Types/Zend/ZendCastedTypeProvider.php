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

namespace Reli\Lib\PhpInternals\Types\Zend;

use FFI\CData;
use Reli\Lib\FFI\CastedTypeProvider;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;

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
