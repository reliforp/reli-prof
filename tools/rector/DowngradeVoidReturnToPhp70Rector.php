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

namespace Reli\Tools\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Drop `void` return type (PHP 7.1+).
 *
 * PHP 7.0 has no `void` keyword for return types. The body is unaffected;
 * we just remove the declaration and rely on PHP's natural "no return"
 * behavior (function returns null implicitly).
 */
final class DowngradeVoidReturnToPhp70Rector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Drop void return type for PHP 7.0 compatibility',
            [new CodeSample(
                'public function foo(): void {}',
                'public function foo() {}',
            )],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class, Function_::class, Closure::class, ArrowFunction::class];
    }

    public function refactor(Node $node): ?Node
    {
        $returnType = $node->returnType;
        if (!$returnType instanceof Identifier) {
            return null;
        }
        if ($returnType->toLowerString() !== 'void') {
            return null;
        }

        $node->returnType = null;
        return $node;
    }
}
