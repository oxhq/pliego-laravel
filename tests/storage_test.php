<?php

declare(strict_types=1);

if (($argv[1] ?? null) === '__fake_pliego_storage__') {
    $options = [];
    for ($index = 4; $index < count($argv); $index += 2) {
        $options[$argv[$index]] = $argv[$index + 1] ?? null;
    }
    $output = $options['--output'] ?? null;
    $artifacts = $options['--artifacts'] ?? null;
    if (
        ($argv[2] ?? null) !== 'render'
        || ($argv[3] ?? null) !== 'document.html'
        || !is_string($output)
        || !is_string($artifacts)
    ) {
        fwrite(STDERR, "invalid storage test request\n");
        exit(2);
    }

    mkdir($artifacts, 0700, true);
    $pdf = fopen($output, 'wb');
    if (!is_resource($pdf)) {
        fwrite(STDERR, "cannot create storage test PDF\n");
        exit(2);
    }
    if (fwrite($pdf, "%PDF-1.7\n") !== 9) {
        fwrite(STDERR, "cannot write storage test PDF header\n");
        exit(2);
    }
    $chunk = str_repeat('p', 1024 * 1024);
    for ($index = 0; $index < 32; $index++) {
        if (fwrite($pdf, $chunk) !== strlen($chunk)) {
            fwrite(STDERR, "cannot write storage test PDF body\n");
            exit(2);
        }
    }
    fclose($pdf);

    fwrite(STDOUT, json_encode([
        'status' => 'rendered',
        'document_pdf' => $output,
        'artifacts' => $artifacts,
        'scene' => [
            'capture_status' => 'complete',
            'capture_code' => null,
        ],
    ], JSON_THROW_ON_ERROR)."\n");
    exit(0);
}

require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Pliego\Laravel\DocumentFactory;
use Pliego\Laravel\Exception\DocumentStorageException;
use Pliego\Laravel\StoredDocument;
use Pliego\Php\CliRenderer;
use Pliego\Php\RenderOptions;

final class StorageTestApplication extends Container
{
    public function __construct(private readonly string $storageRoot) {}

    public function storagePath(string $path = ''): string
    {
        return $this->storageRoot.($path === '' ? '' : DIRECTORY_SEPARATOR.$path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        $app = Container::getInstance();
        if (!$app instanceof StorageTestApplication) {
            throw new RuntimeException('storage test application is not configured');
        }

        return $app->storagePath($path);
    }
}

/** @implements ArrayAccess<string, mixed> */
final class StorageTestConfig implements ArrayAccess
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && Arr::has($this->items, $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_string($offset) ? $this->get($offset) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!is_string($offset)) {
            throw new InvalidArgumentException('test config keys must be strings');
        }
        Arr::set($this->items, $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_string($offset)) {
            Arr::forget($this->items, $offset);
        }
    }
}

function storageExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeStorageFixture(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink()
            ? rmdir($entry->getPathname())
            : unlink($entry->getPathname());
    }
    rmdir($path);
}

function storageDocumentFactory(
    string $root,
    FilesystemFactory $filesystems,
    string $defaultDisk,
): DocumentFactory {
    $files = new Filesystem();
    $container = new Container();
    $resolver = new EngineResolver();
    $resolver->register('blade', fn () => new CompilerEngine(
        new BladeCompiler($files, "{$root}/cache"),
        $files,
    ));
    $views = new Factory(
        $resolver,
        new FileViewFinder($files, ["{$root}/views"]),
        new Dispatcher($container),
    );
    $views->setContainer($container);

    return new DocumentFactory(
        $views,
        new CliRenderer([PHP_BINARY, __FILE__, '__fake_pliego_storage__']),
        "{$root}/jobs",
        new RenderOptions(),
        $filesystems,
        $defaultDisk,
    );
}

$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pliego-laravel-storage-'.bin2hex(random_bytes(8));
storageExpect(mkdir("{$root}/views", 0700, true), 'cannot create storage test views');
storageExpect(mkdir("{$root}/cache", 0700, true), 'cannot create storage test cache');
file_put_contents("{$root}/views/invoice.blade.php", '<h1>Invoice {{ $number }}</h1>');
file_put_contents("{$root}/blocked-root", 'not a directory');
register_shutdown_function(static fn () => removeStorageFixture($root));

$app = new StorageTestApplication("{$root}/framework-storage");
$app->instance('config', new StorageTestConfig([
    'filesystems' => [
        'default' => 'local',
        'disks' => [
            'local' => [
                'driver' => 'local',
                'root' => "{$root}/local",
                'throw' => false,
            ],
            'archive' => [
                'driver' => 'local',
                'root' => "{$root}/archive",
                'throw' => false,
            ],
            'broken' => [
                'driver' => 'local',
                'root' => "{$root}/blocked-root",
                'throw' => false,
            ],
        ],
    ],
]));
$filesystems = new FilesystemManager($app);
$app->instance('filesystem', $filesystems);
$app->instance(\Illuminate\Testing\ParallelTesting::class, new class {
    public function token(): false
    {
        return false;
    }
});
Container::setInstance($app);
Facade::setFacadeApplication($app);

$factory = storageDocumentFactory($root, $filesystems, 'local');
Storage::fake('archive');

$memoryBefore = memory_get_usage(true);
memory_reset_peak_usage();
$stored = $factory->view('invoice', ['number' => 42])->store(
    'invoices/42.pdf',
    'archive',
    ['visibility' => 'public'],
);
$additionalPeak = memory_get_peak_usage(true) - $memoryBefore;
storageExpect($stored instanceof StoredDocument, 'store did not return a typed result');
storageExpect($stored->disk === 'archive', 'stored disk identity changed');
storageExpect($stored->path === 'invoices/42.pdf', 'stored path identity changed');
storageExpect($stored->renderResult->pdfPath !== '', 'render result was not returned');
storageExpect(Storage::disk('archive')->exists($stored->path), 'Storage::fake did not receive the PDF');
storageExpect(
    Storage::disk('archive')->size($stored->path) === filesize($stored->renderResult->pdfPath),
    'stored PDF size changed',
);
storageExpect(
    hash_file('sha256', Storage::disk('archive')->path($stored->path))
        === hash_file('sha256', $stored->renderResult->pdfPath),
    'stored PDF bytes changed',
);
storageExpect(Storage::disk('archive')->getVisibility($stored->path) === 'public', 'storage options were not forwarded');
storageExpect($additionalPeak < 20 * 1024 * 1024, 'storage buffered the 32 MiB PDF in PHP memory');
storageExpect(is_file($stored->renderResult->pdfPath), 'successful storage deleted the retained PDF');
storageExpect(is_dir($stored->renderResult->jobPath), 'successful storage deleted the render job');

$local = $factory->view('invoice', ['number' => 43])->store('invoices/43.pdf');
storageExpect($local->disk === 'local', 'configured default storage disk was not retained');
storageExpect(is_file("{$root}/local/invoices/43.pdf"), 'local disk did not receive the PDF');
storageExpect(is_dir($local->renderResult->jobPath), 'local storage deleted the render job');

try {
    $factory->view('invoice', ['number' => 44])->store('invoices/44.pdf', 'broken');
    throw new RuntimeException('broken storage disk accepted the PDF');
} catch (DocumentStorageException $error) {
    storageExpect($error->disk === 'broken', 'storage failure lost the disk identity');
    storageExpect($error->path === 'invoices/44.pdf', 'storage failure lost the path identity');
    storageExpect($error->getPrevious() !== null, 'storage failure lost its cause');
    storageExpect(is_file($error->renderResult->pdfPath), 'storage failure deleted the retained PDF');
    storageExpect(is_dir($error->renderResult->jobPath), 'storage failure deleted the render job');
}

echo "Laravel storage: ok\n";
