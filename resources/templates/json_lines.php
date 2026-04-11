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

use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
/** @var CallTrace $call_trace */
// $annotations is currently ignored in this template. Changing the JSON
// shape mid-stream would break existing `jq`-based consumers; a future
// template (e.g. json_lines_with_vars) can opt in when demand exists.
?>
<?= json_encode($call_trace, JSON_UNESCAPED_UNICODE) ?>
<?= "\n" ?>
