<?php

declare(strict_types=1);

use App\Domain\Job\JobStatus;
use App\Domain\Job\StateGuard;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Repository\JobRepository;
use App\Support\Uuid;

require dirname(__DIR__) . '/bootstrap.php';

final class TestFailed extends RuntimeException
{
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new TestFailed($message);
    }
}

function testUuidV4FormatAndUniqueness(): void
{
    $seen = [];
    for ($i = 0; $i < 2000; $i++) {
        $id = Uuid::v4();
        assertTrue((bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        ), 'Uuid::v4() format is invalid.');
        assertTrue(!isset($seen[$id]), 'Uuid::v4() generated duplicate value.');
        $seen[$id] = true;
    }
}

function testStateGuardTransitions(): void
{
    StateGuard::assertTransitionAllowed(JobStatus::PENDING, JobStatus::UPLOADING);
    StateGuard::assertTransitionAllowed(JobStatus::UPLOADING, JobStatus::ANALYZING);
    StateGuard::assertTransitionAllowed(JobStatus::ANALYZING, JobStatus::EDITING);
    StateGuard::assertTransitionAllowed(JobStatus::EDITING, JobStatus::COMPLETED);
    StateGuard::assertTransitionAllowed(JobStatus::PENDING, JobStatus::COMPLETED);

    $thrown = false;
    try {
        StateGuard::assertTransitionAllowed(JobStatus::COMPLETED, JobStatus::ANALYZING);
    } catch (InvalidArgumentException) {
        $thrown = true;
    }
    assertTrue($thrown, 'StateGuard must reject reverse transition from completed.');
}

function testRepositoryInsertPendingJob(): void
{
    $pdo = PdoFactory::createFromEnv();
    $migration = file_get_contents(dirname(__DIR__) . '/src/Infrastructure/Db/Migrations/001_create_video_jobs.sql');
    if ($migration === false) {
        throw new TestFailed('Failed to read migration SQL.');
    }
    $pdo->exec($migration);

    $repository = new JobRepository($pdo);
    $jobId = $repository->createPendingJob(
        hash('sha256', 'sample-video'),
        hash('sha256', '{"enable_subtitles":true,"extraction_count":2,"retention_days":7}'),
        'sample-video.mp4',
        ['enable_subtitles' => true, 'extraction_count' => 2, 'retention_days' => 7]
    );

    $stmt = $pdo->prepare('SELECT job_id, status, asset_integrity, completed_at FROM video_jobs WHERE job_id = :job_id');
    $stmt->execute([':job_id' => $jobId]);
    $row = $stmt->fetch();

    assertTrue((bool) $row, 'Repository did not insert a row.');
    assertTrue($row['status'] === JobStatus::PENDING, 'Inserted status must be pending.');
    assertTrue($row['asset_integrity'] === 'valid', 'Default asset_integrity must be valid.');
    assertTrue($row['completed_at'] === null, 'completed_at must be NULL for pending job.');
}

try {
    testUuidV4FormatAndUniqueness();
    testStateGuardTransitions();
    testRepositoryInsertPendingJob();
    echo "OK: Issue #1 quality tests passed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: {$e->getMessage()}" . PHP_EOL);
    exit(1);
}
