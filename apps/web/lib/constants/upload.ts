export const MAX_VIDEO_SIZE_BYTES = 10 * 1024 * 1024 * 1024;
export const HASH_CHUNK_SIZE_BYTES = 5 * 1024 * 1024;
export const HASH_PROGRESS_THROTTLE_MS = 200;

export const ACCEPTED_VIDEO_EXTENSIONS = [".mp4", ".mov", ".webm", ".mkv"] as const;

export const ACCEPTED_VIDEO_SIGNATURES: ReadonlyArray<{
  readonly label: string;
  readonly offset: number;
  readonly bytes: readonly number[];
}> = [
  {
    // ISO BMFF family (mp4/mov) checks "ftyp" at byte offset 4.
    label: "mp4/mov",
    offset: 4,
    bytes: [0x66, 0x74, 0x79, 0x70],
  },
  {
    label: "webm",
    offset: 0,
    bytes: [0x1a, 0x45, 0xdf, 0xa3],
  },
  {
    // MKV also starts with EBML signature.
    label: "mkv",
    offset: 0,
    bytes: [0x1a, 0x45, 0xdf, 0xa3],
  },
];
