<?php

declare(strict_types=1);

if (($argv[1] ?? null) === '__fake_pliego__') {
    $engineStartedAt = hrtime(true);
    $engineTiming = static fn (): array => [
        'schema' => 'pliego.engine-timings',
        'version' => 1,
        'unit' => 'milliseconds',
        'measurement_boundary' => 'before_timings_artifact_write',
        'total_ms' => (hrtime(true) - $engineStartedAt) / 1_000_000,
    ];
    if (($argv[2] ?? null) !== 'render' || ($argv[3] ?? null) !== 'document.html') {
        fwrite(STDERR, "invalid fake command\n");
        exit(2);
    }

    $options = [];
    for ($index = 4; $index < count($argv); $index += 2) {
        $options[$argv[$index]] = $argv[$index + 1] ?? null;
    }
    $html = file_get_contents('document.html');
    if (!is_string($html) || !is_file('assets/test.txt')) {
        fwrite(STDERR, "missing fake input\n");
        exit(2);
    }
    if (str_contains($html, 'FAIL_ENGINE')) {
        fwrite(STDOUT, json_encode([
            'status' => 'failed',
            'error' => ['code' => 'RESOURCE_DENIED', 'message' => 'synthetic denial'],
            'engine_timings' => $engineTiming(),
        ], JSON_THROW_ON_ERROR)."\n");
        exit(1);
    }

    $output = $options['--output'] ?? null;
    $artifacts = $options['--artifacts'] ?? null;
    if (!is_string($output) || !is_string($artifacts)) {
        fwrite(STDERR, "missing fake output paths\n");
        exit(2);
    }
    mkdir($artifacts, 0700, true);
    file_put_contents($output, "%PDF-1.7\n% focused Laravel timing proof\n");
    fwrite(STDOUT, json_encode([
        'status' => 'rendered',
        'document_pdf' => $output,
        'artifacts' => $artifacts,
        'scene' => [
            'capture_status' => 'complete',
            'capture_code' => null,
        ],
        'engine_timings' => $engineTiming(),
    ], JSON_THROW_ON_ERROR)."\n");
    exit(0);
}

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use Pliego\Laravel\DocumentFactory;
use Pliego\Laravel\Facades\Document;
use Pliego\Php\CliRenderer;
use Pliego\Php\Exception\EngineRenderException;
use Pliego\Php\RenderOptions;

require dirname(__DIR__).'/vendor/autoload.php';

function bridgeExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string, mixed> $timings */
function bridgeReconciles(array $timings): bool
{
    $sum = array_sum(array_filter($timings['phases_ms'], is_float(...)));

    return abs($sum - $timings['total_ms']) < 0.02
        && ($timings['measurement_boundary'] ?? null) === 'render-invocation-before-timing-diagnostics'
        && is_float($timings['native_engine_ms'])
        && abs($timings['native_engine_ms'] + $timings['bridge_overhead_ms'] - $timings['total_ms']) < 0.002;
}

$root = sys_get_temp_dir().'/pliego-laravel-timings-'.getmypid().'-'.bin2hex(random_bytes(4));
$viewsPath = "{$root}/views";
$cachePath = "{$root}/cache";
$workPath = "{$root}/jobs";
mkdir($viewsPath, 0700, true);
mkdir($cachePath, 0700, true);
$asset = "{$root}/source.txt";
file_put_contents($asset, "asset\n");
file_put_contents(
    "{$viewsPath}/invoice.blade.php",
    '<h1>{{ $title }}</h1>@foreach ($rows as $row)<p>{{ $row }}</p>@endforeach',
);

$container = new Container();
$files = new Filesystem();
$resolver = new EngineResolver();
$compiler = new BladeCompiler($files, $cachePath);
$resolver->register('blade', static fn (): CompilerEngine => new CompilerEngine($compiler, $files));
$views = new ViewFactory(
    $resolver,
    new FileViewFinder($files, [$viewsPath]),
    new Dispatcher($container),
);
$views->setContainer($container);
$container->singleton(DocumentFactory::class, static function () use ($views, $workPath): DocumentFactory {
    $runtimeStartedAt = hrtime(true);
    $binary = realpath(PHP_BINARY);
    bridgeExpect(is_string($binary), 'PHP runtime resolves');

    return new DocumentFactory(
        $views,
        new CliRenderer(
            [$binary, __FILE__, '__fake_pliego__'],
            runtimeResolutionNanoseconds: (int) (hrtime(true) - $runtimeStartedAt),
        ),
        $workPath,
        new RenderOptions(),
    );
});
Facade::setFacadeApplication($container);
bridgeExpect(
    realpath((string) (new ReflectionClass(DocumentFactory::class))->getFileName())
        === realpath(dirname(__DIR__).'/src/DocumentFactory.php'),
    'proof uses this split package source',
);

$startedAt = hrtime(true);
$result = Document::view('invoice', ['title' => 'Invoice', 'rows' => ['A', 'B']])
    ->asset('assets/test.txt', $asset)
    ->render();
$wallMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
$timings = $result->bridgeTimings;
bridgeExpect(is_float($timings['phases_ms']['view_render']), 'Blade render is measured');
bridgeExpect(is_float($timings['setup_ms']['runtime_resolution']), 'runtime resolution is measured');
bridgeExpect($timings['setup_ms']['runtime_install'] === null, 'install is outside render');
bridgeExpect(bridgeReconciles($timings), 'Laravel phases reconcile');
$coldMilliseconds = $timings['total_ms'] + $timings['setup_ms']['runtime_resolution'];
bridgeExpect(
    $coldMilliseconds <= $wallMilliseconds + 1
        && $wallMilliseconds - $coldMilliseconds < 50,
    'render total plus cold setup is contained by fresh facade wall time',
);
bridgeExpect(str_contains((string) file_get_contents($result->inputBundlePath.'/document.html'), 'Invoice'), 'Blade output rendered');

$warm = Document::view('invoice', ['title' => 'Warm', 'rows' => []])
    ->asset('assets/test.txt', $asset)
    ->render();
bridgeExpect($warm->bridgeTimings['setup_ms']['runtime_resolution'] === 0.0, 'cached runtime costs zero');

try {
    Document::view('invoice', ['title' => 'FAIL_ENGINE', 'rows' => []])
        ->asset('assets/test.txt', $asset)
        ->render();
    throw new RuntimeException('expected typed Laravel failure');
} catch (EngineRenderException $error) {
    bridgeExpect($error->errorCode === 'RESOURCE_DENIED', 'typed Laravel failure preserved');
    bridgeExpect(is_float($error->bridgeTimings['phases_ms']['view_render']), 'failed Blade render measured');
    bridgeExpect(bridgeReconciles($error->bridgeTimings), 'failed Laravel phases reconcile');
}

$runtimeSetupMilliseconds = $timings['setup_ms']['runtime_resolution'];
echo sprintf(
    "Pliego Laravel facade timing proof passed; wall=%.3fms render=%.3fms setup=%.3fms cold=%.3fms residual=%.3fms; evidence retained at %s\n",
    $wallMilliseconds,
    $timings['total_ms'],
    $runtimeSetupMilliseconds,
    $coldMilliseconds,
    abs($coldMilliseconds - $wallMilliseconds),
    $root,
);
