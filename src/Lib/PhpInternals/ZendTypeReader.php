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
use PhpProfiler\Lib\FFI\CannotAllocateBufferException;
use PhpProfiler\Lib\FFI\CannotCastCDataException;
use PhpProfiler\Lib\FFI\CannotGetTypeForCDataException;
use PhpProfiler\Lib\FFI\CannotLoadCHeaderException;
use Webmozart\Assert\Assert;

final class ZendTypeReader
{
    public const V70 = 'v70';
    public const V71 = 'v71';
    public const V72 = 'v72';
    public const V73 = 'v73';
    public const V74 = 'v74';
    public const V80 = 'v80';
    public const V81 = 'v81';

    public const ALL_SUPPORTED_VERSIONS = [
        self::V70,
        self::V71,
        self::V72,
        self::V73,
        self::V74,
        self::V80,
        self::V81,
    ];

    private ?FFI $ffi = null;

    /** @return value-of<self::ALL_SUPPORTED_VERSIONS> */
    public static function defaultVersion(): string
    {
        $version_string = join(
            '',
            [
                'v',
                PHP_MAJOR_VERSION,
                PHP_MINOR_VERSION,
            ]
        );
        Assert::true(self::isSupported($version_string));
        /** @var value-of<self::ALL_SUPPORTED_VERSIONS> */
        return $version_string;
    }

    /**
     * @param string $version_string
     * @assert-if-true value-of<self::ALL_SUPPORTED_VERSIONS> $version_string
     * @return bool
     */
    public static function isSupported(string $version_string): bool
    {
        return in_array($version_string, self::ALL_SUPPORTED_VERSIONS, true);
    }

    /**
     * @param value-of<self::ALL_SUPPORTED_VERSIONS> $php_version
     */
    public function __construct(
        private string $php_version
    ) {
    }

    private function loadHeader(string $php_version): FFI
    {
        if (!isset($this->ffi)) {
            if ($php_version === self::V81) {
                $php_version = self::V80;
            }
            $this->ffi = FFI::load(__DIR__ . "/Headers/{$php_version}.h")
                ?? throw new CannotLoadCHeaderException('cannot load headers for zend engine');
        }
        return $this->ffi;
    }

    public function readAs(string $type, CData $cdata): CastedCData
    {
        $ffi = $this->loadHeader($this->php_version);
        return new CastedCData(
            $cdata,
            $ffi->cast($type, $cdata) ?? throw new CannotCastCDataException(
                'cannot cast a C Data'
            ),
        );
    }

    /** @var array<string, int> $sizeof_cache */
    private array $sizeof_cache = [];

    public function sizeOf(string $type): int
    {
        if (!isset($this->sizeof_cache[$type])) {
            $ffi = $this->loadHeader($this->php_version);
            $cdata_type = $ffi->type($type)
                ?? throw new CannotGetTypeForCDataException(
                    message: 'cannot get type for a C Data',
                    type: $type
                );
            $this->sizeof_cache[$type] = FFI::sizeof($cdata_type);
        }
        return $this->sizeof_cache[$type];
    }

    /** @var array<string, array<string, array{int, int}>> $offset_cache */
    private array $offset_cache = [];

    /** @return array{int, int} */
    public function getOffsetAndSizeOfMember(string $type, string $member): array
    {
        if (!isset($this->offset_cache[$type][$member])) {
            $ffi = $this->loadHeader($this->php_version);
            $dummy = $ffi->new($type);
            if (is_null($dummy)) {
                throw new CannotAllocateBufferException(
                    message: sprintf(
                        'cannot allocate buffer for calculating the offset of %s in %s',
                        $member,
                        $type
                    )
                );
            }
            /** @var CData $dummy_member */
            $dummy_member = $dummy->$member;
            /** @var FFI\CInteger $member_addr_cdata */
            $member_addr_cdata = \FFI::cast('long', FFI::addr($dummy_member));
            $member_addr = $member_addr_cdata->cdata;
            /** @var FFI\CInteger $dummy_base_addr */
            $dummy_base_addr = \FFI::cast('long', FFI::addr($dummy));
            $addr = $member_addr - $dummy_base_addr->cdata;
            $this->offset_cache[$type][$member] = [
                $addr,
                \FFI::sizeof($dummy_member)
            ];
        }
        return $this->offset_cache[$type][$member];
    }
}
