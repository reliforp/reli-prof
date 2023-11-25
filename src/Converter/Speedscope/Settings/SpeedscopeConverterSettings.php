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

namespace Reli\Converter\Speedscope\Settings;

final class SpeedscopeConverterSettings
{
    public function __construct(
        public Utf8ErrorHandlingType $utf8_error_handling_type,
    ) {
    }
}
