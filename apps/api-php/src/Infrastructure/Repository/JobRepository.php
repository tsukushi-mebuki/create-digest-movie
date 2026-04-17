<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Job\JobStatus;
use App\Domain\Job\StateGuard;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Uuid;
use InvalidArgumentException;
use PDO;

final class JobRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromEnv(): self
    {
        return new self(PdoFactory::createFromEnv());
    }

    /**
     * Why: Job IDs must be server-generated to prevent collisions and injection.
     */
    public function createPendingJob(
        string $fileHash,
        string $settingsHash,
        string $originalFileName,
        array $settings
    ): string {
        $jobId = Uuid::v4();
        $sql = <<<'SQL'
            INSERT INTO video_jobs (
                job_id,
                source_job_id,
                file_hash,
                settings_hash,
                original_file_name,
                status,
                asset_integrity,
                settings,
                assets,
                completed_at
            ) VALUES (
                :job_id,
                NULL,
                :file_hash,
                :settings_hash,
                :original_file_name,
                :status,
                'valid',
                :settings,
                NULL,
                NULL
            )
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':job_id' => $jobId,
            ':file_hash' => $fileHash,
            ':settings_hash' => $settingsHash,
            ':original_file_name' => $originalFileName,
            ':status' => JobStatus::PENDING,
            ':settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $jobId;
    }

    /**
     * Why: Duplicate branches need explicit source linkage instead of copying assets.
     */
    public function createDerivedJob(
        string $fileHash,
        string $settingsHash,
        string $originalFileName,
        array $settings,
        string $sourceJobId,
        string $status,
        ?string $completedAt
    ): string {
        $jobId = Uuid::v4();
        $sql = <<<'SQL'
            INSERT INTO video_jobs (
                job_id,
                source_job_id,
                file_hash,
                settings_hash,
                original_file_name,
                status,
                asset_integrity,
                settings,
                assets,
                completed_at
            ) VALUES (
                :job_id,
                :source_job_id,
                :file_hash,
                :settings_hash,
                :original_file_name,
                :status,
                'valid',
                :settings,
                NULL,
                :completed_at
            )
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':job_id' => $jobId,
            ':source_job_id' => $sourceJobId,
            ':file_hash' => $fileHash,
            ':settings_hash' => $settingsHash,
            ':original_file_name' => $originalFileName,
            ':status' => $status,
            ':settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':completed_at' => $completedAt,
        ]);

        return $jobId;
    }

    /**
     * Why: We must split exact/partial duplicate branches server-side.
     */
    public function findExactCompletedDuplicate(string $fileHash, string $settingsHash): ?array
    {
        $sql = <<<'SQL'
            SELECT job_id
            FROM video_jobs
            WHERE file_hash = :file_hash
              AND settings_hash = :settings_hash
              AND status = :status_completed
              AND asset_integrity = 'valid'
            ORDER BY completed_at DESC, created_at DESC
            LIMIT 1
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':file_hash' => $fileHash,
            ':settings_hash' => $settingsHash,
            ':status_completed' => JobStatus::COMPLETED,
        ]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Why: Reuse existing uploaded video when only settings differ.
     */
    public function findPartialDuplicateSource(string $fileHash, string $settingsHash): ?array
    {
        $sql = <<<'SQL'
            SELECT job_id
            FROM video_jobs
            WHERE file_hash = :file_hash
              AND settings_hash <> :settings_hash
            ORDER BY completed_at DESC, created_at DESC
            LIMIT 1
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':file_hash' => $fileHash,
            ':settings_hash' => $settingsHash,
        ]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Why: GET must transparently resolve parent assets for source-linked jobs.
     */
    public function findJobWithResolvedAssets(string $jobId): ?array
    {
        $sql = <<<'SQL'
            SELECT
                child.job_id,
                child.file_hash,
                child.settings_hash,
                child.original_file_name,
                child.status,
                child.asset_integrity,
                child.settings,
                COALESCE(child.assets, parent.assets) AS resolved_assets,
                child.completed_at,
                child.created_at,
                child.updated_at
            FROM video_jobs child
            LEFT JOIN video_jobs parent ON child.source_job_id = parent.job_id
            WHERE child.job_id = :job_id
            LIMIT 1
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':job_id' => $jobId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'job_id' => $row['job_id'],
            'file_hash' => $row['file_hash'],
            'settings_hash' => $row['settings_hash'],
            'original_file_name' => $row['original_file_name'],
            'status' => $row['status'],
            'asset_integrity' => $row['asset_integrity'],
            'settings' => $row['settings'] !== null ? json_decode((string) $row['settings'], true) : null,
            'assets' => $row['resolved_assets'] !== null ? json_decode((string) $row['resolved_assets'], true) : null,
            'completed_at' => $row['completed_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * Why: Dispatch must be exactly-once per job_id and guarded by DB status.
     *
     * @param callable(string):void $dispatch
     */
    public function dispatchIfAllowed(string $jobId, callable $dispatch): string
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT status FROM video_jobs WHERE job_id = :job_id FOR UPDATE');
            $stmt->execute([':job_id' => $jobId]);
            $row = $stmt->fetch();

            if ($row === false) {
                $this->pdo->rollBack();
                return 'not_found';
            }

            $currentStatus = (string) $row['status'];
            if (!in_array($currentStatus, [JobStatus::PENDING, JobStatus::UPLOADING], true)) {
                $this->pdo->rollBack();
                return 'skipped';
            }

            $dispatch($jobId);

            $update = $this->pdo->prepare('UPDATE video_jobs SET status = :status WHERE job_id = :job_id');
            $update->execute([
                ':status' => JobStatus::ANALYZING,
                ':job_id' => $jobId,
            ]);

            $this->pdo->commit();
            return 'dispatched';
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Why: Webhook retries/out-of-order deliveries must never overwrite terminal states.
     *
     * @param array<string,mixed>|null $incomingAssets
     */
    public function applyWebhookUpdate(string $jobId, string $incomingStatus, ?array $incomingAssets, ?string $completedAt): string
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT status, assets FROM video_jobs WHERE job_id = :job_id FOR UPDATE');
            $stmt->execute([':job_id' => $jobId]);
            $row = $stmt->fetch();

            if ($row === false) {
                $this->pdo->rollBack();
                return 'not_found';
            }

            $currentStatus = (string) $row['status'];
            if (
                in_array($currentStatus, [JobStatus::COMPLETED, JobStatus::FAILED], true)
                && in_array($incomingStatus, [JobStatus::ANALYZING, JobStatus::EDITING], true)
            ) {
                $this->pdo->rollBack();
                return 'skipped';
            }

            try {
                StateGuard::assertTransitionAllowed($currentStatus, $incomingStatus);
            } catch (InvalidArgumentException) {
                $this->pdo->rollBack();
                return 'skipped';
            }

            $currentAssets = null;
            if ($row['assets'] !== null) {
                $decoded = json_decode((string) $row['assets'], true);
                $currentAssets = is_array($decoded) ? $decoded : null;
            }

            $mergedAssets = $this->mergeAssets($currentAssets, $incomingAssets);

            $resolvedCompletedAt = $completedAt;
            if ($incomingStatus === JobStatus::COMPLETED && $resolvedCompletedAt === null) {
                $resolvedCompletedAt = gmdate('Y-m-d H:i:s');
            }

            $update = $this->pdo->prepare(<<<'SQL'
                UPDATE video_jobs
                SET status = :next_status,
                    assets = :assets,
                    completed_at = CASE WHEN :status_for_completed = :status_completed THEN :completed_at ELSE completed_at END
                WHERE job_id = :job_id
            SQL);
            $update->execute([
                ':next_status' => $incomingStatus,
                ':assets' => $mergedAssets === null ? null : json_encode($mergedAssets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':status_for_completed' => $incomingStatus,
                ':status_completed' => JobStatus::COMPLETED,
                ':completed_at' => $resolvedCompletedAt,
                ':job_id' => $jobId,
            ]);

            $this->pdo->commit();
            return 'updated';
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed>|null $current
     * @param array<string,mixed>|null $incoming
     * @return array<string,mixed>|null
     */
    private function mergeAssets(?array $current, ?array $incoming): ?array
    {
        if ($incoming === null) {
            return $current;
        }
        if ($current === null) {
            return $incoming;
        }

        return array_replace_recursive($current, $incoming);
    }
}
