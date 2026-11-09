<?php

namespace App\Jobs;

use App\Models\Export;
use App\Services\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Export asynchrone (V41). Les droits sont revérifiés au moment de la génération.
 */
class GenerateExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public string $exportId)
    {
        $this->onQueue('exports');
    }

    public function handle(ExportService $service): void
    {
        $export = Export::query()->with('requester')->find($this->exportId);
        if (! $export || in_array($export->status, ['ready', 'cancelled'], true)) {
            return;
        }
        $service->generate($export);
    }

    public function failed(Throwable $exception): void
    {
        Export::query()->whereKey($this->exportId)->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => now()]);
    }
}
