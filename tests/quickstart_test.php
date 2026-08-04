<?php

declare(strict_types=1);

if (($argv[1] ?? null) === '__fake_pliego__') {
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
        || file_get_contents('document.html') !== "<h1>Invoice 42</h1>\n"
        || file_get_contents('assets/invoice.woff2') !== 'rights-cleared-font'
        || ($options['--page-size'] ?? null) !== '816x1056'
        || ($options['--page-margins'] ?? null) !== '48,48,48,48'
        || in_array('--allow-http-root', $argv, true)
    ) {
        fwrite(STDERR, "invalid quickstart request\n");
        exit(2);
    }

    mkdir($artifacts, 0700, true);
    file_put_contents($output, "%PDF-1.7\n% focused Laravel quickstart\n");
    fwrite(STDOUT, json_encode([
        'status' => 'rendered',
        'document_pdf' => $output,
        'artifacts' => $artifacts,
        'scene' => [
            'capture_status' => 'complete',
            'capture_code' => null,
        ],
    ])."\n");
    exit(0);
}

require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Pliego\Laravel\DocumentFactory;
use Pliego\Php\CliRenderer;
use Pliego\Php\RenderOptions;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir().'/pliego-laravel-quickstart-'.bin2hex(random_bytes(8));
mkdir("{$root}/views", 0700, true);
mkdir("{$root}/cache", 0700, true);
file_put_contents("{$root}/views/invoice.blade.php", '<h1>Invoice {{ $number }}</h1>'."\n");
file_put_contents("{$root}/invoice.woff2", 'rights-cleared-font');

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

$result = (new DocumentFactory(
    $views,
    new CliRenderer([PHP_BINARY, __FILE__, '__fake_pliego__']),
    "{$root}/jobs",
    new RenderOptions(),
))->view('invoice', ['number' => 42])
    ->denyNetwork()
    ->asset('assets/invoice.woff2', "{$root}/invoice.woff2")
    ->render('invoice.pdf');

$manifest = json_decode(
    (string) file_get_contents("{$result->inputBundlePath}/input-bundle.json"),
    true,
    flags: JSON_THROW_ON_ERROR,
);
check(str_starts_with($result->bytes(), '%PDF-1.7'), 'quickstart did not return a PDF');
check($manifest['environment']['network'] === ['policy' => 'deny'], 'network was not denied');
check(
    $manifest['assets']['assets/invoice.woff2']['sha256'] === 'sha256:'.hash('sha256', 'rights-cleared-font'),
    'bundled font hash was not recorded',
);

echo "Pliego Laravel focused quickstart passed; evidence retained at {$root}\n";
