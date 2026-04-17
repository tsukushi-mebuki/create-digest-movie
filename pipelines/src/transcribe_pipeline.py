import hashlib
import hmac
import json
import os
import shutil
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Any

import requests
from dotenv import load_dotenv
from tenacity import retry, retry_if_exception_type, stop_after_attempt, wait_exponential


class RetryableWebhookError(Exception):
    pass


def required_env(name: str) -> str:
    value = os.getenv(name, "").strip()
    if not value:
        raise RuntimeError(f"Missing required environment variable: {name}")
    return value


def get_storage_client():
    from google.cloud import storage

    credentials_path = os.getenv("GCP_SERVICE_ACCOUNT_KEY_FILE", "").strip()
    if credentials_path:
        return storage.Client.from_service_account_json(credentials_path)

    key_json = os.getenv("GCP_SERVICE_ACCOUNT_KEY", "").strip()
    if key_json:
        return storage.Client.from_service_account_info(json.loads(key_json))

    raise RuntimeError("Either GCP_SERVICE_ACCOUNT_KEY_FILE or GCP_SERVICE_ACCOUNT_KEY must be provided.")


def find_original_video_blob(storage_client, bucket_name: str, prefix: str, job_id: str):
    effective_prefix = prefix.strip("/")
    target_prefix = f"{effective_prefix}/{job_id}/"
    blobs = list(storage_client.list_blobs(bucket_name, prefix=target_prefix, max_results=1))
    if not blobs:
        raise RuntimeError(f"No source video found in gs://{bucket_name}/{target_prefix}")
    return blobs[0]


def extract_audio(video_path: Path, audio_path: Path) -> None:
    cmd = [
        "ffmpeg",
        "-y",
        "-i",
        str(video_path),
        "-vn",
        "-acodec",
        "pcm_s16le",
        "-ar",
        "16000",
        "-ac",
        "1",
        str(audio_path),
    ]
    subprocess.run(cmd, check=True)


def report_disk_space(before_free: int, target_dir: Path) -> float:
    after_free = shutil.disk_usage(target_dir).free
    freed_gb = (after_free - before_free) / (1024**3)
    free_gb = after_free / (1024**3)
    print(f"Free space: {free_gb:.2f} GB")
    print(f"Freed by source video deletion: {freed_gb:.2f} GB")
    if freed_gb >= 10:
        print("Freed space check passed: >= 10 GB released.")
    else:
        print("Freed space check warning: released space is below 10 GB.")
    return free_gb


def transcribe(audio_path: Path) -> tuple[list[dict[str, Any]], str]:
    from faster_whisper import WhisperModel

    model_name = os.getenv("WHISPER_MODEL", "small")
    model = WhisperModel(model_name, device="cpu", compute_type="int8")
    segments, info = model.transcribe(str(audio_path), beam_size=5)

    records: list[dict[str, Any]] = []
    srt_chunks: list[str] = []
    for idx, segment in enumerate(segments, start=1):
        records.append(
            {
                "id": idx,
                "start": segment.start,
                "end": segment.end,
                "text": segment.text.strip(),
            }
        )
        srt_chunks.append(
            f"{idx}\n{format_srt_time(segment.start)} --> {format_srt_time(segment.end)}\n{segment.text.strip()}\n"
        )

    transcript = {
        "language": info.language,
        "duration": info.duration,
        "segments": records,
    }
    return [transcript], "\n".join(srt_chunks)


def format_srt_time(seconds: float) -> str:
    millis = int(round(seconds * 1000))
    hours = millis // 3_600_000
    millis %= 3_600_000
    minutes = millis // 60_000
    millis %= 60_000
    secs = millis // 1_000
    ms = millis % 1_000
    return f"{hours:02}:{minutes:02}:{secs:02},{ms:03}"


def upload_text_asset(
    storage_client,
    bucket_name: str,
    object_name: str,
    file_path: Path,
    mime_type: str,
) -> str:
    blob = storage_client.bucket(bucket_name).blob(object_name)
    blob.upload_from_filename(str(file_path), content_type=mime_type)
    return f"gs://{bucket_name}/{object_name}"


def sign_webhook_payload(secret: str, timestamp: str, payload_bytes: bytes) -> str:
    message = timestamp.encode("utf-8") + b"." + payload_bytes
    digest = hmac.new(secret.encode("utf-8"), message, hashlib.sha256).hexdigest()
    return f"sha256={digest}"


def build_webhook_payload(job_id: str, original_video_id: str, text_asset_id: str) -> dict[str, Any]:
    return {
        "job_id": job_id,
        "status": "editing",
        "assets": {
            "original_video_id": original_video_id,
            "text_asset_id": text_asset_id,
        },
    }


@retry(
    stop=stop_after_attempt(5),
    wait=wait_exponential(multiplier=1, min=1, max=30),
    retry=retry_if_exception_type((RetryableWebhookError, requests.RequestException)),
    reraise=True,
)
def post_webhook_with_retry(webhook_url: str, secret: str, payload: dict[str, Any]) -> None:
    timestamp = str(int(time.time()))
    payload_bytes = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    signature = sign_webhook_payload(secret, timestamp, payload_bytes)
    headers = {
        "Content-Type": "application/json",
        "X-Hub-Signature-256": signature,
        "X-Webhook-Timestamp": timestamp,
    }
    response = requests.post(webhook_url, headers=headers, data=payload_bytes, timeout=30)
    if response.status_code >= 500:
        raise RetryableWebhookError(f"Webhook temporary failure: HTTP {response.status_code}")
    response.raise_for_status()


def main() -> None:
    load_dotenv()

    job_id = required_env("JOB_ID")
    webhook_url = required_env("WEBHOOK_URL")
    webhook_secret = required_env("WEBHOOK_SECRET")
    gcs_bucket = required_env("GCS_UPLOAD_BUCKET")
    gcs_prefix = os.getenv("GCS_UPLOAD_PREFIX", "originals")
    gcs_text_assets_prefix = os.getenv("GCS_TEXT_ASSETS_PREFIX", "text-assets").strip("/")
    required_env("DRIVE_FOLDER_01_ORIGINAL")
    required_env("DRIVE_FOLDER_02_TEXT_ASSETS")
    required_env("DRIVE_FOLDER_03_COMPLETED_SHORTS")

    storage_client = get_storage_client()
    source_blob = find_original_video_blob(storage_client, gcs_bucket, gcs_prefix, job_id)
    source_name = Path(source_blob.name).name or f"{job_id}.mp4"
    original_video_id = f"gs://{gcs_bucket}/{source_blob.name}"

    print(f"Source video selected: {source_name} ({original_video_id})")

    with tempfile.TemporaryDirectory(prefix="transcribe-") as temp_dir:
        temp_path = Path(temp_dir)
        video_path = temp_path / source_name
        audio_path = temp_path / "audio.wav"
        srt_path = temp_path / "transcript.srt"
        json_path = temp_path / "transcript.json"

        before_free = shutil.disk_usage(temp_path).free
        source_blob.download_to_filename(str(video_path))
        print(f"Downloaded source video: {video_path}")

        extract_audio(video_path, audio_path)
        os.remove(video_path)
        report_disk_space(before_free, temp_path)

        transcripts, srt_text = transcribe(audio_path)
        json_path.write_text(
            json.dumps({"job_id": job_id, "results": transcripts}, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
        srt_path.write_text(srt_text, encoding="utf-8")

        srt_id = upload_text_asset(
            storage_client,
            gcs_bucket,
            f"{gcs_text_assets_prefix}/{job_id}/transcript.srt",
            srt_path,
            "application/x-subrip",
        )
        json_id = upload_text_asset(
            storage_client,
            gcs_bucket,
            f"{gcs_text_assets_prefix}/{job_id}/transcript.json",
            json_path,
            "application/json",
        )
        print(f"Uploaded transcript.srt to GCS: {srt_id}")
        print(f"Uploaded transcript.json to GCS: {json_id}")

    payload = build_webhook_payload(job_id, original_video_id, json_id)
    post_webhook_with_retry(webhook_url, webhook_secret, payload)
    print("Webhook delivered successfully.")


if __name__ == "__main__":
    main()
