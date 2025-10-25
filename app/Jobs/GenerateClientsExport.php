<?php

namespace App\Jobs;

use App\Exports\ClientsExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateClientsExport implements ShouldQueue
{
    use Queueable;

    public $timeout = 600;
    public $tries = 3;

    protected $exportId;

    public function __construct(string $exportId)
    {
        $this->exportId = $exportId;
        $this->onQueue('exports');
    }

    public function handle(): void
    {
        try {
            $exportPath = "exports/clients_" . now()->format('Y-m-d_His') . ".csv";

            Log::info("Starting clients export generation for export ID {$this->exportId}");

            Cache::put("client_export_{$this->exportId}", [
                'status' => 'processing',
                'progress' => 0
            ], now()->addHours(2));

            $exporter = new ClientsExport();
            $exporter->exportToCsv($exportPath);

            Cache::put("client_export_{$this->exportId}", [
                'status' => 'completed',
                'path' => $exportPath,
                'generated_at' => now()->toDateTimeString()
            ], now()->addHours(2));

            Log::info("Clients export completed for export ID {$this->exportId}: {$exportPath}");

        } catch (\Exception $e) {
            Log::error("Clients export generation failed for export ID {$this->exportId}: " . $e->getMessage());

            Cache::put("client_export_{$this->exportId}", [
                'status' => 'failed',
                'error' => $e->getMessage()
            ], now()->addHours(2));

            throw $e;
        }
    }
}
