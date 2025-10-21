<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Imports\ClientsImport;
use App\Models\Import;
use App\Services\ImportService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessClientsImport implements ShouldQueue
{
    use Batchable, Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 600;
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Import $import
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ImportService $importService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $importService->processImport($this->import, ClientsImport::class);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->import->update([
            'status' => ImportStatus::FAILED,
            'data' => [
                'error' => $exception->getMessage(),
                'failed_at' => now()->toDateTimeString(),
            ],
        ]);
    }
}
