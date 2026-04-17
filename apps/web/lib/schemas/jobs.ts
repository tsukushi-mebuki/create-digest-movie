export type JobInitRequest = {
  file_hash: string;
  original_file_name: string;
  settings: Record<string, unknown>;
};

export type JobInitResponse = {
  job_id: string;
  status: JobStatus;
  upload_url: string | null;
};

export type JobDispatchRequest = {
  job_id: string;
};

export type JobStatus = "pending" | "uploading" | "analyzing" | "editing" | "completed" | "failed";

export type CompletedShortAsset =
  | string
  | {
      drive_file_id: string;
      start_sec?: number;
      end_sec?: number;
      duration_sec?: number;
    };

export type JobAssets = {
  original_video_id?: string;
  text_asset_id?: string;
  completed_shorts?: CompletedShortAsset[];
  transcript_text?: string;
};

export type JobDetailResponse = {
  job_id: string;
  file_hash: string;
  settings_hash: string;
  original_file_name: string;
  status: JobStatus;
  asset_integrity: string;
  settings: Record<string, unknown>;
  assets: JobAssets | null;
  completed_at: string | null;
  created_at: string;
  updated_at: string;
};
