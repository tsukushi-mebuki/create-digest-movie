<?php

declare(strict_types=1);

use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Repository\JobRepository;

require dirname(__DIR__) . '/bootstrap.php';

$pdo = PdoFactory::createFromEnv();
$migrationSql = file_get_contents(dirname(__DIR__) . '/src/Infrastructure/Db/Migrations/001_create_video_jobs.sql');
if ($migrationSql === false) {
    throw new RuntimeException('Failed to read migration SQL.');
}

$pdo->exec($migrationSql);

$repo = new JobRepository($pdo);
$jobId = $repo->createPendingJob(
    str_repeat('a', 64),
    str_repeat('b', 64),
    'sample-video.mp4',
    [
        'extraction_count' => 2,
        'enable_subtitles' => true,
        'retention_days' => 7,
    ]
);

$stmt = $pdo->prepare('SELECT job_id, status FROM video_jobs WHERE job_id = :job_id');
$stmt->execute([':job_id' => $jobId]);
$row = $stmt->fetch();

if (!$row || $row['status'] !== 'pending') {
    throw new RuntimeException('Failed to insert pending job.');
}

echo "OK: inserted pending job {$row['job_id']}" . PHP_EOL;
