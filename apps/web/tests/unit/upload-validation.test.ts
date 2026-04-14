import { describe, expect, it } from "vitest";
import { validateVideoFile } from "@/apps/web/features/upload/upload-validation";
import { MAX_VIDEO_SIZE_BYTES } from "@/apps/web/lib/constants/upload";

function makeFile(name: string, bytes: number[]): File {
  return new File([new Uint8Array(bytes)], name, { type: "video/mp4" });
}

describe("validateVideoFile", () => {
  it("許可拡張子・ヘッダなら通過する", async () => {
    const bytes = new Array(16).fill(0);
    bytes[4] = 0x66;
    bytes[5] = 0x74;
    bytes[6] = 0x79;
    bytes[7] = 0x70;
    const file = makeFile("sample.mp4", bytes);

    await expect(validateVideoFile(file)).resolves.toBeUndefined();
  });

  it("拡張子が不正ならブロックする", async () => {
    const file = makeFile("sample.exe", [0, 1, 2, 3, 4, 5, 6, 7]);
    await expect(validateVideoFile(file)).rejects.toThrow("対応拡張子");
  });

  it("サイズが10GB超ならブロックする", async () => {
    const bytes = new Array(16).fill(0);
    bytes[4] = 0x66;
    bytes[5] = 0x74;
    bytes[6] = 0x79;
    bytes[7] = 0x70;
    const file = makeFile("big.mp4", bytes);
    Object.defineProperty(file, "size", { value: MAX_VIDEO_SIZE_BYTES + 1 });

    await expect(validateVideoFile(file)).rejects.toThrow("10GB");
  });

  it("ヘッダ不一致ならブロックする", async () => {
    const file = makeFile("sample.mp4", [0, 1, 2, 3, 4, 5, 6, 7]);
    await expect(validateVideoFile(file)).rejects.toThrow("ヘッダ検証");
  });
});
