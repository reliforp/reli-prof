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

final class PhpInternalsConstantsV70 extends VersionAwareConstants
{
    public const ZEND_ACC_CLOSURE = 0x100000;
    public const ZEND_ACC_HAS_RETURN_TYPE = 0x40000000;

    public const ZEND_CALL_CODE = (1 << 24);

    public const ZEND_CALL_TOP = (1 << 25);
}
