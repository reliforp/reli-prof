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

final class PhpInternalsConstantsV74 extends VersionAwareConstants
{
    public const int ZEND_ACC_CLOSURE = (1 << 20);
    public const int ZEND_ACC_HAS_RETURN_TYPE = (1 << 13);

    // 7.4 already uses the modern call_info numbering (ZEND_CALL_CLOSURE at
    // bit 22, HAS_SYMBOL_TABLE at bit 20, both inherited), but named params
    // are still 8.0+, so HAS_EXTRA_NAMED_PARAMS is pinned to 0.
    public const int ZEND_CALL_HAS_EXTRA_NAMED_PARAMS = 0;
}
