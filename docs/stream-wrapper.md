# PHP stream wrapper

Register the wrapper once and use ordinary PHP stream functions:

```php
use DK\CompoundFile\StreamWrapper;

StreamWrapper::register();

$handle = fopen('ole2://document.doc#WordDocument', 'rb');
$contents = stream_get_contents($handle);
```

For arbitrary paths and Unicode or reserved characters, build an encoded URL:

```php
$url = StreamWrapper::url('/documents/example.xls', 'ObjectPool/Object 1');
$handle = fopen($url, 'rb');
```

Storages are exposed as read-only directories:

```php
$root = StreamWrapper::directoryUrl('/documents/example.xls');
$entries = scandir($root);

$objectPool = StreamWrapper::directoryUrl(
    '/documents/example.xls',
    'ObjectPool',
);
```

The wrapper supports `fread()`, `feof()`, `ftell()`, `fseek()`, `stat()`,
`is_file()`, `is_dir()`, `opendir()`, `readdir()`, and `scandir()`.

[Back to the README](../README.md)
