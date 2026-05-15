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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext;

final class DefinedClassesContext implements ReferenceContext
{
    use ReferenceContextDefault;

    /** Number of class buckets the EmitClassTableJob walk skipped
     *  because deref / collectClassDefinition threw an exception
     *  on them (per-bucket error isolation swallows the throwable).
     *  Surfaced as the `#dropped_exceptions` attribute so the
     *  asymmetry it causes — some classes silently absent from the
     *  class_table tree, breaking RmemModel::findClassDef() and
     *  the `⇒ class` pseudo-edges — is visible from rmem:explore. */
    private int $droppedExceptions = 0;

    /** Number of class buckets skipped because their memory address
     *  was already registered (dedup hit at line 97 of
     *  EmitClassTableJob). Should normally be zero — flagged so we
     *  can spot pathological inputs. */
    private int $skippedDedup = 0;

    public function recordDroppedException(): void
    {
        $this->droppedExceptions++;
    }

    public function recordSkippedDedup(): void
    {
        $this->skippedDedup++;
    }

    #[\Override]
    public function getContexts(): iterable
    {
        $attrs = [
            '#count' => count($this->referencing_contexts),
        ];
        if ($this->droppedExceptions > 0) {
            $attrs['#dropped_exceptions'] = $this->droppedExceptions;
        }
        if ($this->skippedDedup > 0) {
            $attrs['#skipped_dedup'] = $this->skippedDedup;
        }
        return $attrs;
    }
}
