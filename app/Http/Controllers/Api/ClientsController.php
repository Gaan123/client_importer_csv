<?php

namespace App\Http\Controllers\Api;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportClientsRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\ImportResource;
use App\Jobs\DetectSingleClientDuplicate;
use App\Jobs\ProcessClientsImport;
use App\Models\Clients;
use App\Services\ImportService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
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
}
