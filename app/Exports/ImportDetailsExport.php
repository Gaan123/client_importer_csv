<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportDetailsExport
{
    protected $importId;

    public function __construct(int $importId)
    {
        $this->importId = $importId;
    }

    /**
     * Export to CSV using PostgreSQL streaming cursor
     */
    public function exportToCsv(string $filePath): bool
    {
        set_time_limit(0);

        $fullPath = Storage::disk('local')->path($filePath);
        $directory = dirname($fullPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $handle = fopen($fullPath, 'w');
        if (!$handle) {
            throw new \Exception("Could not open file for writing: {$fullPath}");
        }

        try {
            Log::info("Starting PostgreSQL streaming export for import {$this->importId}");

            fputcsv($handle, ['Row #', 'Company', 'Email', 'Phone', 'Status', 'Is Duplicate', 'Error']);

            $pdo = DB::connection()->getPdo();
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

            $query = "
                SELECT
                    row_data->>'row_number' as row_number,
                    COALESCE(row_data->'data'->>'company', '') as company,
                    COALESCE(row_data->'data'->>'email', '') as email,
                    COALESCE(row_data->'data'->>'phone', '') as phone,
                    COALESCE(row_data->>'status', '') as status,
                    CASE
                        WHEN (row_data->>'is_duplicate')::boolean IS TRUE THEN 'Yes'
                        ELSE 'No'
                    END as is_duplicate,
                    COALESCE(row_data->>'error', '') as error
                FROM (
                    SELECT jsonb_array_elements(data->'rows') as row_data
                    FROM imports
                    WHERE id = :id
                ) subquery
                ORDER BY (row_data->>'row_number')::integer
            ";

            $stmt = $pdo->prepare($query, [\PDO::ATTR_CURSOR => \PDO::CURSOR_FWDONLY]);
            $stmt->execute(['id' => $this->importId]);

            $count = 0;
            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                fputcsv($handle, $row);
                $count++;

                if ($count % 10000 === 0) {
                    Log::info("Exported {$count} rows...");
                }
            }

            $stmt->closeCursor();
            fclose($handle);

            Log::info("PostgreSQL export completed successfully: {$count} rows exported to {$fullPath}");
            return true;

        } catch (\Exception $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            Log::error("PostgreSQL export failed: " . $e->getMessage());

            if (file_exists($fullPath)) {
                unlink($fullPath);
            }

            throw $e;
        }
    }
}
