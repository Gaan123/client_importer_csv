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

            $processedRows = [];
            $rowNumber = ($this->chunkIndex * 10000) + 1; // Estimate row numbers based on chunk index

            // Use batch insert with temporary table for duplicate tracking
            $processedRows = $this->batchInsertWithDuplicateTracking($rows, $rowNumber, $stats);

            $this->updateChunkStats($stats, $processedRows);

            Log::info("Chunk {$this->chunkIndex} completed: Imported={$stats['imported']}, Failed={$stats['failed']}, Duplicates={$stats['duplicates']}");

        } catch (\Exception $e) {
            Log::error("Chunk {$this->chunkIndex} failed for import {$this->importId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Batch insert rows with duplicate tracking using PostgreSQL COPY + temp table
     * Returns array of processed rows with duplicate status
     */
    protected function batchInsertWithDuplicateTracking(array $rows, int $startRowNumber, array &$stats): array
    {
        if (empty($rows)) {
            return [];
        }

        $processedRows = [];
        $rowNumber = $startRowNumber;

        // Validate all rows before attempting COPY
        $validRows = [];
        $validRowIndices = [];

        foreach ($rows as $idx => $row) {
            $company = $row['company'] ?? '';
            $email = $row['email'] ?? '';
            $phone = $row['phone'] ?? '';

            // Validate row data (same rules as ClientsImport)
            $validationError = $this->validateRow($company, $email, $phone);

            if ($validationError) {
                // Row failed validation - track as failed
                $processedRows[] = [
                    'row_number' => $rowNumber,
                    'data' => [
                        'company' => $company,
                        'email' => $email,
                        'phone' => $phone,
                    ],
                    'is_duplicate' => false,
                    'status' => 'failed',
                    'error' => $validationError,
                ];
                $stats['failed']++;
            } else {
                // Row passed validation - add to batch
                $validRows[] = $row;
                $validRowIndices[$idx] = $rowNumber;
            }

            $rowNumber++;
        }


        // Only process valid rows with COPY
        if (empty($validRows)) {
            return $processedRows;
        }

        $tempTableName = "temp_batch_{$this->importId}_{$this->chunkIndex}";

        // Get raw PDO connection for COPY command
        $pdo = DB::connection()->getPdo();

        $pdo->beginTransaction();

        try {
            // Create temporary table for this batch
            $pdo->exec("
                CREATE TEMP TABLE {$tempTableName} (
                    row_index INT,
                    company VARCHAR(255),
                    email VARCHAR(255),
                    phone VARCHAR(255)
                ) ON COMMIT DELETE ROWS
            ");


            // Use batch INSERT for fast temp table population
            $this->populateTempTableWithCopy($pdo, $tempTableName, $validRows);


            // Insert from temp table into clients, getting back what was inserted
            $stmt = $pdo->query("
                INSERT INTO clients (company, email, phone, has_duplicates, extras, created_at, updated_at)
                SELECT company, email, phone, false, null, NOW(), NOW()
                FROM {$tempTableName}
                ON CONFLICT (company, email, phone) DO NOTHING
                RETURNING company, email, phone
            ");

            $insertedRows = $stmt->fetchAll(\PDO::FETCH_OBJ);

            // Commit transaction
            $pdo->commit();

        } catch (\Exception $e) {
            $pdo->rollBack();

            // Drop temp table if it exists
            try {
                $pdo->exec("DROP TABLE IF EXISTS {$tempTableName}");
            } catch (\Exception $dropError) {
                // Ignore drop errors
            }

            throw $e;
        }


        // Build lookup of successfully inserted rows
        $insertedLookup = [];
        foreach ($insertedRows as $row) {
            $key = $row->company . '|' . $row->email . '|' . $row->phone;
            $insertedLookup[$key] = true;
        }

        // Mark valid rows as success/duplicate based on lookup
        foreach ($validRows as $idx => $row) {
            $company = $row['company'] ?? '';
            $email = $row['email'] ?? '';
            $phone = $row['phone'] ?? '';
            $key = $company . '|' . $email . '|' . $phone;

            $isDuplicate = !isset($insertedLookup[$key]);

            $processedRows[] = [
                'row_number' => $validRowIndices[$idx],
                'data' => [
                    'company' => $company,
                    'email' => $email,
                    'phone' => $phone,
                ],
                'is_duplicate' => $isDuplicate,
                'status' => $isDuplicate ? 'failed' : 'success',
                'error' => $isDuplicate
                    ? 'Duplicate entry: A client with this company, email, and phone combination already exists.'
                    : null,
            ];

            if ($isDuplicate) {
                $stats['duplicates']++;
                $stats['failed']++;
            } else {
                $stats['imported']++;
            }
        }

        // Cleanup: Drop temp table
        try {
            $pdo->exec("DROP TABLE IF EXISTS {$tempTableName}");
        } catch (\Exception $e) {
            // Ignore cleanup errors
            Log::warning("Chunk {$this->chunkIndex}: Failed to drop temp table {$tempTableName}: " . $e->getMessage());
        }

        return $processedRows;
    }

    /**
     * Validate row data before insert
     * Returns error message if validation fails, null if passes
     */
    protected function validateRow(string $company, string $email, string $phone): ?string
    {
        $errors = [];

        // Validate company (required, max 255 chars)
        if (empty(trim($company))) {
            $errors[] = 'Company name is required';
        } elseif (strlen($company) > 255) {
            $errors[] = 'Company name must not exceed 255 characters';
        }

        // Validate email (required, valid format, max 55 chars)
        if (empty(trim($email))) {
            $errors[] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email must be a valid email address';
        } elseif (strlen($email) > 55) {
            $errors[] = 'Email must not exceed 55 characters';
        }

        // Validate phone (required, max 22 chars)
        if (empty(trim($phone))) {
            $errors[] = 'Phone number is required';
        } elseif (strlen($phone) > 22) {
            $errors[] = 'Phone number must not exceed 22 characters';
        }

        return empty($errors) ? null : implode(', ', $errors);
    }

    /**
     * Populate temp table using batch INSERT (fast, works across all environments)
     */
    protected function populateTempTableWithCopy(\PDO $pdo, string $tempTableName, array $rows): void
    {
        // Build batch INSERT statement
        $values = [];
        $params = [];

        foreach ($rows as $idx => $row) {
            $values[] = '(?, ?, ?, ?)';
            $params[] = $idx;
            $params[] = $row['company'] ?? '';
            $params[] = $row['email'] ?? '';
            $params[] = $row['phone'] ?? '';
        }

        // Single batch INSERT for all rows
        $sql = "INSERT INTO {$tempTableName} (row_index, company, email, phone) VALUES " . implode(', ', $values);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    protected function updateChunkStats(array $stats, array $processedRows): void
    {
        DB::transaction(function () use ($stats, $processedRows) {
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

            // Append processed rows to the rows array in data JSON
            // This stores all row details (failed and succeeded) for large files
            DB::statement(
                "UPDATE imports
                 SET data = jsonb_set(
                     COALESCE(data, '{}'::jsonb),
                     '{rows}',
                     (COALESCE(data->'rows', '[]'::jsonb) || ?::jsonb)
                 )
                 WHERE id = ?",
                [json_encode($processedRows), $this->importId]
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
