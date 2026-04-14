export type JobInitRequest = {
  file_hash: string;
  original_file_name: string;
  settings: Record<string, unknown>;
};

export type JobInitResponse = {
  job_id: string;
  status: "pending" | "completed";
  upload_url: string | null;
};

export type JobDispatchRequest = {
  job_id: string;
};
