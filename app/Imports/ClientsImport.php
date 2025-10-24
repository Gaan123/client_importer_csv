<?php

namespace App\Imports;

use App\Models\Clients;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;

class ClientsImport implements
    ToModel,
    WithHeadingRow,
    WithValidation,
    WithChunkReading,
    SkipsOnError,
    SkipsOnFailure
{
    use Importable;

    protected array $errors = [];
    protected array $failures = [];
    protected int $importedCount = 0;
    protected int $failedCount = 0;
    protected array $allRows = [];
    protected int $currentRow = 0;

    // Batch processing
    protected array $batchBuffer = [];
    protected array $batchMetadata = [];
    protected int $batchSize = 1000;

    public function model(array $row)
    {
        $this->currentRow++;

        // Add row to batch buffer
        $this->batchBuffer[] = [
            'company' => $row['company'] ?? null,
            'email' => $row['email'] ?? null,
            'phone' => $row['phone'] ?? null,
            'has_duplicates' => false,
            'extras' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->batchMetadata[] = [
            'row_number' => $this->currentRow,
            'data' => $row,
        ];

        // Process batch when buffer is full
        if (count($this->batchBuffer) >= $this->batchSize) {
            $this->processBatch();
        }

        return null;
    }

    /**
     * Process accumulated batch using temporary table for duplicate tracking
     */
    protected function processBatch(): void
    {
        if (empty($this->batchBuffer)) {
            return;
        }

        // Validate all rows before batch insert
        $validBuffer = [];
        $validMetadata = [];

        foreach ($this->batchBuffer as $idx => $row) {
            $company = $row['company'] ?? '';
            $email = $row['email'] ?? '';
            $phone = $row['phone'] ?? '';

            // Validate row data
            $validationError = $this->validateRowData($company, $email, $phone);

            if ($validationError) {
                // Row failed validation - track as failed
                $rowData = [
                    'row_number' => $this->batchMetadata[$idx]['row_number'],
                    'data' => $this->batchMetadata[$idx]['data'],
                    'is_duplicate' => false,
                    'status' => 'failed',
                    'error' => $validationError,
                ];
                $this->failedCount++;
                $this->allRows[] = $rowData;
            } else {
                // Row passed validation - add to valid batch
                $validBuffer[] = $row;
                $validMetadata[] = $this->batchMetadata[$idx];
            }
        }

        // Only process valid rows
        if (empty($validBuffer)) {
            $this->batchBuffer = [];
            $this->batchMetadata = [];
            return;
        }

        try {
            $tempTableName = 'temp_batch_' . uniqid();

            // Create temporary table
            DB::statement("
                CREATE TEMP TABLE {$tempTableName} (
                    row_index INT,
                    company VARCHAR(255),
                    email VARCHAR(255),
                    phone VARCHAR(255)
                ) ON COMMIT DROP
            ");

            // Prepare data for temp table
            $tempInserts = [];
            foreach ($validBuffer as $idx => $row) {
                $tempInserts[] = [
                    $idx,
                    $row['company'] ?? '',
                    $row['email'] ?? '',
                    $row['phone'] ?? '',
                ];
            }

            // Insert into temp table
            $placeholders = implode(',', array_fill(0, count($tempInserts), '(?, ?, ?, ?)'));
            $flatValues = array_merge(...$tempInserts);

            DB::statement("
                INSERT INTO {$tempTableName} (row_index, company, email, phone)
                VALUES {$placeholders}
            ", $flatValues);

            // Batch insert with duplicate detection
            $insertedRows = DB::select("
                INSERT INTO clients (company, email, phone, has_duplicates, extras, created_at, updated_at)
                SELECT company, email, phone, false, null, NOW(), NOW()
                FROM {$tempTableName}
                ON CONFLICT (company, email, phone) DO NOTHING
                RETURNING company, email, phone
            ");

            // Build lookup of successfully inserted rows
            $insertedLookup = [];
            foreach ($insertedRows as $row) {
                $key = $row->company . '|' . $row->email . '|' . $row->phone;
                $insertedLookup[$key] = true;
            }

            // Update stats and allRows based on what was inserted
            foreach ($validMetadata as $idx => $metadata) {
                $company = $validBuffer[$idx]['company'] ?? '';
                $email = $validBuffer[$idx]['email'] ?? '';
                $phone = $validBuffer[$idx]['phone'] ?? '';
                $key = $company . '|' . $email . '|' . $phone;

                $isDuplicate = !isset($insertedLookup[$key]);

                $rowData = [
                    'row_number' => $metadata['row_number'],
                    'data' => $metadata['data'],
                    'is_duplicate' => $isDuplicate,
                    'status' => $isDuplicate ? 'failed' : 'success',
                    'error' => $isDuplicate
                        ? 'Duplicate entry: A client with this company, email, and phone combination already exists.'
                        : null,
                ];

                if ($isDuplicate) {
                    $this->failedCount++;
                } else {
                    $this->importedCount++;
                }

                $this->allRows[] = $rowData;
            }

        } catch (\Exception $e) {
            // Fallback to row-by-row if batch fails
            $this->processBatchRowByRow($validBuffer, $validMetadata);
        }

        // Clear buffers
        $this->batchBuffer = [];
        $this->batchMetadata = [];
    }

    /**
     * Validate row data before insert
     * Returns error message if validation fails, null if passes
     */
    protected function validateRowData(string $company, string $email, string $phone): ?string
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
     * Fallback: process batch row-by-row
     */
    protected function processBatchRowByRow(array $validBuffer, array $validMetadata): void
    {
        foreach ($validBuffer as $idx => $insertData) {
            $metadata = $validMetadata[$idx];

            $rowData = [
                'row_number' => $metadata['row_number'],
                'data' => $metadata['data'],
                'is_duplicate' => false,
                'status' => 'pending',
                'error' => null,
            ];

            try {
                DB::transaction(function () use ($insertData) {
                    Clients::create($insertData);
                });

                $rowData['status'] = 'success';
                $this->importedCount++;

            } catch (\Illuminate\Database\QueryException $e) {
                $errorCode = $e->getCode();
                $errorMessage = $e->getMessage();

                if ($errorCode === '23000' || $errorCode === '23505' ||
                    str_contains($errorMessage, 'duplicate key') ||
                    str_contains($errorMessage, 'Duplicate entry') ||
                    str_contains($errorMessage, 'unique constraint')) {
                    $rowData['status'] = 'failed';
                    $rowData['is_duplicate'] = true;
                    $rowData['error'] = 'Duplicate entry: A client with this company, email, and phone combination already exists.';
                    $this->failedCount++;
                } else {
                    $rowData['status'] = 'failed';
                    $rowData['error'] = $e->getMessage();
                    $this->failedCount++;
                }
            } catch (\Throwable $e) {
                $rowData['status'] = 'failed';
                $rowData['error'] = $e->getMessage();
                $this->failedCount++;
            }

            $this->allRows[] = $rowData;
        }
    }

    /**
     * Process any remaining rows in buffer after all chunks are read
     */
    public function __destruct()
    {
        $this->processBatch();
    }

    /**
     * Manually flush the batch buffer
     * Call this before getting results to ensure all rows are processed
     */
    public function flushBatch(): void
    {
        $this->processBatch();
    }

    public function rules(): array
    {
        return [
            'company' => 'required|string|max:255',
            'email' => 'required|email|max:55',
            'phone' => 'required|string|max:22',
            'has_duplicates' => 'nullable|boolean',
            'extras' => 'nullable|json',
        ];
    }

    public function customValidationMessages()
    {
        return [
            'company.required' => 'Company name is required',
            'email.required' => 'Email is required',
            'email.email' => 'Email must be a valid email address',
            'phone.required' => 'Phone number is required',
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function onError(Throwable $e)
    {
        $this->failedCount++;
        $this->errors[] = $e->getMessage();
    }

    /**
     * Handles validation failures (null fields, invalid email, length exceeded, etc.)
     * Stores failed rows in allRows with validation error messages
     */
    public function onFailure(Failure ...$failures)
    {
        foreach ($failures as $failure) {
            $this->failedCount++;

            $errorMessages = [];
            foreach ($failure->errors() as $error) {
                $errorMessages[] = $error;
            }

            $this->failures[] = [
                'row' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ];

            $this->allRows[] = [
                'row_number' => $failure->row(),
                'data' => $failure->values(),
                'is_duplicate' => false,
                'status' => 'failed',
                'error' => implode(', ', $errorMessages),
            ];
        }
    }

    public function getImportedCount(): int
    {
        return $this->importedCount;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFailures(): array
    {
        return $this->failures;
    }

    public function getAllRows(): array
    {
        return $this->allRows;
    }
}
