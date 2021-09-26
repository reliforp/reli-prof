<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Libc\Unistd;

use FFI\CInteger;

class Execvp
{
    /** @var \FFI\Libc\execvp_ffi */
    private \FFI $ffi;

    public function __construct()
    {
        /** @var \FFI\Libc\execvp_ffi */
        $this->ffi = \FFI::cdef('
            int execvp(const char *file, char *const argv[]);
       ', 'libc.so.6');
    }

    /** @param list<string> $argv */
    public function execvp(string $file, array $argv): int
    {
        /** @var CInteger $zero */
        $zero = \FFI::new('long', false, true);
        $zero->cdata = 0;
        $null = \FFI::cast('void *', $zero);

        $args = [$file, ...$argv];
        $size = \count($args) + 1;
        /** @var \FFI\CArray $argv_real */
        $argv_real = \FFI::new('char *[' . $size . ']', false, true);
        foreach ($args as $key => $item) {
            $item_len = strlen($item);
            $item_len_nul = $item_len + 1;
            /** @var \FFI\CArray $argv_item */
            $argv_item = \FFI::new("char[{$item_len_nul}]", false, true);
            \FFI::memcpy($argv_item, $item, $item_len);
            $argv_item[$item_len] = "\0";
            $argv_real[$key] = $argv_item;
        }
        $argv_real[$key + 1] = $null;
        return $this->ffi->execvp($file, $argv_real);
    }
}
