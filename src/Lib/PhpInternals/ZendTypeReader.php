<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\PhpInternals;

use FFI;
use FFI\CData;

/**
 * Class ZendTypeReader
 * @package PhpProfiler\Lib\PhpInternals
 */
final class ZendTypeReader
{
    public const V74 = 'v74';
    public const V80 = 'v80';
    private string $php_version;
    private ?FFI $ffi = null;

    /**
     * ZendTypeReader constructor.
     * @param string $php_version
     */
    public function __construct(string $php_version)
    {
        $this->php_version = $php_version;
    }

    /**
     * @param string $php_version
     * @return FFI
     */
    private function loadHeader(string $php_version): FFI
    {
        if (!isset($this->ffi)) {
            $this->ffi = FFI::load(__DIR__ . "/Headers/{$php_version}.h");
        }
        return $this->ffi;
    }

    /**
     * @param string $type
     * @param CData $cdata
     * @return CData
     */
    public function readAs(string $type, CData $cdata): CData
    {
        $ffi = $this->loadHeader($this->php_version);
        return $ffi->cast($type, $cdata);
    }

    /**
     * @param string $type
     * @return int
     */
    public function sizeOf(string $type): int
    {
        $ffi = $this->loadHeader($this->php_version);
        $type = $ffi->type($type);
        return FFI::sizeof($type);
    }
}
