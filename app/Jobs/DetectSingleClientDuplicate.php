<?php

namespace App\Jobs;

use App\Models\Clients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DetectSingleClientDuplicate implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 60;
    public int $tries = 3;

    public function __construct(
        public int $clientId
    ) {}

    public function handle(): void
    {
        try {
            $client = Clients::find($this->clientId);

            if (!$client) {
                Log::warning("Client {$this->clientId} not found for duplicate detection");
                return;
            }

            $driver = DB::getDriverName();
            if ($driver !== 'pgsql') {
                Log::info("Skipping duplicate detection for client {$this->clientId} - requires PostgreSQL");
                return;
            }

            Log::info("Detecting duplicates for client {$this->clientId}");

            $duplicateIds = [];
            $hasDuplicates = false;

            $companyDups = DB::table('clients')
                ->where('company', $client->company)
                ->where('id', '!=', $this->clientId)
                ->pluck('id')
                ->toArray();

            if (!empty($companyDups)) {
                $duplicateIds['company'] = $companyDups;
                $hasDuplicates = true;
            }

            $emailDups = DB::table('clients')
                ->where('email', $client->email)
                ->where('id', '!=', $this->clientId)
                ->pluck('id')
                ->toArray();

            if (!empty($emailDups)) {
                $duplicateIds['email'] = $emailDups;
                $hasDuplicates = true;
            }

            $phoneDups = DB::table('clients')
                ->where('phone', $client->phone)
                ->where('id', '!=', $this->clientId)
                ->pluck('id')
                ->toArray();

            if (!empty($phoneDups)) {
                $duplicateIds['phone'] = $phoneDups;
                $hasDuplicates = true;
            }

            $client->update([
                'has_duplicates' => $hasDuplicates,
                'extras' => $hasDuplicates ? ['duplicate_ids' => $duplicateIds] : null,
            ]);

            if ($hasDuplicates) {
                Log::info("Client {$this->clientId} marked as having duplicates", [
                    'duplicate_ids' => $duplicateIds
                ]);

                $allRelatedIds = array_unique(array_merge(...array_values($duplicateIds)));

                foreach ($allRelatedIds as $relatedId) {
                    $this->updateRelatedClientDuplicates($relatedId);
                }

                Log::info("Updated " . count($allRelatedIds) . " related clients with their duplicate information");
            } else {
                Log::info("Client {$this->clientId} has no duplicates");
            }

        } catch (\Exception $e) {
            Log::error("Failed to detect duplicates for client {$this->clientId}: " . $e->getMessage());
            throw $e;
        }
    }

    protected function updateRelatedClientDuplicates(int $clientId): void
    {
        $client = Clients::find($clientId);

        if (!$client) {
            return;
        }

        $duplicateIds = [];

        $companyDups = DB::table('clients')
            ->where('company', $client->company)
            ->where('id', '!=', $clientId)
            ->pluck('id')
            ->toArray();

        if (!empty($companyDups)) {
            $duplicateIds['company'] = $companyDups;
        }

        $emailDups = DB::table('clients')
            ->where('email', $client->email)
            ->where('id', '!=', $clientId)
            ->pluck('id')
            ->toArray();

        if (!empty($emailDups)) {
            $duplicateIds['email'] = $emailDups;
        }

        $phoneDups = DB::table('clients')
            ->where('phone', $client->phone)
            ->where('id', '!=', $clientId)
            ->pluck('id')
            ->toArray();

        if (!empty($phoneDups)) {
            $duplicateIds['phone'] = $phoneDups;
        }

        $hasDuplicates = !empty($duplicateIds);

        $client->update([
            'has_duplicates' => $hasDuplicates,
            'extras' => $hasDuplicates ? ['duplicate_ids' => $duplicateIds] : null,
        ]);
    }
}
