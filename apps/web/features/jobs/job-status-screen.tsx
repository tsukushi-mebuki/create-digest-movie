"use client";

import { useEffect, useRef } from "react";
import useSWR from "swr";
import Link from "next/link";
import { fetchJobDetail } from "@/apps/web/lib/api-client/jobs";
import type { CompletedShortAsset, JobAssets, JobDetailResponse, JobStatus } from "@/apps/web/lib/schemas/jobs";

const TERMINAL_STATUSES: JobStatus[] = ["completed", "failed"];
const FAST_POLLING_MS = 5_000;
const SLOW_POLLING_MS = 10_000;
const FAST_POLLING_WINDOW_MS = 60_000;

const STATUS_STEPS: JobStatus[] = ["pending", "uploading", "analyzing", "editing"];

const STATUS_LABELS: Record<JobStatus, string> = {
  pending: "処理キューに登録しています",
  uploading: "動画アップロードを確認しています",
  analyzing: "音声認識と要約を実行しています",
  editing: "ショート動画を生成しています",
  completed: "処理が完了しました",
  failed: "処理に失敗しました",
};

function isTerminalStatus(status: JobStatus): boolean {
  return TERMINAL_STATUSES.includes(status);
}

function buildShortDownloadHref(shortAsset: string): string {
  if (shortAsset.startsWith("http://") || shortAsset.startsWith("https://")) {
    return shortAsset;
  }

  if (shortAsset.startsWith("gs://")) {
    const withoutScheme = shortAsset.slice("gs://".length);
    const slashIndex = withoutScheme.indexOf("/");
    if (slashIndex > 0) {
      const bucket = withoutScheme.slice(0, slashIndex);
      const objectPath = withoutScheme.slice(slashIndex + 1);
      const encodedPath = objectPath
        .split("/")
        .map((segment) => encodeURIComponent(segment))
        .join("/");
      return `https://storage.googleapis.com/${encodeURIComponent(bucket)}/${encodedPath}`;
    }
  }

  return `https://drive.google.com/uc?export=download&id=${encodeURIComponent(shortAsset)}`;
}

function extractShortAssetId(shortAsset: CompletedShortAsset): string | null {
  if (typeof shortAsset === "string") {
    return shortAsset;
  }
  if (shortAsset && typeof shortAsset === "object" && typeof shortAsset.drive_file_id === "string") {
    return shortAsset.drive_file_id;
  }
  return null;
}

function getPollingInterval(data: JobDetailResponse | undefined, startedAtMs: number | null): number {
  if (data && isTerminalStatus(data.status)) {
    return 0;
  }

  if (startedAtMs === null) {
    return FAST_POLLING_MS;
  }

  const elapsedMs = Date.now() - startedAtMs;
  return elapsedMs < FAST_POLLING_WINDOW_MS ? FAST_POLLING_MS : SLOW_POLLING_MS;
}

function ProgressCard({ status }: { status: JobStatus }) {
  const activeStepIndex = STATUS_STEPS.indexOf(status);

  return (
    <section className="rounded-lg border border-slate-200 p-5">
      <h2 className="text-lg font-semibold">解析ステータス</h2>
      <p className="mt-2 text-slate-700">{STATUS_LABELS[status]}</p>
      <ol className="mt-4 flex flex-col gap-2">
        {STATUS_STEPS.map((step, index) => {
          const done = activeStepIndex > index;
          const active = activeStepIndex === index;
          return (
            <li key={step} className="flex items-center gap-2">
              <span
                className={[
                  "inline-flex h-6 w-6 items-center justify-center rounded-full text-sm",
                  done || active ? "bg-black text-white" : "bg-slate-200 text-slate-600",
                ].join(" ")}
              >
                {index + 1}
              </span>
              <span className={active ? "font-medium text-black" : "text-slate-600"}>{STATUS_LABELS[step]}</span>
            </li>
          );
        })}
      </ol>
    </section>
  );
}

function ResultCard({ assets }: { assets: JobAssets | null }) {
  const shorts = assets?.completed_shorts ?? [];
  const transcript = assets?.transcript_text;
  const transcriptAssetId = assets?.text_asset_id;

  return (
    <section className="rounded-lg border border-emerald-200 bg-emerald-50 p-5">
      <h2 className="text-lg font-semibold text-emerald-900">成果物</h2>
      <p className="mt-2 text-emerald-900">処理が完了しました。生成された成果物をこのページ内で確認できます。</p>

      <div className="mt-4 space-y-4">
        <div>
          <h3 className="font-medium text-emerald-900">ショート動画</h3>
          {shorts.length === 0 ? (
            <p className="text-sm text-emerald-800">まだショート動画情報がありません。</p>
          ) : (
            <ul className="mt-2 list-disc space-y-1 pl-5">
              {shorts.map((shortAsset, index) => {
                const shortAssetId = extractShortAssetId(shortAsset);
                if (!shortAssetId) {
                  return null;
                }

                return (
                  <li key={`${shortAssetId}-${index}`}>
                    <a className="underline" href={buildShortDownloadHref(shortAssetId)} target="_blank" rel="noreferrer">
                      ダウンロード: {shortAssetId}
                    </a>
                  </li>
                );
              })}
            </ul>
          )}
        </div>

        <div>
          <h3 className="font-medium text-emerald-900">文字起こし</h3>
          {transcript ? (
            <pre className="mt-2 max-h-64 overflow-auto whitespace-pre-wrap rounded border border-emerald-300 bg-white p-3 text-sm">
              {transcript}
            </pre>
          ) : transcriptAssetId ? (
            <p className="text-sm text-emerald-800">文字起こしアセットID: {transcriptAssetId}</p>
          ) : (
            <p className="text-sm text-emerald-800">文字起こしデータはまだ利用できません。</p>
          )}
        </div>
      </div>
    </section>
  );
}

function FailedCard() {
  return (
    <section className="rounded-lg border border-red-200 bg-red-50 p-5">
      <h2 className="text-lg font-semibold text-red-900">処理に失敗しました</h2>
      <p className="mt-2 text-red-800">時間をおいて再アップロードいただくか、サポートへお問い合わせください。</p>
      <Link href="/" className="mt-4 inline-block rounded bg-black px-4 py-2 text-white">
        再アップロードする
      </Link>
    </section>
  );
}

export function JobStatusScreen({ jobId }: { jobId: string }) {
  const startedAtRef = useRef<number | null>(null);

  useEffect(() => {
    startedAtRef.current = Date.now();
  }, []);

  const { data, error, isLoading } = useSWR(
    jobId ? ["job-status", jobId] : null,
    () => fetchJobDetail(jobId),
    {
      refreshInterval: (nextData) => getPollingInterval(nextData, startedAtRef.current),
      revalidateOnFocus: false,
      shouldRetryOnError: false,
    }
  );

  if (isLoading) {
    return <main className="mx-auto w-full max-w-3xl p-6">ジョブ情報を取得中です...</main>;
  }

  if (error) {
    return (
      <main className="mx-auto w-full max-w-3xl p-6">
        <FailedCard />
      </main>
    );
  }

  if (!data) {
    return <main className="mx-auto w-full max-w-3xl p-6">ジョブ情報が見つかりませんでした。</main>;
  }

  return (
    <main className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-6">
      <h1 className="text-2xl font-semibold">ジョブ進行状況</h1>
      <p className="text-sm text-slate-600">Job ID: {data.job_id}</p>
      {data.status === "completed" ? <ResultCard assets={data.assets} /> : null}
      {data.status === "failed" ? <FailedCard /> : null}
      {!isTerminalStatus(data.status) ? <ProgressCard status={data.status} /> : null}
    </main>
  );
}
