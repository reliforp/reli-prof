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

namespace Reli\Converter\BinaryTrace;

enum EventType: int
{
    case FRAME_DEF = 0x01;
    case STACK_DEF = 0x02;
    case SAMPLE = 0x03;
    case CHECKPOINT = 0x04;
    case SEGMENT_END = 0x05;
    case METADATA = 0x06;
    case PID_SAMPLE = 0x07;
    /** Compact sample without payload_length: [0x08][stack_id: varint] */
    case COMPACT_SAMPLE = 0x08;
    /** Repeat last compact sample: [0x09][count: varint] (no payload_length) */
    case REPEAT_SAMPLE = 0x09;
}
