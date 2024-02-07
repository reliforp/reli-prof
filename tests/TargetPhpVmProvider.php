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

namespace Reli;

use Reli\Lib\PhpInternals\ZendTypeReader;

class TargetPhpVmProvider
{
    public static function from(string $php_version)
    {
        $versions = [
            ZendTypeReader::V70,
            ZendTypeReader::V71,
            ZendTypeReader::V72,
            ZendTypeReader::V73,
            ZendTypeReader::V74,
            ZendTypeReader::V80,
            ZendTypeReader::V81,
            ZendTypeReader::V82,
            ZendTypeReader::V83,
        ];
        foreach ($versions as $v) {
            if ($php_version <= $v) {
                yield $v => [$v, self::dockerImageNameFromPhpVersion($v)];
            }
        }
    }

    public static function allSupported()
    {
        $versions = [
            ZendTypeReader::V70,
            ZendTypeReader::V71,
            ZendTypeReader::V72,
            ZendTypeReader::V73,
            ZendTypeReader::V74,
            ZendTypeReader::V80,
            ZendTypeReader::V81,
            ZendTypeReader::V82,
            ZendTypeReader::V83,
        ];
        foreach ($versions as $version) {
            yield $version => [$version, self::dockerImageNameFromPhpVersion($version)];
        }
    }

    public static function dockerImageNameFromPhpVersion(string $php_version): string
    {
        return match ($php_version) {
            ZendTypeReader::V70 => 'php:7.0-cli',
            ZendTypeReader::V71 => 'php:7.1-cli',
            ZendTypeReader::V72 => 'php:7.2-cli',
            ZendTypeReader::V73 => 'php:7.3-cli',
            ZendTypeReader::V74 => 'php:7.4-cli',
            ZendTypeReader::V80 => 'php:8.0-cli',
            ZendTypeReader::V81 => 'php:8.1-cli',
            ZendTypeReader::V82 => 'php:8.2-cli',
            ZendTypeReader::V83 => 'php:8.3-cli',
            default => throw new \InvalidArgumentException("unsupported php version: $php_version"),
        };
    }

    public static function runScriptViaContainer(
        string $docker_image_name,
        string $script,
        array &$pipes,
    ) {
        $tmp_file = tempnam('/tmp/reli-test', 'reli-prof-test');
        $pid_writer = tempnam('/tmp/reli-test', 'reli-prof-test-pid-writer');
        $pid_file = tempnam('/tmp/reli-test', 'reli-prof-test-pid');

        chmod($tmp_file, 0777);
        chmod($pid_writer, 0777);
        chmod($pid_file, 0777);

        file_put_contents(
            $pid_writer,
            <<<CODE
            <?php
            file_put_contents('/target-pid', getmypid());
            fputs(STDOUT, "pid written\n");
            CODE
        );
        file_put_contents(
            $tmp_file,
            $script
        );

        $proc_handle = self::procOpenViaDocker(
            $docker_image_name,
            'php -dauto_prepend_file=/pid-writer /source',
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w']
            ],
            $pipes,
            [
                $tmp_file => '/source',
                $pid_writer => '/pid-writer',
                $pid_file => '/target-pid',
                '/tmp/reli-test' => '/tmp/reli-test',
            ],
        );
        $pid_written_message = fgets($pipes[1]);
        assert($pid_written_message === "pid written\n");
        $pid = (int)file_get_contents($pid_file);
        return [$proc_handle, $pid];
    }

    public static function procOpenViaDocker(
        string $docker_image_name,
        string $command,
        array $descriptorspec,
        array &$pipes,
        array $mount_points = [],
    ) {
        $mount_options = array_map(
            fn ($source, $target) => "-v$source:$target:rw",
            array_keys($mount_points),
            array_values($mount_points)
        );
        $uid = posix_getuid();
        $gid = posix_getgid();

        $docker_command = [
            'docker',
            'run',
            '--rm',
            '-u',
            "$uid:$gid",
            '--pid',
            'host',
            '-i',
            '--entrypoint',
            'sh',
            ...$mount_options,
            $docker_image_name,
            '-c',
            $command,
        ];
        return proc_open(
            $docker_command,
            $descriptorspec,
            $pipes
        );
    }
}
