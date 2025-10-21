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

    public function model(array $row)
    {
        $this->currentRow++;

        $rowData = [
            'row_number' => $this->currentRow,
            'data' => $row,
            'is_duplicate' => false,
            'status' => 'pending',
            'error' => null,
        ];

        try {
            $client = Clients::create([
                'company' => $row['company'] ?? null,
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
                'has_duplicates' =>  false,
                'extras' => null,
            ]);

            $rowData['status'] = 'success';
            $rowData['is_duplicate'] = false;
            $this->importedCount++;

        } catch (\Illuminate\Database\QueryException $e) {
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();

            // Detect duplicate constraint violations from both MySQL and PostgreSQL
            // MySQL: error code 23000, PostgreSQL: error code 23505
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
                $rowData['is_duplicate'] = false;
                $rowData['error'] = $e->getMessage();
                $this->failedCount++;
            }
        }

        $this->allRows[] = $rowData;
        return null;
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
