<?php

namespace App\Http\Controllers\Api;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportClientsRequest;
use App\Http\Resources\ImportResource;
use App\Jobs\ProcessClientsImport;
use App\Models\Clients;
use App\Services\ImportService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpFoundation\Response;

class ClientsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Clients $client)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Clients $client)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Clients $client)
    {
        //
    }

    /**
     * Import clients from CSV file.
     */
    public function import(ImportClientsRequest $request, ImportService $importService)
    {
        try {
            $file = $request->file('file');
            $fileSize = $file->getSize();
            $isLargeFile = $fileSize > 10 * 1024 * 1024; // >10MB

            // Fast-track for large files: Store immediately, validate in queue
            if ($isLargeFile) {
                return $this->handleLargeFileImport($file, $importService);
            }

            // Standard processing for small files (<10MB)
            return $this->handleSmallFileImport($file, $importService);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to import clients.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Handle large file import (>10MB)
     * Store immediately without validation/signature, process everything in queue
     */
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

        // Create import record with pending_large_csv status
        $importRecord = $importService->saveImportRecord(
            $file,
            $quickSignature, // Temporary signature (33 chars, fits in 64 limit)
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

        // Dispatch single job for small files
        $jobs[] = new ProcessClientsImport($importRecord);

        Bus::batch($jobs)
            ->name('Import Clients - ' . $importRecord->id)
            ->dispatch();

        return (new ImportResource($importRecord))
            ->additional(['message' => 'Import queued successfully. Check import status for results.'])
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
