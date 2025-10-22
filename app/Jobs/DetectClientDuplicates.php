<?php

namespace App\Jobs;

use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DetectClientDuplicates implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 600;
    public int $tries = 3;

    public function __construct(
        public int $importId
    ) {}

    public function handle(): void
    {
        Log::info("Detecting duplicates for import {$this->importId}");

        try {
            // Use window functions to detect duplicates by company, email, or phone in single query
            // ARRAY_AGG collects all matching IDs per partition, array_remove excludes current row
            DB::statement("
                WITH duplicate_detection AS (
                    SELECT
                        id,
                        COALESCE(
                            array_remove(
                                ARRAY_AGG(id) FILTER (WHERE company != '') OVER (PARTITION BY company),
                                id
                            ),
                            ARRAY[]::integer[]
                        ) as company_dups,
                        COALESCE(
                            array_remove(
                                ARRAY_AGG(id) FILTER (WHERE email != '') OVER (PARTITION BY email),
                                id
                            ),
                            ARRAY[]::integer[]
                        ) as email_dups,
                        COALESCE(
                            array_remove(
                                ARRAY_AGG(id) FILTER (WHERE phone != '') OVER (PARTITION BY phone),
                                id
                            ),
                            ARRAY[]::integer[]
                        ) as phone_dups
                    FROM clients
                )
                UPDATE clients c
                SET
                    has_duplicates = CASE
                        WHEN array_length(d.company_dups, 1) > 0
                          OR array_length(d.email_dups, 1) > 0
                          OR array_length(d.phone_dups, 1) > 0
                        THEN true
                        ELSE false
                    END,
                    extras = CASE
                        WHEN array_length(d.company_dups, 1) > 0
                          OR array_length(d.email_dups, 1) > 0
                          OR array_length(d.phone_dups, 1) > 0
                        THEN jsonb_build_object(
                            'duplicate_ids', jsonb_build_object(
                                'company', d.company_dups,
                                'email', d.email_dups,
                                'phone', d.phone_dups
                            )
                        )
                        ELSE NULL
                    END,
                    updated_at = NOW()
                FROM duplicate_detection d
                WHERE c.id = d.id
            ");

            // Get statistics on detected duplicates
            $stats = DB::selectOne("
                SELECT
                    COUNT(*) FILTER (WHERE has_duplicates = true) as flagged_duplicates,
                    COUNT(*) FILTER (WHERE (extras->'duplicate_ids'->>'company')::jsonb != '[]'::jsonb) as by_company,
                    COUNT(*) FILTER (WHERE (extras->'duplicate_ids'->>'email')::jsonb != '[]'::jsonb) as by_email,
                    COUNT(*) FILTER (WHERE (extras->'duplicate_ids'->>'phone')::jsonb != '[]'::jsonb) as by_phone
                FROM clients
                WHERE has_duplicates = true
            ");

            Log::info("Duplicate detection completed for import {$this->importId}: " .
                     "Flagged={$stats->flagged_duplicates}, Company={$stats->by_company}, " .
                     "Email={$stats->by_email}, Phone={$stats->by_phone}");

        } catch (\Exception $e) {
            Log::error("Duplicate detection failed for import {$this->importId}: " . $e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Duplicate detection permanently failed for import {$this->importId}: " . $exception->getMessage());
    }
}
