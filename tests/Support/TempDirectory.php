<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Support;

/**
 * Throwaway directories for the buffer, which is a filesystem structure and is
 * therefore tested against a real filesystem rather than a double — the
 * locking behaviour under concurrent writers is the whole point of it.
 */
final class TempDirectory
{
    public static function make(string $prefix = 'gtaip'): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.uniqid($prefix, true);
        mkdir($dir, 0o775, true);

        return $dir;
    }

    public static function remove(?string $dir): void
    {
        if (null === $dir || !is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? self::remove($path) : unlink($path);
        }

        rmdir($dir);
    }
}
