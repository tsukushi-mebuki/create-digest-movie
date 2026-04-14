import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { UploadFlow } from "@/apps/web/features/upload/upload-flow";

const pushMock = vi.fn();
const initJobMock = vi.fn();
const dispatchJobMock = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({
    push: pushMock,
  }),
}));

vi.mock("@/apps/web/lib/api-client/jobs", () => ({
  initJob: (...args: unknown[]) => initJobMock(...args),
  dispatchJob: (...args: unknown[]) => dispatchJobMock(...args),
}));

class WorkerMock {
  public onmessage: ((event: MessageEvent) => void) | null = null;
  public onerror: ((event: Event) => void) | null = null;

  terminate() {
    return undefined;
  }

  postMessage() {
    this.onmessage?.({
      data: { type: "done", hash: "abc123" },
    } as MessageEvent);
  }
}

function makeValidMp4File(): File {
  const bytes = new Array(16).fill(0);
  bytes[4] = 0x66;
  bytes[5] = 0x74;
  bytes[6] = 0x79;
  bytes[7] = 0x70;
  return new File([new Uint8Array(bytes)], "sample.mp4", { type: "video/mp4" });
}

describe("UploadFlow partial duplicate branch", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal("Worker", WorkerMock as unknown as typeof Worker);
  });

  it("upload_url:null pendingなら自動dispatchして待機画面へ遷移する", async () => {
    initJobMock.mockResolvedValue({
      job_id: "11111111-1111-1111-1111-111111111111",
      status: "pending",
      upload_url: null,
    });
    dispatchJobMock.mockResolvedValue({});

    render(<UploadFlow />);

    const fileInput = document.querySelector("input[type='file']") as HTMLInputElement | null;
    if (!fileInput) {
      throw new Error("file input not found");
    }

    const file = makeValidMp4File();
    fireEvent.change(fileInput, { target: { files: [file] } });
    await waitFor(() => expect(screen.getByRole("button", { name: "解析を開始" })).toBeEnabled());
    fireEvent.click(screen.getByRole("button", { name: "解析を開始" }));

    await waitFor(() => expect(initJobMock).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(dispatchJobMock).toHaveBeenCalledWith({ job_id: "11111111-1111-1111-1111-111111111111" }));
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/job/11111111-1111-1111-1111-111111111111"));
    expect(screen.getByText("既存の動画ファイルを使用して解析を開始します")).toBeInTheDocument();
  });
});
