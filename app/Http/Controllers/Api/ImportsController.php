<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImportResource;
use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            'metadata'
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
        $type = $request->get('type'); // 'failed' or 'success'
        $isDuplicate = $request->get('is_duplicate'); // 'true', 'false', or null
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
        if ($type === 'failed') {
            $statusFilter = "AND row->>'status' = 'failed'";
        } elseif ($type === 'success') {
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
