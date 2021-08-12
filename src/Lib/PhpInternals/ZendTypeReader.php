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

namespace PhpProfiler\Lib\PhpInternals;

use FFI;
use FFI\CData;

/**
 * Class ZendTypeReader
 * @package PhpProfiler\Lib\PhpInternals
 */
final class ZendTypeReader
{
    public const V70 = 'v70';
    public const V71 = 'v71';
    public const V72 = 'v72';
    public const V73 = 'v73';
    public const V74 = 'v74';
    public const V80 = 'v80';

    public const ALL_SUPPORTED_VERSIONS = [
        self::V70,
        self::V71,
        self::V72,
        self::V73,
        self::V74,
        self::V80,
    ];

    private ?FFI $ffi = null;

    /**
     * ZendTypeReader constructor.
     * @param value-of<self::ALL_SUPPORTED_VERSIONS> $php_version
     */
    public function __construct(
        private string $php_version
    ) {
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
     * @return ZendTypeCData
     */
    public function readAs(string $type, CData $cdata): ZendTypeCData
    {
        $ffi = $this->loadHeader($this->php_version);
        return new ZendTypeCData($cdata, $ffi->cast($type, $cdata));
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
