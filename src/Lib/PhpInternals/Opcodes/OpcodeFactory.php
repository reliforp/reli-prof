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

namespace Reli\Lib\PhpInternals\Opcodes;

final class OpcodeFactory
{
    /** @var array<string, class-string<Opcode>> */
    private const VERSION_MAP = [
        'v70' => OpcodeV70::class,
        'v71' => OpcodeV71::class,
        'v72' => OpcodeV72::class,
        'v73' => OpcodeV73::class,
        'v74' => OpcodeV74::class,
        'v80' => OpcodeV80::class,
        'v81' => OpcodeV81::class,
    ];

    /**
     * @template TVersion of key-of<self::VERSION_MAP>
     * @param TVersion $version
     * @return (
     *   TVersion is 'v70' ? OpcodeV70 :
     *   TVersion is 'v71' ? OpcodeV71 :
     *   TVersion is 'v72' ? OpcodeV72 :
     *   TVersion is 'v73' ? OpcodeV73 :
     *   TVersion is 'v74' ? OpcodeV74 :
     *   TVersion is 'v80' ? OpcodeV80 :
     *   OpcodeV81
     * )
     */
    public function create(string $version, int $opcode): Opcode
    {
        return match ($version) {
            'v70' => new OpcodeV70($opcode),
            'v71' => new OpcodeV71($opcode),
            'v72' => new OpcodeV72($opcode),
            'v73' => new OpcodeV73($opcode),
            'v74' => new OpcodeV74($opcode),
            'v80' => new OpcodeV80($opcode),
            'v81' => new OpcodeV81($opcode),
        };
    }
}
