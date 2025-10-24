<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'importable_type' => $this->importable_type,
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'file_signature' => $this->file_signature,
            'metadata' => $this->metadata,
            'summary' => [
                'success' => $this->success_count ?? 0,
                'failed' => $this->failed_count ?? 0,
                'duplicates' => $this->duplicates_count ?? 0,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
