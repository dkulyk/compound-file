<?php

declare(strict_types=1);

namespace DK\CompoundFile;

/**
 * Read-only PHP stream wrapper for CFBF streams.
 *
 * Register once, then open `ole2:///<encoded-file>?stream=<encoded-path>`.
 */
final class StreamWrapper
{
    public mixed $context = null;
    private Stream $stream;
    /** @var list<string> */
    private array $directoryEntries = [];
    private int $directoryPosition = 0;

    /** Registers the wrapper scheme. Safe to call more than once. */
    public static function register(string $scheme = 'ole2'): void
    {
        if (!in_array($scheme, stream_get_wrappers(), true) && !stream_wrapper_register($scheme, self::class)) {
            throw new \RuntimeException('Cannot register OLE2 stream wrapper.');
        }
    }

    /** Builds a wrapper URL for a file and an internal stream path. */
    public static function url(string $file, string $stream, string $scheme = 'ole2'): string
    {
        return $scheme . ':///' . rawurlencode($file) . '?stream=' . rawurlencode($stream);
    }

    /** Builds a wrapper URL for a storage; an empty path addresses the root. */
    public static function directoryUrl(string $file, string $storage = '', string $scheme = 'ole2'): string
    {
        return $scheme . ':///' . rawurlencode($file) . '?storage=' . rawurlencode($storage);
    }

    /** @internal */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (strpbrk($mode, 'waxc+') !== false) {
            return false;
        }
        $location = $this->parseUrl($path, 'stream');
        if ($location === null) {
            return false;
        }
        try {
            $this->stream = CompoundFile::open($location['file'])->openStream($location['entry']);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    /** @internal */ public function stream_read(int $count): string
    {
        return $this->stream->read($count);
    }
    /** @internal */ public function stream_eof(): bool
    {
        return $this->stream->eof();
    }
    /** @internal */ public function stream_tell(): int
    {
        return $this->stream->tell();
    }
    /** @internal */ public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return $this->stream->seek($offset, $whence);
    }
    /**
     * @internal
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [
            'size' => $this->stream->getSize(),
            7 => $this->stream->getSize(),
            'mode' => 0o100444,
            2 => 0o100444,
        ];
    }

    /**
     * @internal
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $location = $this->parseUrl($path, 'stream') ?? $this->parseUrl($path, 'storage');
        if ($location === null) {
            return false;
        }
        try {
            $entry = CompoundFile::open($location['file'])->findEntry($location['entry']);
            if ($entry === null) {
                return false;
            }
            $mode = $entry->isStorage() ? 0o040555 : 0o100444;
            return ['size' => $entry->getSize(), 7 => $entry->getSize(), 'mode' => $mode, 2 => $mode];
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /** @internal */
    public function dir_opendir(string $path, int $options): bool
    {
        $location = $this->parseUrl($path, 'storage');
        if ($location === null) {
            return false;
        }
        try {
            $children = CompoundFile::open($location['file'])->getChildren($location['entry']);
            $this->directoryEntries = ['.', '..'];
            foreach ($children as $child) {
                $this->directoryEntries[] = $child->getName();
            }
            $this->directoryPosition = 0;
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /** @internal */
    public function dir_readdir(): string|false
    {
        if (!isset($this->directoryEntries[$this->directoryPosition])) {
            return false;
        }
        return $this->directoryEntries[$this->directoryPosition++];
    }

    /** @internal */
    public function dir_rewinddir(): bool
    {
        $this->directoryPosition = 0;
        return true;
    }

    /** @internal */
    public function dir_closedir(): bool
    {
        $this->directoryEntries = [];
        $this->directoryPosition = 0;
        return true;
    }

    /**
     * @return array{file: string, entry: string}|null
     */
    private function parseUrl(string $path, string $entryParameter): ?array
    {
        $schemePosition = strpos($path, '://');
        $queryPosition = strpos($path, '?');
        if ($schemePosition === false) {
            return null;
        }

        if ($queryPosition === false) {
            if ($entryParameter !== 'stream') {
                return null;
            }
            $fragmentPosition = strpos($path, '#', $schemePosition + 3);
            if ($fragmentPosition === false) {
                return null;
            }
            $file = rawurldecode(substr($path, $schemePosition + 3, $fragmentPosition - $schemePosition - 3));
            $entry = rawurldecode(substr($path, $fragmentPosition + 1));
            if ($file === '' || $entry === '') {
                return null;
            }
            return ['file' => $file, 'entry' => $entry];
        }

        $encodedFile = ltrim(substr($path, $schemePosition + 3, $queryPosition - $schemePosition - 3), '/');
        $file = rawurldecode($encodedFile);
        if ($file === '') {
            return null;
        }
        parse_str(substr($path, $queryPosition + 1), $query);
        if (!isset($query[$entryParameter]) || !is_string($query[$entryParameter])) {
            return null;
        }
        return ['file' => $file, 'entry' => $query[$entryParameter]];
    }
}
