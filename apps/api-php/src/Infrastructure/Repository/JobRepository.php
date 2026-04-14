<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Job\JobStatus;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Uuid;
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
}
