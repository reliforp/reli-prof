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

namespace Reli\Lib\PhpInternals\Types\Zend\V74;

use Reli\Lib\PhpInternals\Types\Zend\Zval as BaseZval;
use Reli\Lib\Process\Pointer\Dereferencable;

final class Zval extends BaseZval implements Dereferencable
{
    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'u1' => $this->u1 = new ZvalU1($this->casted_cdata->casted->u1),
            default => parent::__get($field_name),
        };
    }
}
