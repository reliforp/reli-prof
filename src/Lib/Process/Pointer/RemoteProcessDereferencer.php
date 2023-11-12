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

use FFI\CData;
use Reli\Lib\FFI\CastedTypeProvider;
use Reli\Lib\PhpInternals\CastedCData;
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
            $pointer->getCTypeNameOfType(),
            $buffer
        );

        return $this->fromCastedCDataOfType(
            $casted_cdata,
            $pointer
        );
    }

    /**
     * @template T of Dereferencable
     * @param CastedCData<CData> $casted_cdata
     * @param Pointer<T> $pointer
     * @return T
     */
    public function fromCastedCDataOfType(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): mixed {
        $type = $pointer->type;
        return $type::fromCastedCData($casted_cdata, $pointer);
    }
}
