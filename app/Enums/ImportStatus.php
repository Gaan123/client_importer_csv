<?php

namespace App\Enums;

enum ImportStatus: string
{
    case PENDING = 'pending';
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case COMPLETED_WITH_ERRORS = 'completed_with_errors';
    case FAILED = 'failed';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
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
            self::QUEUED,
            self::PROCESSING,
        ]);
    }
}
