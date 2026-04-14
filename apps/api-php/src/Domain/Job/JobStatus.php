<?php

declare(strict_types=1);

namespace App\Domain\Job;

final class JobStatus
{
    public const PENDING = 'pending';
    public const UPLOADING = 'uploading';
    public const ANALYZING = 'analyzing';
    public const EDITING = 'editing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
}
