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
?>
<?= json_encode($call_trace, JSON_UNESCAPED_UNICODE) ?>
<?= "\n" ?>
