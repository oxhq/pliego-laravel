<?php

declare(strict_types=1);

namespace Pliego\Laravel;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Pliego\Php\CliRenderer;
use Pliego\Php\RenderOptions;

final readonly class DocumentFactory
{
    public function __construct(
        private ViewFactory $views,
        private CliRenderer $renderer,
        private string $workDirectory,
        private RenderOptions $defaults,
        private ?FilesystemFactory $filesystems = null,
        private ?string $defaultStorageDisk = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function view(string $name, array $data = []): PendingDocument
    {
        return new PendingDocument(
            $this->views,
            $this->renderer,
            $this->workDirectory,
            $this->defaults,
            $name,
            $data,
            $this->filesystems,
            $this->defaultStorageDisk,
        );
    }
}
