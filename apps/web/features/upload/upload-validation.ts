import {
  ACCEPTED_VIDEO_EXTENSIONS,
  ACCEPTED_VIDEO_SIGNATURES,
  MAX_VIDEO_SIZE_BYTES,
} from "@/apps/web/lib/constants/upload";

function getFileExtension(fileName: string): string {
  const index = fileName.lastIndexOf(".");
  return index >= 0 ? fileName.slice(index).toLowerCase() : "";
}

async function readFileHeader(file: File, length: number): Promise<Uint8Array> {
  const buffer = await file.slice(0, length).arrayBuffer();
  return new Uint8Array(buffer);
}

function headerMatchesSignature(header: Uint8Array, offset: number, bytes: readonly number[]): boolean {
  if (header.length < offset + bytes.length) {
    return false;
  }

  return bytes.every((byte, index) => header[offset + index] === byte);
}

export async function validateVideoFile(file: File): Promise<void> {
  const extension = getFileExtension(file.name);
  if (!ACCEPTED_VIDEO_EXTENSIONS.includes(extension as (typeof ACCEPTED_VIDEO_EXTENSIONS)[number])) {
    throw new Error(`対応拡張子は ${ACCEPTED_VIDEO_EXTENSIONS.join(", ")} のみです。`);
  }

  if (file.size > MAX_VIDEO_SIZE_BYTES) {
    throw new Error("ファイルサイズ上限は10GBです。");
  }

  const header = await readFileHeader(file, 16);
  const hasKnownSignature = ACCEPTED_VIDEO_SIGNATURES.some((signature) =>
    headerMatchesSignature(header, signature.offset, signature.bytes),
  );
  if (!hasKnownSignature) {
    throw new Error("動画ファイルヘッダ検証に失敗しました。");
  }
}
