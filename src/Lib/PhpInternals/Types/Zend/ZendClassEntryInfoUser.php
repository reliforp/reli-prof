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
use Reli\Lib\Process\Pointer\Pointer;

class ZendClassEntryInfoUser
{
    /** @var Pointer<ZendString>|null  */
    public ?Pointer $filename;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $line_start;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $line_end;

    /** @var Pointer<ZendString>|null  */
    public ?Pointer $doc_comment;

    /**
     * @param \FFI\PhpInternals\zend_class_entry_info_user $cdata
     */
    public function __construct(
        private CData $cdata,
    ) {
        unset($this->filename);
        unset($this->line_start);
        unset($this->line_end);
        unset($this->doc_comment);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'filename' => $this->filename = $this->cdata->filename !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->cdata->filename
                )
                : null
            ,
            'line_start' => $this->line_start = $this->cdata->line_start,
            'line_end' => $this->line_end = $this->cdata->line_end,
            'doc_comment' => $this->doc_comment = $this->cdata->doc_comment !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->cdata->doc_comment
                )
                : null
            ,
        };
    }
}
