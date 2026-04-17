import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { JobStatusScreen } from "@/apps/web/features/jobs/job-status-screen";
import type { JobDetailResponse } from "@/apps/web/lib/schemas/jobs";

type SwrState = {
  data?: JobDetailResponse;
  error?: Error;
  isLoading: boolean;
};

let swrState: SwrState = { isLoading: false };
let capturedRefreshInterval: ((data: JobDetailResponse | undefined) => number) | undefined;

vi.mock("swr", () => ({
  default: (
    _key: unknown,
    _fetcher: unknown,
    options: { refreshInterval?: (data: JobDetailResponse | undefined) => number }
  ) => {
    capturedRefreshInterval = options.refreshInterval;
    return swrState;
  },
}));

function buildJob(status: JobDetailResponse["status"], assets: JobDetailResponse["assets"] = null): JobDetailResponse {
  return {
    job_id: "11111111-1111-1111-1111-111111111111",
    file_hash: "hash",
    settings_hash: "settings-hash",
    original_file_name: "sample.mp4",
    status,
    asset_integrity: "valid",
    settings: {},
    assets,
    completed_at: status === "completed" ? "2026-04-14 12:34:56" : null,
    created_at: "2026-04-14 12:00:00",
    updated_at: "2026-04-14 12:10:00",
  };
}

describe("JobStatusScreen", () => {
  beforeEach(() => {
    swrState = { isLoading: false };
    capturedRefreshInterval = undefined;
    vi.restoreAllMocks();
  });

  it("analyzing中は待機UIを表示し、completedで同一ページ内で成果物UIへ切り替わる", () => {
    swrState = {
      isLoading: false,
      data: buildJob("analyzing"),
    };

    const { rerender } = render(<JobStatusScreen jobId="11111111-1111-1111-1111-111111111111" />);
    expect(screen.getByText("解析ステータス")).toBeInTheDocument();
    expect(screen.getAllByText("音声認識と要約を実行しています").length).toBeGreaterThan(0);
    expect(screen.queryByText("成果物")).not.toBeInTheDocument();

    swrState = {
      isLoading: false,
      data: buildJob("completed", {
        completed_shorts: ["short-asset-id"],
        transcript_text: "transcript body",
      }),
    };
    rerender(<JobStatusScreen jobId="11111111-1111-1111-1111-111111111111" />);

    expect(screen.getByText("成果物")).toBeInTheDocument();
    expect(screen.getByText("transcript body")).toBeInTheDocument();
    expect(screen.queryByText("解析ステータス")).not.toBeInTheDocument();
  });

  it("failed時はエラーUIを表示する", () => {
    swrState = {
      isLoading: false,
      data: buildJob("failed"),
    };

    render(<JobStatusScreen jobId="11111111-1111-1111-1111-111111111111" />);
    expect(screen.getByText("処理に失敗しました")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "再アップロードする" })).toHaveAttribute("href", "/");
  });

  it("completed_shortsがオブジェクト配列でも成果物リンクを表示できる", () => {
    swrState = {
      isLoading: false,
      data: buildJob("completed", {
        completed_shorts: [
          {
            drive_file_id: "gs://create-digest-movie/completed-shorts/job-a/short_01.mp4",
            signed_url: "https://signed.example/short_01.mp4",
            start_sec: 0,
            end_sec: 30,
            duration_sec: 30,
          },
        ],
      }),
    };

    render(<JobStatusScreen jobId="11111111-1111-1111-1111-111111111111" />);
    expect(screen.getByRole("link", { name: /ダウンロード: gs:\/\/create-digest-movie\/completed-shorts\/job-a\/short_01\.mp4/ })).toHaveAttribute(
      "href",
      "https://signed.example/short_01.mp4"
    );
  });

  it("ポーリング間隔は60秒以内5秒・以降10秒で、終端状態で停止する", () => {
    const dateNowSpy = vi.spyOn(Date, "now");
    dateNowSpy.mockReturnValueOnce(1_000);
    swrState = {
      isLoading: false,
      data: buildJob("pending"),
    };

    render(<JobStatusScreen jobId="11111111-1111-1111-1111-111111111111" />);
    expect(capturedRefreshInterval).toBeTypeOf("function");

    dateNowSpy.mockReturnValue(50_000);
    expect(capturedRefreshInterval?.(buildJob("analyzing"))).toBe(5_000);

    dateNowSpy.mockReturnValue(70_000);
    expect(capturedRefreshInterval?.(buildJob("editing"))).toBe(10_000);

    expect(capturedRefreshInterval?.(buildJob("completed"))).toBe(0);
    expect(capturedRefreshInterval?.(buildJob("failed"))).toBe(0);
  });
});
