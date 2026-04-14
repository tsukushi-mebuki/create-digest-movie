import type { JobDetailResponse, JobDispatchRequest, JobInitRequest, JobInitResponse } from "@/apps/web/lib/schemas/jobs";

const API_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL ?? "";

async function requestJson<TResponse>(path: string, body: unknown): Promise<TResponse> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });

  if (!response.ok) {
    throw new Error(`${path} failed with status ${response.status}`);
  }

  return (await response.json()) as TResponse;
}

async function requestGetJson<TResponse>(path: string): Promise<TResponse> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    method: "GET",
    headers: { "Content-Type": "application/json" },
    cache: "no-store",
  });

  if (!response.ok) {
    throw new Error(`${path} failed with status ${response.status}`);
  }

  return (await response.json()) as TResponse;
}

export async function initJob(payload: JobInitRequest): Promise<JobInitResponse> {
  return requestJson<JobInitResponse>("/api/jobs/init", payload);
}

export async function dispatchJob(payload: JobDispatchRequest): Promise<void> {
  await requestJson<Record<string, unknown>>("/api/jobs/dispatch", payload);
}

export async function fetchJobDetail(jobId: string): Promise<JobDetailResponse> {
  return requestGetJson<JobDetailResponse>(`/api/jobs/${jobId}`);
}
