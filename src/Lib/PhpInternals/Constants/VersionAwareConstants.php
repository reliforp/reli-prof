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
    public const ZEND_ACC_HAS_RETURN_TYPE = (1 << 13);

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
        });
    }
}
