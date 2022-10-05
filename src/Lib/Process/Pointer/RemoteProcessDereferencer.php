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

namespace Reli\Lib\Process\Pointer;

use Reli\Lib\FFI\CastedTypeProvider;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\ProcessSpecifier;

class RemoteProcessDereferencer implements Dereferencer
{
    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ProcessSpecifier $process_specifier,
        private CastedTypeProvider $ctype_provider,
    ) {
    }

    /**
     * @template T of Dereferencable
     * @param Pointer<T> $pointer
     * @return T
     */
    public function deref(Pointer $pointer): mixed
    {
        $buffer = $this->memory_reader->read(
            $this->process_specifier->pid,
            $pointer->address,
            $pointer->size
        );
        $casted_cdata = $this->ctype_provider->readAs(
            $pointer->getCTypeName(),
            $buffer
        );

        return $pointer->fromCastedCData($casted_cdata, $pointer);
    }
}
