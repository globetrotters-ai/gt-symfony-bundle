<?php

declare(strict_types=1);

namespace Globetrotters\AiPresenceBundle\Tests\Support;

/**
 * A pass-through stream wrapper over the real filesystem that records every
 * file opened or stat'ed through it.
 *
 * Point a BufferDirectory at {@see url()} and everything the code under test
 * does in that directory still happens on disk — locks, renames and all — but
 * the test can also ask what it *looked at*. Asserting on what was written
 * only proves a file was not kept; this proves it was never read: the beacon
 * endpoint never opening ``vendor-ranges.json``, and not opening
 * ``ai-referrers.json`` for a view with no referrer.
 */
final class OpenRecordingStream
{
    public const SCHEME = 'gtaip-record';

    /** @var list<string> basenames opened, in order */
    public static array $opened = [];

    /** @var list<string> basenames stat'ed by path (is_file(), file_exists()…), in order */
    public static array $statted = [];

    /** @var resource|null set by PHP */
    public $context;

    /** @var resource|null */
    private $handle;

    /** @var resource|null */
    private $directory;

    public static function register(): void
    {
        if (!\in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        self::$opened = [];
        self::$statted = [];
    }

    public static function unregister(): void
    {
        if (\in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    /**
     * The recorded twin of a real directory.
     */
    public static function url(string $dir): string
    {
        return self::SCHEME.'://'.$dir;
    }

    /**
     * Every basename the code under test touched, opened or stat'ed.
     *
     * @return list<string>
     */
    public static function touched(): array
    {
        return array_values(array_unique([...self::$opened, ...self::$statted]));
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opened[] = basename(self::real($path));
        $handle = @fopen(self::real($path), $mode);
        if (false === $handle) {
            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        return null === $this->handle || $count < 1 ? false : fread($this->handle, $count);
    }

    public function stream_write(string $data): int
    {
        return null === $this->handle ? 0 : (int) fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return null === $this->handle || feof($this->handle);
    }

    public function stream_tell(): int
    {
        return null === $this->handle ? 0 : (int) ftell($this->handle);
    }

    public function stream_seek(int $offset, int $whence = \SEEK_SET): bool
    {
        return null !== $this->handle && 0 === fseek($this->handle, $offset, $whence);
    }

    public function stream_flush(): bool
    {
        return null !== $this->handle && fflush($this->handle);
    }

    public function stream_truncate(int $size): bool
    {
        return null !== $this->handle && $size >= 0 && ftruncate($this->handle, $size);
    }

    public function stream_lock(int $operation): bool
    {
        return null !== $this->handle && flock($this->handle, $operation);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return null === $this->handle ? false : fstat($this->handle);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        if (null !== $this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        self::$statted[] = basename(self::real($path));

        return @stat(self::real($path));
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return match ($option) {
            \STREAM_META_ACCESS => @chmod(self::real($path), (int) $value),
            \STREAM_META_TOUCH => @touch(self::real($path)),
            default => false,
        };
    }

    public function rename(string $from, string $to): bool
    {
        return @rename(self::real($from), self::real($to));
    }

    public function unlink(string $path): bool
    {
        return @unlink(self::real($path));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return @mkdir(self::real($path), $mode, 0 !== ($options & \STREAM_MKDIR_RECURSIVE));
    }

    public function dir_opendir(string $path, int $options): bool
    {
        $directory = @opendir(self::real($path));
        if (false === $directory) {
            return false;
        }
        $this->directory = $directory;

        return true;
    }

    public function dir_readdir(): string|false
    {
        return null === $this->directory ? false : readdir($this->directory);
    }

    public function dir_rewinddir(): bool
    {
        if (null !== $this->directory) {
            rewinddir($this->directory);
        }

        return true;
    }

    public function dir_closedir(): bool
    {
        if (null !== $this->directory) {
            closedir($this->directory);
            $this->directory = null;
        }

        return true;
    }

    private static function real(string $url): string
    {
        return substr($url, \strlen(self::SCHEME) + 3);
    }
}
