"use client";

import { useParams } from "next/navigation";
import { JobStatusScreen } from "@/apps/web/features/jobs/job-status-screen";

export default function JobPage() {
  const params = useParams<{ jobId: string }>();
  const jobId = params?.jobId ?? "";

  if (!jobId) {
    return <main className="mx-auto w-full max-w-3xl p-6">ジョブIDが不正です。</main>;
  }

  return <JobStatusScreen jobId={jobId} />;
}
