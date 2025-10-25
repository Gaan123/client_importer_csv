<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ClientsExport
{
    /**
     * Export all clients to CSV using PostgreSQL streaming cursor
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
            Log::info("Starting PostgreSQL streaming export for clients");

            fputcsv($handle, ['ID', 'Company', 'Email', 'Phone', 'Has Duplicates', 'Created At']);

            $pdo = DB::connection()->getPdo();
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

            $query = "SELECT id, company, email, phone, has_duplicates, created_at FROM clients ORDER BY created_at DESC";

            $stmt = $pdo->prepare($query, [\PDO::ATTR_CURSOR => \PDO::CURSOR_FWDONLY]);
            $stmt->execute();

            $count = 0;
            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                $row[4] = $row[4] ? 'Yes' : 'No';

                fputcsv($handle, $row);
                $count++;

                if ($count % 10000 === 0) {
                    Log::info("Exported {$count} clients...");
                }
            }

            $stmt->closeCursor();
            fclose($handle);

            Log::info("PostgreSQL export completed successfully: {$count} clients exported to {$fullPath}");
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
