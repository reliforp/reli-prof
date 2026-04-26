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

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Strip class constant visibility modifiers (PHP 7.1+).
 *
 * `private const X` / `protected const X` / `public const X` all become a bare
 * `const X` for PHP 7.0, which historically only had implicitly-public class
 * constants. Effective visibility loosens for private/protected constants, but
 * that is a hidden access leak, not a runtime fault — and for an FFI-free
 * embeddable client this trade-off is acceptable.
 */
final class DowngradeConstVisibilityToPhp70Rector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Strip class constant visibility for PHP 7.0 compatibility',
            [new CodeSample(
                'private const FOO = 1;',
                'const FOO = 1;',
            )],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassConst::class];
    }

    public function refactor(Node $node): ?Node
    {
        $visibilityFlags = Modifiers::PUBLIC | Modifiers::PROTECTED | Modifiers::PRIVATE;
        if (($node->flags & $visibilityFlags) === 0) {
            return null;
        }

        $node->flags &= ~$visibilityFlags;
        return $node;
    }
}
