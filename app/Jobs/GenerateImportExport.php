<?php

namespace App\Jobs;

use App\Models\Import;
use App\Exports\ImportDetailsExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class GenerateImportExport implements ShouldQueue
{
    use Queueable;

    public $timeout = 600; // 10 minutes for large files
    public $tries = 3;

    protected $importId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $importId)
    {
        $this->importId = $importId;
        $this->onQueue('exports');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $exportPath = "exports/import_{$this->importId}_" . now()->format('Y-m-d_His') . ".csv";

            Log::info("Starting export generation for import {$this->importId}");

            $exporter = new ImportDetailsExport($this->importId);
            $exporter->exportToCsv($exportPath);

            // Update only metadata column without loading data column
            // Convert json->jsonb to use || operator, then back to json
            \DB::statement("
                UPDATE imports
                SET metadata = ((COALESCE(metadata, '{}'::json)::jsonb) || ?::jsonb)::json,
                    updated_at = NOW()
                WHERE id = ?
            ", [
                json_encode([
                    'export_status' => 'completed',
                    'export_path' => $exportPath,
                    'export_generated_at' => now()->toDateTimeString()
                ]),
                $this->importId
            ]);

            Log::info("Export completed for import {$this->importId}: {$exportPath}");

        } catch (\Exception $e) {
            Log::error("Export generation failed for import {$this->importId}: " . $e->getMessage());

            // Update failure status
            \DB::statement("
                UPDATE imports
                SET metadata = ((COALESCE(metadata, '{}'::json)::jsonb) || ?::jsonb)::json,
                    updated_at = NOW()
                WHERE id = ?
            ", [
                json_encode([
                    'export_status' => 'failed',
                    'export_error' => $e->getMessage()
                ]),
                $this->importId
            ]);

            throw $e;
        }
    }
}
