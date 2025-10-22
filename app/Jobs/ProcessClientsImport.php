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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessClientsImport implements ShouldQueue
{
    use Batchable, Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 3;
    public function __construct(
        public Import $import
    ) {}

    public function handle(ImportService $importService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $isLargeFile = $this->import->metadata['is_large_file'] ?? false;

        if ($isLargeFile) {
            $this->processLargeFileWithPython($importService);
        } else {
            ini_set('memory_limit', '512M');
            $importService->processImport($this->import, ClientsImport::class);
        }
    }

    protected function processLargeFileWithPython(ImportService $importService): void
    {
        try {
            $csvFilePath = Storage::disk('local')->path($this->import->file_path);
            $scriptPath = base_path('scripts/process_large_csv.py');
            $envPath = base_path('.env');
            $fileSignature = $this->import->file_signature;

            if (!file_exists($scriptPath)) {
                throw new \Exception("Python script not found: {$scriptPath}");
            }

            if (!file_exists($csvFilePath)) {
                throw new \Exception("CSV file not found: {$csvFilePath}");
            }

            Log::info("Chunking CSV into smaller JSON files for import {$this->import->id}");

            $command = sprintf(
                'python3 %s %s %s %s %s 2>&1',
                escapeshellarg($scriptPath),
                escapeshellarg($this->import->id),
                escapeshellarg($csvFilePath),
                escapeshellarg($envPath),
                escapeshellarg($fileSignature)
            );

            exec($command, $output, $returnCode);

            Log::info("Python output for import {$this->import->id}: " . implode("\n", $output));

            if ($returnCode !== 0) {
                throw new \Exception("Python chunking failed: " . implode("\n", $output));
            }

            Log::info("CSV chunked into JSON files for import {$this->import->id}");

            $this->import->refresh();
            $this->dispatchChunkProcessingJobs();

        } catch (\Exception $e) {
            Log::error("Large file processing failed for import {$this->import->id}: " . $e->getMessage());

            $this->import->update([
                'status' => ImportStatus::FAILED,
                'data' => [
                    'error' => $e->getMessage(),
                    'failed_at' => now()->toDateTimeString(),
                ],
            ]);

            throw $e;
        }
    }

    protected function dispatchChunkProcessingJobs(): void
    {
        $totalChunks = $this->import->data['total_chunks'] ?? 0;

        if ($totalChunks === 0) {
            throw new \Exception("No chunks found in import data");
        }

        Log::info("Dispatching {$totalChunks} chunk processing jobs for import {$this->import->id}");

        $this->import->update([
            'status' => ImportStatus::PROCESSING_CHUNKS,
            'data' => array_merge($this->import->data, [
                'rows' => [], // Initialize empty rows array for storing processed data
                'summary' => [
                    'imported' => 0,
                    'failed' => 0,
                    'duplicates' => 0,
                ],
            ]),
        ]);

        for ($i = 0; $i < $totalChunks; $i++) {
            ProcessChunkedClientsImport::dispatch($this->import->id, $i)
                ->onQueue('imports');
        }

        $this->scheduleFinalizationJob($totalChunks);
    }

    protected function scheduleFinalizationJob(int $totalChunks): void
    {
        FinalizeChunkedImport::dispatch($this->import->id, $totalChunks)
            ->onQueue('imports')
            ->delay(now()->addSeconds(30));
    }

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
