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

final class PhpInternalsConstantsV72 extends VersionAwareConstants
{
    public const int ZEND_ACC_CLOSURE = 0x100000;
    public const int ZEND_ACC_HAS_RETURN_TYPE = 0x40000000;

    // 7.1-7.3: ZEND_CALL_CLOSURE (1 << 5) at bit 21 (moved to 22 in 7.4);
    // HAS_SYMBOL_TABLE (bit 20) matches the base; named params are 8.0+.
    public const int ZEND_CALL_CLOSURE = (1 << 21);

    public const int ZEND_CALL_HAS_EXTRA_NAMED_PARAMS = 0;
}
