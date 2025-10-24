<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImportResource;
use App\Models\Import;
use App\Exports\ImportDetailsExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class ImportsController extends Controller
{
    /**
     * Display a listing of imports.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $status = $request->get('status');
        $importableType = $request->get('importable_type');

        $query = Import::query()->select([
            'id',
            'status',
            'total_rows',
            'file_signature',
            'importable_type',
            'created_at',
            'updated_at',
            'metadata',
            DB::raw("(data->'summary'->>'imported')::integer as success_count"),
            DB::raw("(data->'summary'->>'failed')::integer as failed_count"),
            DB::raw("(data->'summary'->>'duplicates')::integer as duplicates_count")
        ])->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }


        $imports = $query->paginate($perPage);

        return ImportResource::collection($imports);
    }

    /**
     * Display the specified import's client logs (failed and succeeded).
     * Uses PostgreSQL JSON operators to efficiently flatten and filter data.
     */
    public function show(Request $request, $importId)
    {
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $status = $request->get('status');
        $isDuplicate = $request->get('is_duplicate');
        $offset = ($page - 1) * $perPage;

        // Get import metadata WITHOUT loading the data column
        $importMeta = DB::selectOne("
            SELECT id, status, total_rows, importable_type
            FROM imports
            WHERE id = ?
        ", [$importId]);

        if (!$importMeta) {
            return response()->json([
                'message' => 'Import not found.',
            ], 404);
        }

        // Build WHERE clause for status filtering
        $statusFilter = '';
        if ($status === 'failed') {
            $statusFilter = "AND row->>'status' = 'failed'";
        } elseif ($status === 'success') {
            $statusFilter = "AND row->>'status' = 'success'";
        }

        // Build WHERE clause for is_duplicate filtering
        $duplicateFilter = '';
        if ($isDuplicate === 'true' || $isDuplicate === '1') {
            $duplicateFilter = "AND (row->>'is_duplicate')::boolean = true";
        } elseif ($isDuplicate === 'false' || $isDuplicate === '0') {
            $duplicateFilter = "AND (row->>'is_duplicate')::boolean = false";
        }

        // Use PostgreSQL to flatten JSON data and paginate in one query
        // jsonb_array_elements expands the rows array into individual rows
        // ->> operator extracts text values from JSON
        $clients = DB::select("
            SELECT
                (row->>'row_number')::integer as row_number,
                row->'data'->>'company' as company,
                row->'data'->>'email' as email,
                row->'data'->>'phone' as phone,
                row->>'status' as status,
                (row->>'is_duplicate')::boolean as is_duplicate,
                row->>'error' as error
            FROM imports,
                 jsonb_array_elements(data->'rows') as row
            WHERE imports.id = ?
            {$statusFilter}
            {$duplicateFilter}
            ORDER BY (row->>'row_number')::integer
            LIMIT ? OFFSET ?
        ", [$importId, $perPage, $offset]);

        // Get total count for pagination
        $totalQuery = DB::select("
            SELECT COUNT(*) as total
            FROM imports,
                 jsonb_array_elements(data->'rows') as row
            WHERE imports.id = ?
            {$statusFilter}
            {$duplicateFilter}
        ", [$importId]);

        $total = $totalQuery[0]->total ?? 0;

        // Get summary from the data JSON field without loading entire column
        $summary = DB::selectOne("
            SELECT
                (data->'summary'->>'total')::integer as total,
                (data->'summary'->>'imported')::integer as imported,
                (data->'summary'->>'failed')::integer as failed,
                (data->'summary'->>'duplicates')::integer as duplicates
            FROM imports
            WHERE id = ?
        ", [$importId]);

        // Get export metadata
        $exportMeta = DB::selectOne("
            SELECT metadata
            FROM imports
            WHERE id = ?
        ", [$importId]);

        $metadata = json_decode($exportMeta->metadata ?? '{}', true);
        $exportStatus = $metadata['export_status'] ?? null;
        $exportPath = $metadata['export_path'] ?? null;

        return response()->json([
            'import_id' => $importMeta->id,
            'status' => $importMeta->status,
            'total_rows' => $importMeta->total_rows,
            'summary' => [
                'total' => $summary->total ?? 0,
                'imported' => $summary->imported ?? 0,
                'failed' => $summary->failed ?? 0,
                'duplicates' => $summary->duplicates ?? 0,
            ],
            'export_status' => $exportStatus,
            'export_available' => $exportStatus === 'completed' && $exportPath !== null,
            'clients' => [
                'data' => $clients,
                'current_page' => (int) $page,
                'per_page' => (int) $perPage,
                'total' => (int) $total,
                'last_page' => (int) ceil($total / $perPage),
                'from' => $total > 0 ? $offset + 1 : null,
                'to' => $total > 0 ? min($offset + $perPage, $total) : null,
            ],
        ]);
    }

    /**
     * Export import details to Excel with RED background for failed rows.
     */
    public function export(Request $request, $importId)
    {
        $import = Import::find($importId);

        if (!$import) {
            return response()->json(['message' => 'Import not found.'], 404);
        }

        $metadata = $import->metadata ?? [];

        if (isset($metadata['export_status'])) {
            if ($metadata['export_status'] === 'pending') {
                return response()->json([
                    'status' => 'pending',
                    'message' => 'Export is being generated. Please wait...'
                ], 202);
            }

            if ($metadata['export_status'] === 'completed' && isset($metadata['export_path'])) {
                if (Storage::disk('local')->exists($metadata['export_path'])) {
                    return Storage::disk('local')->download($metadata['export_path'], "import_{$importId}_export.xlsx");
                }
            }
        }

        $import->metadata = array_merge($metadata, ['export_status' => 'pending']);
        $import->save();

        try {
            $exportPath = "exports/import_{$importId}_" . now()->format('Y-m-d_His') . ".xlsx";

            $rows = DB::select("
                SELECT
                    (row->>'row_number')::integer as row_number,
                    row->'data'->>'company' as company,
                    row->'data'->>'email' as email,
                    row->'data'->>'phone' as phone,
                    row->>'status' as status,
                    (row->>'is_duplicate')::boolean as is_duplicate,
                    row->>'error' as error
                FROM imports,
                     jsonb_array_elements(data->'rows') as row
                WHERE imports.id = ?
                ORDER BY (row->>'row_number')::integer
            ", [$importId]);

            $rowsArray = array_map(function($row) {
                return [
                    'row_number' => $row->row_number,
                    'company' => $row->company,
                    'email' => $row->email,
                    'phone' => $row->phone,
                    'status' => $row->status,
                    'is_duplicate' => $row->is_duplicate,
                    'error' => $row->error,
                ];
            }, $rows);

            Excel::store(new ImportDetailsExport($rowsArray), $exportPath, 'local');

            $import->metadata = array_merge($import->metadata ?? [], [
                'export_status' => 'completed',
                'export_path' => $exportPath,
                'export_generated_at' => now()->toDateTimeString()
            ]);
            $import->save();

            return Storage::disk('local')->download($exportPath, "import_{$importId}_export.xlsx");

        } catch (\Exception $e) {
            $import->metadata = array_merge($import->metadata ?? [], [
                'export_status' => 'failed',
                'export_error' => $e->getMessage()
            ]);
            $import->save();

            return response()->json([
                'message' => 'Failed to generate export.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified import from storage.
     */
    public function destroy(Import $import)
    {
        try {
            // Delete the associated file if it exists
            if ($import->file_path && Storage::disk('local')->exists($import->file_path)) {
                Storage::disk('local')->delete($import->file_path);
            }

            // Delete the import record
            $import->delete();

            return response()->json([
                'message' => 'Import deleted successfully.',
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete import.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
