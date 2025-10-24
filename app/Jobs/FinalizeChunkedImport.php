<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class FinalizeChunkedImport implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 300;
    public int $tries = 5;
    public function __construct(
        public int $importId,
        public int $expectedChunks
    ) {}

    public function handle(): void
    {
        try {
            // Count chunks without loading full data JSON into memory
            // Uses jsonb_array_length to get array sizes directly in PostgreSQL
            $result = DB::selectOne(
                "SELECT
                    COALESCE(jsonb_array_length(data->'processed_chunk_indices'), 0) as processed,
                    COALESCE(jsonb_array_length(data->'failed_chunks'), 0) as failed
                 FROM imports
                 WHERE id = ?",
                [$this->importId]
            );

            $totalProcessed = $result->processed ?? 0;
            $totalFailed = $result->failed ?? 0;


            if (($totalProcessed + $totalFailed) < $this->expectedChunks) {
                Log::info("Not all chunks processed yet for import {$this->importId}, rescheduling finalization");
                $this->release(30);
                return;
            }

            // Extract summary integers from nested JSON without loading entire data column
            // ->> returns text, cast to int for numeric operations
            $summaryResult = DB::selectOne(
                "SELECT
                    COALESCE((data->'summary'->>'imported')::int, 0) as imported,
                    COALESCE((data->'summary'->>'failed')::int, 0) as failed,
                    COALESCE((data->'summary'->>'duplicates')::int, 0) as duplicates
                 FROM imports
                 WHERE id = ?",
                [$this->importId]
            );

            $summary = [
                'imported' => $summaryResult->imported ?? 0,
                'failed' => $summaryResult->failed ?? 0,
                'duplicates' => $summaryResult->duplicates ?? 0,
            ];

            $status = ImportStatus::COMPLETED;
            if ($totalFailed > 0) {
                $status = ImportStatus::COMPLETED_WITH_ERRORS;
            } elseif ($summary['failed'] > 0 && $summary['imported'] > 0) {
                $status = ImportStatus::COMPLETED_WITH_ERRORS;
            } elseif ($summary['failed'] > 0 && $summary['imported'] === 0) {
                $status = ImportStatus::FAILED;
            }

            // Update status and add finalization metadata using nested jsonb_set
            // Each jsonb_set adds one field to the JSON structure
            DB::statement(
                "UPDATE imports
                 SET status = ?,
                     data = jsonb_set(
                         jsonb_set(
                             jsonb_set(
                                 jsonb_set(
                                     data,
                                     '{summary,total_chunks}',
                                     ?::jsonb
                                 ),
                                 '{summary,processed_chunks}',
                                 ?::jsonb
                             ),
                             '{summary,failed_chunks}',
                             ?::jsonb
                         ),
                         '{finalized_at}',
                         ?::jsonb
                     ),
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    $status->value,
                    json_encode($this->expectedChunks),
                    json_encode($totalProcessed),
                    json_encode($totalFailed),
                    json_encode(now()->toDateTimeString()),
                    $this->importId
                ]
            );

            Log::info("Summary: Imported={$summary['imported']}, Failed={$summary['failed']}, Duplicates={$summary['duplicates']}");

            // Clean up chunk files and original CSV after successful processing
            if ($status->isSuccessful()) {
                $this->cleanupChunkFiles();
                $this->cleanupOriginalCsv();

                // Dispatch duplicate detection job
                DetectClientDuplicates::dispatch($this->importId)
                    ->onQueue('imports')
                    ->delay(now()->addSeconds(5));
            }

        } catch (\Exception $e) {
            Log::error("Finalization failed for import {$this->importId}: " . $e->getMessage());

            if ($this->attempts() >= $this->tries) {
                DB::statement(
                    "UPDATE imports
                     SET status = ?,
                         data = jsonb_set(COALESCE(data, '{}'::jsonb), '{finalization_error}', ?::jsonb),
                         updated_at = NOW()
                     WHERE id = ?",
                    [ImportStatus::FAILED->value, json_encode($e->getMessage()), $this->importId]
                );
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Finalization permanently failed for import {$this->importId}: " . $exception->getMessage());

        DB::statement(
            "UPDATE imports
             SET status = ?,
                 data = jsonb_set(
                     jsonb_set(
                         COALESCE(data, '{}'::jsonb),
                         '{finalization_error}',
                         ?::jsonb
                     ),
                     '{failed_at}',
                     ?::jsonb
                 ),
                 updated_at = NOW()
             WHERE id = ?",
            [
                ImportStatus::FAILED->value,
                json_encode($exception->getMessage()),
                json_encode(now()->toDateTimeString()),
                $this->importId
            ]
        );
    }

    /**
     * Clean up chunk JSON files after successful processing
     */
    protected function cleanupChunkFiles(): void
    {
        try {
            // Get chunks directory from database
            $result = DB::selectOne(
                "SELECT data->>'chunks_directory' as chunks_dir FROM imports WHERE id = ?",
                [$this->importId]
            );

            if (!$result || !$result->chunks_dir) {
                Log::warning("No chunks directory found for import {$this->importId}, skipping cleanup");
                return;
            }

            $chunksPath = storage_path("app/{$result->chunks_dir}");

            // Verify path is within chunks directory before deletion
            $realPath = realpath($chunksPath);
            $expectedPath = realpath(storage_path('app/chunks'));

            if ($realPath && str_starts_with($realPath, $expectedPath)) {
                // Delete all JSON files in the chunks directory
                $files = glob("{$chunksPath}/*.json");
                $deletedCount = 0;

                foreach ($files as $file) {
                    if (unlink($file)) {
                        $deletedCount++;
                    }
                }

                // Remove the directory if empty
                if (is_dir($chunksPath) && count(scandir($chunksPath)) === 2) {
                    rmdir($chunksPath);
                }

                Log::info("Cleaned up {$deletedCount} chunk files for import {$this->importId}");
            } else {
                Log::warning("Invalid chunks path for import {$this->importId}, skipping cleanup");
            }

        } catch (\Exception $e) {
            // Don't fail the job if cleanup fails, just log it
            Log::error("Failed to cleanup chunk files for import {$this->importId}: " . $e->getMessage());
        }
    }

    /**
     * Clean up original CSV file after successful processing
     */
    protected function cleanupOriginalCsv(): void
    {
        try {
            $result = DB::selectOne(
                "SELECT file_path FROM imports WHERE id = ?",
                [$this->importId]
            );

            if (!$result || !$result->file_path) {
                return;
            }

            $csvPath = storage_path("app/{$result->file_path}");

            if (file_exists($csvPath) && unlink($csvPath)) {
                Log::info("Cleaned up original CSV file for import {$this->importId}");
            }

        } catch (\Exception $e) {
            Log::error("Failed to cleanup original CSV for import {$this->importId}: " . $e->getMessage());
        }
    }
}
