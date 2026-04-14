"use client";

import { useMemo, useRef, useState } from "react";
import Uppy from "@uppy/core";
import Tus from "@uppy/tus";
import { useRouter } from "next/navigation";
import { dispatchJob, initJob } from "@/apps/web/lib/api-client/jobs";
import {
  ACCEPTED_VIDEO_EXTENSIONS,
  HASH_CHUNK_SIZE_BYTES,
  MAX_VIDEO_SIZE_BYTES,
} from "@/apps/web/lib/constants/upload";
import { validateVideoFile } from "@/apps/web/features/upload/upload-validation";

type HashWorkerProgressMessage = {
  type: "progress";
  processedBytes: number;
  totalBytes: number;
};

type HashWorkerDoneMessage = {
  type: "done";
  hash: string;
};

type HashWorkerErrorMessage = {
  type: "error";
  message: string;
};

type HashWorkerMessage = HashWorkerProgressMessage | HashWorkerDoneMessage | HashWorkerErrorMessage;

type Toast = {
  kind: "success" | "error";
  message: string;
};

async function calculateSha256InWorker(file: File, onProgress: (progress: number) => void): Promise<string> {
  return new Promise<string>((resolve, reject) => {
    const worker = new Worker(new URL("./hash.worker.ts", import.meta.url));

    worker.onmessage = (event: MessageEvent<HashWorkerMessage>) => {
      const message = event.data;
      if (message.type === "progress") {
        const ratio = message.totalBytes === 0 ? 1 : message.processedBytes / message.totalBytes;
        onProgress(Math.max(0, Math.min(100, Math.round(ratio * 100))));
        return;
      }

      if (message.type === "done") {
        worker.terminate();
        resolve(message.hash);
        return;
      }

      worker.terminate();
      reject(new Error(message.message));
    };

    worker.onerror = () => {
      worker.terminate();
      reject(new Error("ハッシュ計算Workerでエラーが発生しました。"));
    };

    worker.postMessage({ file });
  });
}

export function UploadFlow() {
  const router = useRouter();
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [hashProgress, setHashProgress] = useState(0);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [toast, setToast] = useState<Toast | null>(null);
  const uppyRef = useRef<Uppy | null>(null);

  const acceptedExtensionsText = useMemo(() => ACCEPTED_VIDEO_EXTENSIONS.join(","), []);

  const onSelectFile = async (file: File | null): Promise<void> => {
    setToast(null);
    setHashProgress(0);
    setUploadProgress(0);

    if (!file) {
      setSelectedFile(null);
      return;
    }

    try {
      await validateVideoFile(file);
      setSelectedFile(file);
    } catch (error) {
      setSelectedFile(null);
      setToast({
        kind: "error",
        message: error instanceof Error ? error.message : "ファイル検証に失敗しました。",
      });
    }
  };

  const handleStart = async (): Promise<void> => {
    if (!selectedFile || isSubmitting) {
      return;
    }

    setIsSubmitting(true);
    setToast(null);

    try {
      const fileHash = await calculateSha256InWorker(selectedFile, setHashProgress);

      const init = await initJob({
        file_hash: fileHash,
        original_file_name: selectedFile.name,
        settings: {
          summary_style: "short",
          short_count: 3,
        },
      });

      if (init.upload_url === null && init.status === "pending") {
        // Why: Partial duplicate must bypass upload and immediately continue analysis.
        uppyRef.current?.cancelAll();
        await dispatchJob({ job_id: init.job_id });
        setToast({
          kind: "success",
          message: "既存の動画ファイルを使用して解析を開始します",
        });
        router.push(`/job/${init.job_id}`);
        return;
      }

      if (init.upload_url === null) {
        router.push(`/job/${init.job_id}`);
        return;
      }

      const uppy = new Uppy({
        autoProceed: true,
        restrictions: {
          maxNumberOfFiles: 1,
          maxFileSize: MAX_VIDEO_SIZE_BYTES,
          allowedFileTypes: [...ACCEPTED_VIDEO_EXTENSIONS],
        },
      });
      uppy.use(Tus, {
        endpoint: init.upload_url,
        chunkSize: HASH_CHUNK_SIZE_BYTES,
        retryDelays: [0, 1000, 3000, 5000],
      });
      uppyRef.current = uppy;

      await new Promise<void>((resolve, reject) => {
        uppy.on("upload-progress", (_file, progress) => {
          if (!progress || !progress.bytesTotal) {
            return;
          }

          setUploadProgress(Math.round((progress.bytesUploaded / progress.bytesTotal) * 100));
        });
        uppy.on("complete", () => {
          uppy.destroy();
          resolve();
        });
        uppy.on("error", (error) => {
          uppy.destroy();
          reject(error);
        });

        uppy.addFile({
          name: selectedFile.name,
          type: selectedFile.type,
          data: selectedFile,
        });
        void uppy.upload();
      });

      await dispatchJob({ job_id: init.job_id });
      router.push(`/job/${init.job_id}`);
    } catch (error) {
      setToast({
        kind: "error",
        message: error instanceof Error ? error.message : "アップロード処理に失敗しました。",
      });
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <main className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-6">
      <h1 className="text-xl font-semibold">動画アップロード</h1>
      <input
        type="file"
        accept={acceptedExtensionsText}
        onChange={(event) => void onSelectFile(event.target.files?.[0] ?? null)}
      />
      <button
        type="button"
        onClick={() => void handleStart()}
        disabled={!selectedFile || isSubmitting}
        className="rounded bg-black px-4 py-2 text-white disabled:opacity-50"
      >
        {isSubmitting ? "処理中..." : "解析を開始"}
      </button>
      <p>ハッシュ計算: {hashProgress}%</p>
      <p>アップロード: {uploadProgress}%</p>
      {toast ? (
        <p className={toast.kind === "error" ? "text-red-600" : "text-green-600"}>{toast.message}</p>
      ) : null}
    </main>
  );
}
