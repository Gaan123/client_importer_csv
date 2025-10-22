<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessChunkedClientsImport implements ShouldQueue
{
    use Batchable, Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 600;
    public int $tries = 3;

    /**
     * Use import ID instead of model to avoid loading large data column
     */
    public function __construct(
        public int $importId,
        public int $chunkIndex
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            Log::info("Processing chunk {$this->chunkIndex} for import {$this->importId}");

            // Extract only needed fields from JSON to avoid loading entire data column
            // ->> returns text, -> returns JSON for further nesting
            $result = DB::selectOne(
                "SELECT
                    data->>'chunks_directory' as chunks_dir,
                    data->'chunk_files' as chunk_files
                 FROM imports
                 WHERE id = ?",
                [$this->importId]
            );

            if (!$result || !$result->chunks_dir) {
                throw new \Exception("Chunk metadata not found in import data");
            }

            $chunkFiles = json_decode($result->chunk_files, true);
            if (!isset($chunkFiles[$this->chunkIndex])) {
                throw new \Exception("Chunk file index {$this->chunkIndex} not found");
            }

            $chunkFilename = $chunkFiles[$this->chunkIndex];

            // Prevent path traversal attacks
            if (str_contains($result->chunks_dir, '..') || str_contains($chunkFilename, '..')) {
                throw new \Exception("Invalid path detected in chunk metadata");
            }

            $chunkFilePath = storage_path("app/{$result->chunks_dir}/{$chunkFilename}");
            $realPath = realpath(dirname($chunkFilePath));
            $expectedPath = realpath(storage_path('app/chunks'));

            // Ensure file is within chunks directory
            if (!$realPath || !str_starts_with($realPath, $expectedPath)) {
                throw new \Exception("Chunk file path is outside allowed directory");
            }

            if (!file_exists($chunkFilePath)) {
                throw new \Exception("Chunk file not found: {$chunkFilePath}");
            }
            $jsonContent = file_get_contents($chunkFilePath);
            $rows = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Failed to decode JSON: " . json_last_error_msg());
            }

            Log::info("Chunk {$this->chunkIndex}: Processing " . count($rows) . " rows");

            $stats = [
                'imported' => 0,
                'failed' => 0,
                'duplicates' => 0,
            ];

            foreach ($rows as $row) {
                try {
                    DB::table('clients')->insert([
                        'company' => $row['company'] ?? '',
                        'email' => $row['email'] ?? '',
                        'phone' => $row['phone'] ?? '',
                        'has_duplicates' => false,
                        'extras' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $stats['imported']++;

                } catch (\Exception $e) {
                    $stats['failed']++;
                    $isDuplicate = str_contains($e->getMessage(), 'Duplicate') ||
                                   str_contains($e->getMessage(), 'unique');

                    if ($isDuplicate) {
                        $stats['duplicates']++;
                    }
                }
            }

            $this->updateChunkStats($stats);

            Log::info("Chunk {$this->chunkIndex} completed: Imported={$stats['imported']}, Failed={$stats['failed']}, Duplicates={$stats['duplicates']}");

        } catch (\Exception $e) {
            Log::error("Chunk {$this->chunkIndex} failed for import {$this->importId}: " . $e->getMessage());
            throw $e;
        }
    }

    protected function updateChunkStats(array $stats): void
    {
        DB::transaction(function () use ($stats) {
            // Append chunk index to array: || operator concatenates JSONB arrays
            DB::statement(
                "UPDATE imports
                 SET data = jsonb_set(
                     COALESCE(data, '{}'::jsonb),
                     '{processed_chunk_indices}',
                     (COALESCE(data->'processed_chunk_indices', '[]'::jsonb) || ?::jsonb)
                 )
                 WHERE id = ?",
                [json_encode([$this->chunkIndex]), $this->importId]
            );

            // Atomically increment summary counters in nested JSON
            // Cast chain: int -> add -> text -> jsonb to update JSON field
            DB::statement(
                "UPDATE imports
                 SET data = jsonb_set(
                     jsonb_set(
                         jsonb_set(
                             data,
                             '{summary,imported}',
                             (COALESCE((data->'summary'->>'imported')::int, 0) + ?)::text::jsonb
                         ),
                         '{summary,failed}',
                         (COALESCE((data->'summary'->>'failed')::int, 0) + ?)::text::jsonb
                     ),
                     '{summary,duplicates}',
                     (COALESCE((data->'summary'->>'duplicates')::int, 0) + ?)::text::jsonb
                 )
                 WHERE id = ?",
                [$stats['imported'], $stats['failed'], $stats['duplicates'], $this->importId]
            );
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Chunk {$this->chunkIndex} permanently failed for import {$this->importId}: " . $exception->getMessage());

        // Append failed chunk index to array for tracking
        DB::statement(
            "UPDATE imports
             SET data = jsonb_set(
                 COALESCE(data, '{}'::jsonb),
                 '{failed_chunks}',
                 (COALESCE(data->'failed_chunks', '[]'::jsonb) || ?::jsonb)
             )
             WHERE id = ?",
            [json_encode([$this->chunkIndex]), $this->importId]
        );
    }
}
