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
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Downgrade `?T` nullable type hints (PHP 7.1+) to PHP 7.0 form.
 *
 * Parameters:
 *   ?T $x = null  ->  T $x = null            (default already null; just drop ?)
 *   ?T $x         ->  T $x = null            (add null default to keep nullability)
 *   ?T $x = 5     ->  $x = 5                 (drop type entirely; non-null default
 *                                             would lose nullability with T = 5 in 7.0)
 *
 * Return types:
 *   function foo(): ?T    ->  function foo()   (PHP 7.0 has no nullable return form)
 *
 * The official Rector downgrade level set stops at PHP 7.1, so the 7.1 -> 7.0
 * gap is filled by this rule and its siblings. See rector-sidecar-client.php.
 */
final class DowngradeNullableTypeToPhp70Rector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Strip ?T nullable types for PHP 7.0 compatibility',
            [new CodeSample(
                'public function foo(?string $x): ?int { return null; }',
                'public function foo(string $x = null) { return null; }',
            )],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [
            Param::class,
            ClassMethod::class,
            Function_::class,
            Closure::class,
            ArrowFunction::class,
        ];
    }

    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Param) {
            return $this->refactorParam($node);
        }

        return $this->refactorReturn($node);
    }

    private function refactorParam(Param $param): ?Param
    {
        if (!$param->type instanceof NullableType) {
            return null;
        }

        $innerType = $param->type->type;

        if ($param->default === null) {
            $param->type = $innerType;
            $param->default = new ConstFetch(new Name('null'));
            return $param;
        }

        if ($this->isNullDefault($param->default)) {
            $param->type = $innerType;
            return $param;
        }

        $param->type = null;
        return $param;
    }

    /**
     * @param ClassMethod|Function_|Closure|ArrowFunction $node
     */
    private function refactorReturn(Node $node): ?Node
    {
        if (!$node->returnType instanceof NullableType) {
            return null;
        }

        $node->returnType = null;
        return $node;
    }

    private function isNullDefault(Node $default): bool
    {
        return $default instanceof ConstFetch
            && strtolower($default->name->toString()) === 'null';
    }
}
