<?php

namespace App\Enums;

enum ImportStatus: string
{
    case PENDING = 'pending';
    case PENDING_LARGE_CSV = 'pending_large_csv';
    case PROCESSING_LARGE_CSV = 'processing_large_csv';
    case CHUNKS_READY = 'chunks_ready';
    case PROCESSING_CHUNKS = 'processing_chunks';
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case COMPLETED_WITH_ERRORS = 'completed_with_errors';
    case FAILED = 'failed';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::PENDING_LARGE_CSV => 'Pending Large CSV',
            self::PROCESSING_LARGE_CSV => 'Processing Large CSV',
            self::CHUNKS_READY => 'Chunks Ready',
            self::PROCESSING_CHUNKS => 'Processing Chunks',
            self::QUEUED => 'Queued',
            self::PROCESSING => 'Processing',
            self::COMPLETED => 'Completed',
            self::COMPLETED_WITH_ERRORS => 'Completed with Errors',
            self::FAILED => 'Failed',
        };
    }

    public function isComplete(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::COMPLETED_WITH_ERRORS,
            self::FAILED
        ]);
    }

    public function isSuccessful(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::COMPLETED_WITH_ERRORS,
        ]);
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    public function isProcessing(): bool
    {
        return in_array($this, [
            self::PENDING_LARGE_CSV,
            self::PROCESSING_LARGE_CSV,
            self::CHUNKS_READY,
            self::PROCESSING_CHUNKS,
            self::QUEUED,
            self::PROCESSING,
        ]);
    }
}
