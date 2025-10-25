<?php

namespace App\Http\Controllers\Api;

use App\Enums\ImportStatus;
use App\Exports\ClientsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportClientsRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\ImportResource;
use App\Jobs\DetectSingleClientDuplicate;
use App\Jobs\GenerateClientsExport;
use App\Jobs\ProcessClientsImport;
use App\Models\Clients;
use App\Services\ImportService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ClientsController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');
        $hasDuplicates = $request->get('has_duplicates');

        $query = Clients::query()->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('company', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        if ($hasDuplicates !== null) {
            $query->where('has_duplicates', filter_var($hasDuplicates, FILTER_VALIDATE_BOOLEAN));
        }

        $clients = $query->paginate($perPage);

        return ClientResource::collection($clients);
    }

    public function store(StoreClientRequest $request)
    {
        try {
            $client = Clients::create([
                'company' => $request->company,
                'email' => $request->email,
                'phone' => $request->phone,
                'has_duplicates' => false,
                'extras' => null,
            ]);

            DetectSingleClientDuplicate::dispatch($client->id)
                ->onQueue('imports')
                ->delay(now()->addSeconds(2));

            return (new ClientResource($client))
                ->additional(['message' => 'Client created successfully.'])
                ->response()
                ->setStatusCode(Response::HTTP_CREATED);

        } catch (\Illuminate\Database\QueryException $e) {
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();

            if ($errorCode === '23000' || $errorCode === '23505' ||
                str_contains($errorMessage, 'duplicate key') ||
                str_contains($errorMessage, 'Duplicate entry') ||
                str_contains($errorMessage, 'unique constraint')) {
                return response()->json([
                    'message' => 'Client already exists.',
                    'error' => 'A client with this company, email, and phone combination already exists.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return response()->json([
                'message' => 'Failed to create client.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show(Clients $client)
    {
        return new ClientResource($client);
    }

    public function duplicates(Clients $client)
    {
        if (!$client->has_duplicates || !$client->extras || !isset($client->extras['duplicate_ids'])) {
            return response()->json([
                'message' => 'This client has no duplicates.',
                'data' => []
            ]);
        }

        $duplicateIds = $client->extras['duplicate_ids'];
        $allDuplicateIds = [];

        if (isset($duplicateIds['company'])) {
            $allDuplicateIds = array_merge($allDuplicateIds, $duplicateIds['company']);
        }
        if (isset($duplicateIds['email'])) {
            $allDuplicateIds = array_merge($allDuplicateIds, $duplicateIds['email']);
        }
        if (isset($duplicateIds['phone'])) {
            $allDuplicateIds = array_merge($allDuplicateIds, $duplicateIds['phone']);
        }

        $allDuplicateIds = array_unique($allDuplicateIds);

        $duplicates = Clients::whereIn('id', $allDuplicateIds)->get();

        return ClientResource::collection($duplicates)
            ->additional([
                'message' => 'Duplicates retrieved successfully.',
                'original_client' => new ClientResource($client)
            ]);
    }

    public function update(UpdateClientRequest $request, Clients $client)
    {
        try {
            $client->update($request->validated());

            DetectSingleClientDuplicate::dispatch($client->id)
                ->onQueue('imports')
                ->delay(now()->addSeconds(2));

            return (new ClientResource($client))
                ->additional(['message' => 'Client updated successfully.']);

        } catch (\Illuminate\Database\QueryException $e) {
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();

            if ($errorCode === '23000' || $errorCode === '23505' ||
                str_contains($errorMessage, 'duplicate key') ||
                str_contains($errorMessage, 'Duplicate entry') ||
                str_contains($errorMessage, 'unique constraint')) {
                return response()->json([
                    'message' => 'Client already exists.',
                    'error' => 'A client with this company, email, and phone combination already exists.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return response()->json([
                'message' => 'Failed to update client.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(Clients $client)
    {
        try {
            $client->delete();

            return response()->json([
                'message' => 'Client deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete client.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function import(ImportClientsRequest $request, ImportService $importService)
    {
        try {
            $file = $request->file('file');
            $fileSize = $file->getSize();
            $isLargeFile = $fileSize > 10 * 1024 * 1024;

            if ($isLargeFile) {
                return $this->handleLargeFileImport($file, $importService);
            }

            return $this->handleSmallFileImport($file, $importService);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to import clients.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function handleLargeFileImport(UploadedFile $file, ImportService $importService)
    {
        // Quick extension check only
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['csv', 'txt'])) {
            return response()->json([
                'message' => 'File validation failed.',
                'errors' => ["Invalid file extension: .{$extension}. Expected: csv, txt"],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Generate signature for large files using just file size
        // Format: L{filesize} - simple identifier
        $quickSignature = 'L' . $file->getSize();

        // Check for duplicate upload based on signature
        $existingImport = $importService->checkDuplicate($quickSignature);
        if ($existingImport) {
            return (new ImportResource($existingImport))
                ->additional(['message' => 'A file with this size has already been imported.'])
                ->response()
                ->setStatusCode(Response::HTTP_CONFLICT);
        }

        // Store file immediately without reading it
        $path = $file->store('imports', 'local');

        $importRecord = $importService->saveImportRecord(
            $file,
            $quickSignature,
            $path,
            'clients',
            ['is_large_file' => true]
        );

        $importRecord->update(['status' => ImportStatus::PENDING_LARGE_CSV]);

        // Queue single job that will handle validation, signature, chunking, and processing
        Bus::batch([
            new ProcessClientsImport($importRecord)
        ])
        ->name('Import Large CSV - ' . $importRecord->id)
        ->dispatch();

        return (new ImportResource($importRecord))
            ->additional(['message' => 'Large file uploaded successfully. Processing in background. Check import status for results.'])
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * Handle small file import (<10MB)
     * Full validation and signature before queuing
     */
    private function handleSmallFileImport(UploadedFile $file, ImportService $importService)
    {
        $validationErrors = $importService->validateFile($file);
        if (!empty($validationErrors)) {
            return response()->json([
                'message' => 'File validation failed.',
                'errors' => $validationErrors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $signature = $importService->generateFileSignature($file);

        $existingImport = $importService->checkDuplicate($signature);
        if ($existingImport) {
            return (new ImportResource($existingImport))
                ->additional(['message' => 'This file has already been imported.'])
                ->response()
                ->setStatusCode(Response::HTTP_CONFLICT);
        }

        $filePath = $importService->storeFile($file, $signature);

        $importRecord = $importService->saveImportRecord(
            $file,
            $signature,
            $filePath,
            'clients'
        );

        $importRecord->update(['status' => ImportStatus::QUEUED]);

        $jobs[] = new ProcessClientsImport($importRecord);

        Bus::batch($jobs)
            ->name('Import Clients - ' . $importRecord->id)
            ->dispatch();

        return (new ImportResource($importRecord))
            ->additional(['message' => 'Import queued successfully. Check import status for results.'])
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function export(Request $request)
    {
        try {
            $totalClients = Clients::count();

            if ($totalClients > 10000) {
                $exportId = Str::uuid()->toString();

                Cache::put("client_export_{$exportId}", [
                    'status' => 'queued',
                    'progress' => 0
                ], now()->addHours(2));

                GenerateClientsExport::dispatch($exportId);

                return response()->json([
                    'message' => 'Export queued successfully. Please check status.',
                    'export_id' => $exportId,
                    'async' => true
                ], Response::HTTP_ACCEPTED);
            }

            $exportPath = "exports/clients_" . now()->format('Y-m-d_His') . ".csv";
            $exporter = new ClientsExport();
            $exporter->exportToCsv($exportPath);

            $fullPath = Storage::disk('local')->path($exportPath);

            return response()->download($fullPath)->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to export clients.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function exportStatus(string $exportId)
    {
        $status = Cache::get("client_export_{$exportId}");

        if (!$status) {
            return response()->json([
                'message' => 'Export not found or expired.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json($status);
    }

    public function exportDownload(string $exportId)
    {
        $status = Cache::get("client_export_{$exportId}");

        if (!$status || $status['status'] !== 'completed') {
            return response()->json([
                'message' => 'Export not ready or not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $fullPath = Storage::disk('local')->path($status['path']);

        if (!file_exists($fullPath)) {
            return response()->json([
                'message' => 'Export file not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->download($fullPath)->deleteFileAfterSend(true);
    }

    public function exports()
    {
        try {
            $exports = [];
            $files = Storage::disk('local')->files('exports');

            foreach ($files as $file) {
                if (str_ends_with($file, '.csv') && str_contains($file, 'clients_')) {
                    $filename = basename($file);
                    $fullPath = Storage::disk('local')->path($file);

                    $exports[] = [
                        'filename' => $filename,
                        'path' => $file,
                        'size' => filesize($fullPath),
                        'created_at' => date('Y-m-d H:i:s', filemtime($fullPath)),
                    ];
                }
            }

            usort($exports, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });

            return response()->json([
                'data' => $exports
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to list exports.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function downloadExportFile(string $filename)
    {
        try {
            $path = 'exports/' . $filename;

            if (!Storage::disk('local')->exists($path)) {
                return response()->json([
                    'message' => 'Export file not found.',
                ], Response::HTTP_NOT_FOUND);
            }

            $fullPath = Storage::disk('local')->path($path);

            return response()->download($fullPath, $filename);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to download export.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function deleteExport(string $filename)
    {
        try {
            $path = 'exports/' . $filename;

            if (!Storage::disk('local')->exists($path)) {
                return response()->json([
                    'message' => 'Export file not found.',
                ], Response::HTTP_NOT_FOUND);
            }

            Storage::disk('local')->delete($path);

            return response()->json([
                'message' => 'Export deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete export.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
