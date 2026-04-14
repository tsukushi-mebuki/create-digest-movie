CREATE TABLE IF NOT EXISTS video_jobs (
  job_id CHAR(36) NOT NULL PRIMARY KEY,
  source_job_id CHAR(36) NULL,
  file_hash CHAR(64) NOT NULL,
  settings_hash CHAR(64) NOT NULL,
  original_file_name VARCHAR(255) NOT NULL,
  status ENUM('pending', 'uploading', 'analyzing', 'editing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  asset_integrity ENUM('valid', 'invalid') NOT NULL DEFAULT 'valid',
  settings JSON NOT NULL,
  assets JSON NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_video_jobs_source_job
    FOREIGN KEY (source_job_id) REFERENCES video_jobs (job_id)
    ON DELETE SET NULL,
  INDEX idx_video_jobs_status (status),
  INDEX idx_video_jobs_hashes (file_hash, settings_hash),
  INDEX idx_video_jobs_created_at (created_at),
  INDEX idx_video_jobs_updated_at (updated_at),
  INDEX idx_video_jobs_completed_at (completed_at)
);
