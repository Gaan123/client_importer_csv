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

            Bus::batch([
                new ProcessClientsImport($importRecord)
            ])
            ->name('Import Clients - ' . $importRecord->id)
            ->dispatch();

            return (new ImportResource($importRecord))
                ->additional(['message' => 'Import queued successfully. Check import status for results.'])
                ->response()
                ->setStatusCode(Response::HTTP_ACCEPTED);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to import clients.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
