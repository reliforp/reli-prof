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
    public const int ZEND_ACC_CLOSURE = 0x100000;
    public const int ZEND_ACC_HAS_RETURN_TYPE = 0x40000000;

    public const int ZEND_CALL_CODE = (1 << 24);

    public const int ZEND_CALL_TOP = (1 << 25);

    // 7.0 packs call_info with ZEND_CALL_INFO_SHIFT 24: ZEND_CALL_CLOSURE
    // (1 << 5) lands at bit 29. ZEND_CALL_HAS_SYMBOL_TABLE did not exist yet
    // (added in 7.1) and named params are 8.0+, so both are pinned to 0.
    public const int ZEND_CALL_CLOSURE = (1 << 29);

    public const int ZEND_CALL_HAS_SYMBOL_TABLE = 0;

    public const int ZEND_CALL_HAS_EXTRA_NAMED_PARAMS = 0;
}
