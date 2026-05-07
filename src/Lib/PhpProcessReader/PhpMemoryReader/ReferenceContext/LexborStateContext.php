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

/**
 * Container for the four `ZEND_TLS` lexbor structures that ext/uri's
 * `PHP_RINIT_FUNCTION(uri_parser_whatwg)` installs on PHP >= 8.5
 * (`lexbor_mraw` initial chunk, BST entry pool, BST cache list,
 * Unicode NFC normalizer buffer). The structures aren't reachable from
 * `executor_globals` / `compiler_globals`, so they need a separate root
 * branch in the analyzer's emitted tree.
 */
final class LexborStateContext implements ReferenceContext
{
    use ReferenceContextDefault;
}
