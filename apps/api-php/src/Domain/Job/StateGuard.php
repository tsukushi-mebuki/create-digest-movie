<?php

declare(strict_types=1);

namespace App\Domain\Job;

use InvalidArgumentException;

final class StateGuard
{
    /**
     * Why: We explicitly enforce one-way transitions to protect job lifecycle
     * integrity across retries and asynchronous callbacks.
     */
    public static function assertTransitionAllowed(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $allowed = [
            JobStatus::PENDING => [JobStatus::UPLOADING, JobStatus::FAILED, JobStatus::COMPLETED],
            JobStatus::UPLOADING => [JobStatus::ANALYZING, JobStatus::FAILED],
            JobStatus::ANALYZING => [JobStatus::EDITING, JobStatus::FAILED],
            JobStatus::EDITING => [JobStatus::COMPLETED, JobStatus::FAILED],
            JobStatus::COMPLETED => [],
            JobStatus::FAILED => [],
        ];

        if (!isset($allowed[$from]) || !in_array($to, $allowed[$from], true)) {
            throw new InvalidArgumentException(sprintf('Invalid status transition: %s -> %s', $from, $to));
        }
    }
}
