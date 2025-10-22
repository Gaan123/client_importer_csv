<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Imports\ClientsImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportService
{
    protected array $allowedExtensions = ['csv', 'txt'];

    public function validateFile(UploadedFile $file): array
    {
        $errors = [];

        $detectedType = $this->detectActualFileType($file);
        if ($detectedType !== 'csv') {
            $errors[] = "File content validation failed. File does not appear to be a valid CSV file (detected as: {$detectedType}).";
        }

        $uploadedExtension = strtolower($file->getClientOriginalExtension());
        if (!in_array($uploadedExtension, $this->allowedExtensions)) {
            $errors[] = "Invalid file extension: .{$uploadedExtension}. Expected: " . implode(', ', $this->allowedExtensions);
        }

        $structureValidation = $this->validateCsvStructure($file);
        if (!$structureValidation['valid']) {
            $errors[] = $structureValidation['error'];
        }

        return $errors;
    }

    /**
     * Detect the actual file type by analyzing file content, not just extension or MIME type
     * This prevents users from renaming a .exe to .csv and uploading it
     */
    protected function detectActualFileType(UploadedFile $file): string
    {
        // Open file in binary mode to read raw bytes
        $handle = fopen($file->getRealPath(), 'rb');
        if (!$handle) {
            return 'unknown';
        }

        // Read first 8KB of file content for analysis
        $header = fread($handle, 8192);
        fclose($handle);

        // Check if file contains binary data (executable, image, etc.)
        if ($this->isBinaryFile($header)) {
            return 'binary';
        }

        // Check for CSV delimiters: comma, semicolon, or tab
        if (preg_match('/[,;\t]/', $header)) {
            return 'csv';
        }

        // Check if it's plain text (all printable characters)
        if (ctype_print(str_replace(["\r", "\n", "\t"], '', $header))) {
            return 'text';
        }

        return 'unknown';
    }

    /**
     * Check if file content is binary (not text-based)
     * Binary files include: executables, images, videos, etc.
     */
    protected function isBinaryFile(string $content): bool
    {
        // Define valid text characters in hexadecimal:
        // \x09 = Tab, \x0A = Line Feed (LF), \x0D = Carriage Return (CR)
        // \x20-\x7E = Printable ASCII characters (space to tilde ~)
        $textChars = "\x09\x0A\x0D\x20-\x7E";

        // Check for NULL bytes (\x00) which indicate binary content
        $binaryChars = substr_count($content, "\x00");

        if ($binaryChars > 0) {
            return true;
        }

        // Count how many non-text characters exist in first 512 bytes
        $nonTextChars = 0;
        $contentLength = min(strlen($content), 512);

        for ($i = 0; $i < $contentLength; $i++) {
            $char = $content[$i];
            if (!preg_match("/[{$textChars}]/", $char)) {
                $nonTextChars++;
            }
        }

        // If more than 30% of characters are non-text, consider it binary
        return ($nonTextChars / $contentLength) > 0.3;
    }

    /**
     * Validate CSV file structure by checking header and row consistency
     * Ensures all rows have the same number of columns as the header
     */
    protected function validateCsvStructure(UploadedFile $file): array
    {
        try {
            $handle = fopen($file->getRealPath(), 'r');
            if (!$handle) {
                return ['valid' => false, 'error' => 'Cannot open file for reading.'];
            }

            // Read and validate header row
            $headerRow = fgetcsv($handle);
            if ($headerRow === false || empty($headerRow)) {
                fclose($handle);
                return ['valid' => false, 'error' => 'CSV file must have a header row.'];
            }

            // Check if CSV is completely empty (header exists but has no content)
            $headerCount = count($headerRow);
            if ($headerCount === 1 && empty(trim($headerRow[0]))) {
                fclose($handle);
                return ['valid' => false, 'error' => 'CSV file appears to be empty or improperly formatted.'];
            }

            // Validate first 10 data rows to ensure column count matches header
            $rowNumber = 1;
            $inconsistentRows = [];

            while (($row = fgetcsv($handle)) !== false && $rowNumber <= 10) {
                if (count($row) !== $headerCount) {
                    $inconsistentRows[] = $rowNumber + 1; // +1 because header is row 1
                }
                $rowNumber++;
            }

            fclose($handle);

            // Report any rows with mismatched column counts
            if (!empty($inconsistentRows)) {
                return [
                    'valid' => false,
                    'error' => 'CSV structure inconsistent. Rows ' . implode(', ', $inconsistentRows) . ' have different column counts than header.'
                ];
            }

            return ['valid' => true];

        } catch (\Exception $e) {
            return ['valid' => false, 'error' => 'Error validating CSV structure: ' . $e->getMessage()];
        }
    }

    protected function detectFileExtension(UploadedFile $file): string
    {
        $type = $this->detectActualFileType($file);
        return $type === 'csv' ? 'csv' : 'txt';
    }

    /**
     * Generate unique SHA-256 signature for file content
     * Used to detect if the same file has been uploaded before
     * Reads file in 8KB chunks for memory efficiency
     */
    public function generateFileSignature(UploadedFile $file): string
    {
        $handle = fopen($file->getRealPath(), 'r');
        $hashContext = hash_init('sha256');

        // Read file in 8KB chunks to avoid memory issues with large files
        while (!feof($handle)) {
            hash_update($hashContext, fread($handle, 8192));
        }

        fclose($handle);

        // Returns 64-character hexadecimal hash
        return hash_final($hashContext);
    }

    public function checkDuplicate(string $signature): ?Import
    {
        return Import::getBySignature($signature);
    }

    public function storeFile(UploadedFile $file, string $signature): string
    {
        $path = $file->store('imports', 'local');
        return $path;
    }

    public function saveImportRecord(
        UploadedFile $file,
        string $signature,
        string $filePath,
        string $importableType,
        array $metadata = []
    ): Import {
        $detectedExtension = $this->detectFileExtension($file);

        $defaultMetadata = [
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'detected_extension' => $detectedExtension,
            'file_size' => $file->getSize(),
            'uploaded_at' => now()->toDateTimeString(),
        ];

        return Import::create([
            'importable_type' => $importableType,
            'file_signature' => $signature, // For large files: temp signature; small files: full hash
            'file_path' => $filePath,
            'status' => ImportStatus::PENDING,
            'total_rows' => 0,
            'metadata' => array_merge($defaultMetadata, $metadata),
            'data' => [],
        ]);
    }

    /**
     * Process CSV import using Laravel Excel
     * Stores all rows with is_duplicate flags in imports.data JSON column
     */
    public function processImport(Import $import, string $importClass): array
    {
        try {
            $import->update(['status' => ImportStatus::PROCESSING]);

            $filePath = Storage::disk('local')->path($import->file_path);

            $importer = new $importClass();
            Excel::import($importer, $filePath);

            $imported = $importer->getImportedCount();
            $failed = $importer->getFailedCount();
            $allRows = $importer->getAllRows();

            $status = ImportStatus::COMPLETED;
            if ($failed > 0 && $imported > 0) {
                $status = ImportStatus::COMPLETED_WITH_ERRORS;
            } elseif ($failed > 0 && $imported === 0) {
                $status = ImportStatus::FAILED;
            }

            $import->update([
                'status' => $status,
                'total_rows' => $imported + $failed,
                'data' => [
                    'rows' => $allRows,
                    'summary' => [
                        'total' => $imported + $failed,
                        'imported' => $imported,
                        'failed' => $failed,
                        'duplicates' => count(array_filter($allRows, fn($row) => $row['is_duplicate'] === true)),
                    ],
                ],
            ]);

            return [
                'success' => true,
                'imported' => $imported,
                'failed' => $failed,
                'total' => $imported + $failed,
                'duplicates' => count(array_filter($allRows, fn($row) => $row['is_duplicate'] === true)),
                'data' => $allRows,
            ];

        } catch (\Exception $e) {
            $import->update([
                'status' => ImportStatus::FAILED,
                'data' => ['error' => $e->getMessage()],
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function processImportInQueue(Import $import, string $importClass): void
    {
        $filePath = Storage::disk('local')->path($import->file_path);
        $import->update(['status' => 'queued']);

        Excel::queueImport(new $importClass($import), $filePath);
    }
}
