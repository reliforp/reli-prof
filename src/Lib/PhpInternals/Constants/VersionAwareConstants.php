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

namespace Reli\Lib\PhpInternals\Constants;

use Reli\Lib\PhpInternals\ZendTypeReader;

abstract class VersionAwareConstants
{
    /** @var int */
    public const int ZEND_ACC_CLOSURE = (1 << 22);

    /** @var int */
    public const int ZEND_ACC_HAS_RETURN_TYPE = (1 << 13);

    /** @var int */
    public const int ZEND_CALL_CODE = (1 << 16);

    /** @var int */
    public const int ZEND_CALL_TOP = (1 << 17);

    /** @var int */
    public const int ZEND_CALL_GENERATOR = (1 << 24);

    /*
     * Effective (post-ZEND_CALL_INFO_SHIFT) call_info bits as stored in
     * `execute_data->This.u1.type_info`. These are the PHP 7.4+ values; the
     * high bits moved across versions (7.0 used ZEND_CALL_INFO_SHIFT 24, not
     * 16, and the call_info bit numbers were renumbered at 7.4), so the
     * 7.0-7.3 differences live in the per-version subclasses.
     */

    /** @var int */
    public const int ZEND_CALL_HAS_SYMBOL_TABLE = (1 << 20);

    /** @var int */
    public const int ZEND_CALL_CLOSURE = (1 << 22);

    /**
     * Named arguments are a PHP 8.0 feature; the flag does not exist before
     * 8.0, so every pre-8.0 subclass pins this to 0 (so `& flag` never
     * matches a 7.x call_info bit that happens to share the position).
     *
     * @var int
     */
    public const int ZEND_CALL_HAS_EXTRA_NAMED_PARAMS = (1 << 27);

    /** @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */
    public static function getConstantsOfVersion(string $php_version): self
    {
        return new (match ($php_version) {
            ZendTypeReader::V70 => PhpInternalsConstantsV70::class,
            ZendTypeReader::V71 => PhpInternalsConstantsV71::class,
            ZendTypeReader::V72 => PhpInternalsConstantsV72::class,
            ZendTypeReader::V73 => PhpInternalsConstantsV73::class,
            ZendTypeReader::V74 => PhpInternalsConstantsV74::class,
            ZendTypeReader::V80 => PhpInternalsConstantsV80::class,
            ZendTypeReader::V81 => PhpInternalsConstantsV81::class,
            ZendTypeReader::V82 => PhpInternalsConstantsV82::class,
            ZendTypeReader::V83 => PhpInternalsConstantsV83::class,
            ZendTypeReader::V84 => PhpInternalsConstantsV84::class,
            ZendTypeReader::V85 => PhpInternalsConstantsV85::class,
        });
    }
}
