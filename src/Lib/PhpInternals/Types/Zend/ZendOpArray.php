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
use FFI\PhpInternals\zend_op_array;
use PhpProfiler\Lib\Process\Pointer\Dereferencer;
use PhpProfiler\Lib\Process\Pointer\Pointer;

class ZendOpArray
{
    /**
     * @var Pointer<ZendString>|null
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public ?Pointer $filename;

    /** @param zend_op_array $cdata */
    public function __construct(
        private CData $cdata,
    ) {
        unset($this->filename);
    }

    public function __get(string $field_name)
    {
        return match ($field_name) {
            'filename' => $this->filename = $this->cdata->filename !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->cdata->filename,
                )
                : null
            ,
        };
    }

    public function getFileName(Dereferencer $dereferencer): ?string
    {
        if (is_null($this->filename)) {
            return null;
        }
        $filename = $dereferencer->deref($this->filename);
        return (string)$dereferencer->deref(
            $filename->getValuePointer($this->filename)
        );
    }
}
