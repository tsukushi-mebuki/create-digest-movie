/// <reference lib="webworker" />

import { createSHA256 } from "hash-wasm";
import { HASH_CHUNK_SIZE_BYTES, HASH_PROGRESS_THROTTLE_MS } from "@/apps/web/lib/constants/upload";

type HashWorkerRequest = {
  file: File;
};

type HashWorkerProgress = {
  type: "progress";
  processedBytes: number;
  totalBytes: number;
};

type HashWorkerDone = {
  type: "done";
  hash: string;
};

type HashWorkerError = {
  type: "error";
  message: string;
};

let lastProgressAt = 0;

function postProgress(processedBytes: number, totalBytes: number): void {
  const now = Date.now();
  if (now - lastProgressAt < HASH_PROGRESS_THROTTLE_MS && processedBytes < totalBytes) {
    return;
  }

  lastProgressAt = now;
  const payload: HashWorkerProgress = { type: "progress", processedBytes, totalBytes };
  self.postMessage(payload);
}

self.onmessage = async (event: MessageEvent<HashWorkerRequest>) => {
  const { file } = event.data;
  lastProgressAt = 0;

  try {
    const hasher = await createSHA256();
    const totalBytes = file.size;
    let offset = 0;

    while (offset < totalBytes) {
      const nextOffset = Math.min(offset + HASH_CHUNK_SIZE_BYTES, totalBytes);
      const chunk = file.slice(offset, nextOffset);
      const buffer = await chunk.arrayBuffer();
      hasher.update(new Uint8Array(buffer));
      offset = nextOffset;
      postProgress(offset, totalBytes);
    }

    const payload: HashWorkerDone = { type: "done", hash: hasher.digest("hex") };
    self.postMessage(payload);
  } catch (error) {
    const payload: HashWorkerError = {
      type: "error",
      message: error instanceof Error ? error.message : "Failed to calculate hash.",
    };
    self.postMessage(payload);
  }
};

export {};
