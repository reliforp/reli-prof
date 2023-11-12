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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayContextPool;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectContextPool;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\PhpReferenceContextPool;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ResourceContextPool;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\StringContextPool;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\UserFunctionDefinitionContextPool;

final class ContextPools
{
    public function __construct(
        public StringContextPool $string_context_pool,
        public ArrayContextPool $array_context_pool,
        public ObjectContextPool $object_context_pool,
        public PhpReferenceContextPool $php_reference_context_pool,
        public ResourceContextPool $resource_context_pool,
        public UserFunctionDefinitionContextPool $user_function_definition_context_pool,
    ) {
    }

    public static function createDefault(): self
    {
        return new self(
            new StringContextPool(),
            new ArrayContextPool(),
            new ObjectContextPool(),
            new PhpReferenceContextPool(),
            new ResourceContextPool(),
            new UserFunctionDefinitionContextPool(),
        );
    }
}
