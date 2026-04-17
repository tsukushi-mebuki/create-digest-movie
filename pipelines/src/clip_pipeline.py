import hashlib
import hmac
import json
import os
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Any

import requests
from dotenv import load_dotenv
from tenacity import retry, retry_if_exception_type, stop_after_attempt, wait_exponential


DRIVE_SCOPE = "https://www.googleapis.com/auth/drive"
DRIVE_API_BASE = "https://www.googleapis.com/drive/v3"
DRIVE_UPLOAD_API = "https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart"


class RetryableWebhookError(Exception):
    pass


def required_env(name: str) -> str:
    value = os.getenv(name, "").strip()
    if not value:
        raise RuntimeError(f"Missing required environment variable: {name}")
    return value


def optional_int_env(name: str, default: int) -> int:
    raw = os.getenv(name, "").strip()
    if raw == "":
        return default
    value = int(raw)
    if value <= 0:
        raise RuntimeError(f"{name} must be positive integer.")
    return value


def optional_float_env(name: str, default: float) -> float:
    raw = os.getenv(name, "").strip()
    if raw == "":
        return default
    value = float(raw)
    if value <= 0:
        raise RuntimeError(f"{name} must be positive float.")
    return value


def get_drive_access_token() -> str:
    from google.auth.transport.requests import Request
    from google.oauth2 import service_account

    credentials_path = os.getenv("GCP_SERVICE_ACCOUNT_KEY_FILE", "").strip()
    key_json = os.getenv("GCP_SERVICE_ACCOUNT_KEY", "").strip()
    drive_impersonate_user = os.getenv("GOOGLE_DRIVE_IMPERSONATE_USER", "").strip()

    if credentials_path:
        credentials = service_account.Credentials.from_service_account_file(
            credentials_path, scopes=[DRIVE_SCOPE]
        )
    elif key_json:
        credentials = service_account.Credentials.from_service_account_info(
            json.loads(key_json), scopes=[DRIVE_SCOPE]
        )
    else:
        raise RuntimeError(
            "Either GCP_SERVICE_ACCOUNT_KEY_FILE or GCP_SERVICE_ACCOUNT_KEY must be provided."
        )

    if drive_impersonate_user:
        # Why: shared drive writes may require domain user delegation on CI.
        credentials = credentials.with_subject(drive_impersonate_user)

    credentials.refresh(Request())
    if not credentials.token:
        raise RuntimeError("Failed to mint Google Drive access token.")
    return credentials.token


def get_storage_client():
    from google.cloud import storage

    credentials_path = os.getenv("GCP_SERVICE_ACCOUNT_KEY_FILE", "").strip()
    if credentials_path:
        return storage.Client.from_service_account_json(credentials_path)

    key_json = os.getenv("GCP_SERVICE_ACCOUNT_KEY", "").strip()
    if key_json:
        return storage.Client.from_service_account_info(json.loads(key_json))

    raise RuntimeError("Either GCP_SERVICE_ACCOUNT_KEY_FILE or GCP_SERVICE_ACCOUNT_KEY must be provided.")


def drive_get(url: str, token: str, params: dict[str, Any]) -> dict[str, Any]:
    response = requests.get(
        url,
        headers={"Authorization": f"Bearer {token}"},
        params=params,
        timeout=60,
    )
    response.raise_for_status()
    return response.json()


def ensure_drive_folder_writable(token: str, folder_id: str) -> None:
    response = requests.get(
        f"{DRIVE_API_BASE}/files/{folder_id}",
        headers={"Authorization": f"Bearer {token}"},
        params={
            "fields": "id,name,mimeType,capabilities(canAddChildren)",
            "supportsAllDrives": "true",
        },
        timeout=60,
    )
    response.raise_for_status()
    data = response.json()
    can_add_children = bool(data.get("capabilities", {}).get("canAddChildren"))
    if not can_add_children:
        raise RuntimeError(
            "Drive folder is not writable by current credentials. "
            f"folder_id={folder_id}, folder_name={data.get('name', '')}"
        )


def find_original_video_blob(storage_client, bucket_name: str, prefix: str, job_id: str):
    effective_prefix = prefix.strip("/")
    target_prefix = f"{effective_prefix}/{job_id}/"
    blobs = list(storage_client.list_blobs(bucket_name, prefix=target_prefix, max_results=1))
    if not blobs:
        raise RuntimeError(f"No source video found in gs://{bucket_name}/{target_prefix}")
    return blobs[0]


def find_transcript_json(token: str, folder_id: str, job_id: str) -> dict[str, Any]:
    # Why: job_id appProperties keeps transcript lookup deterministic across jobs.
    query = (
        f"'{folder_id}' in parents and trashed=false and "
        f"name='transcript.json' and appProperties has {{ key='job_id' and value='{job_id}' }}"
    )
    data = drive_get(
        f"{DRIVE_API_BASE}/files",
        token,
        {
            "q": query,
            "fields": "files(id,name,createdTime)",
            "orderBy": "createdTime desc",
            "pageSize": 1,
            "supportsAllDrives": "true",
            "includeItemsFromAllDrives": "true",
        },
    )
    files = data.get("files", [])
    if files:
        return files[0]

    fallback = drive_get(
        f"{DRIVE_API_BASE}/files",
        token,
        {
            "q": f"'{folder_id}' in parents and trashed=false and name='transcript.json'",
            "fields": "files(id,name,createdTime)",
            "orderBy": "createdTime desc",
            "pageSize": 1,
            "supportsAllDrives": "true",
            "includeItemsFromAllDrives": "true",
        },
    ).get("files", [])
    if not fallback:
        raise RuntimeError("No transcript.json found in DRIVE_FOLDER_02_TEXT_ASSETS.")
    print("Warning: transcript.json matched by latest file fallback; appProperties.job_id not found.")
    return fallback[0]


def download_drive_file(token: str, file_id: str, destination: Path) -> None:
    url = f"{DRIVE_API_BASE}/files/{file_id}"
    response = requests.get(
        url,
        headers={"Authorization": f"Bearer {token}"},
        params={"alt": "media", "supportsAllDrives": "true"},
        stream=True,
        timeout=600,
    )
    response.raise_for_status()
    with destination.open("wb") as output:
        for chunk in response.iter_content(chunk_size=8 * 1024 * 1024):
            if chunk:
                output.write(chunk)


def load_segments(transcript_json_path: Path) -> tuple[list[dict[str, Any]], float]:
    payload = json.loads(transcript_json_path.read_text(encoding="utf-8"))
    results = payload.get("results", [])
    if not isinstance(results, list) or not results:
        raise RuntimeError("transcript.json has no results.")
    first = results[0]
    if not isinstance(first, dict):
        raise RuntimeError("transcript.json has invalid result format.")
    segments = first.get("segments", [])
    if not isinstance(segments, list) or not segments:
        raise RuntimeError("transcript.json has no segments.")
    duration = float(first.get("duration", 0))
    return segments, duration


def build_clip_ranges(segments: list[dict[str, Any]], video_duration: float, count: int, max_duration: float) -> list[dict[str, float]]:
    ranges: list[dict[str, float]] = []
    for segment in segments:
        if len(ranges) >= count:
            break
        start = float(segment.get("start", 0))
        if ranges and start < ranges[-1]["end"]:
            continue
        end = min(start + max_duration, video_duration)
        if end <= start:
            continue
        ranges.append({"start": start, "end": end})
    if not ranges:
        raise RuntimeError("No valid clip ranges found from transcript segments.")
    return ranges


def cut_clip(video_path: Path, output_path: Path, start_sec: float, end_sec: float) -> None:
    cmd = [
        "ffmpeg",
        "-y",
        "-ss",
        f"{start_sec:.3f}",
        "-to",
        f"{end_sec:.3f}",
        "-i",
        str(video_path),
        "-c:v",
        "libx264",
        "-c:a",
        "aac",
        "-movflags",
        "+faststart",
        str(output_path),
    ]
    subprocess.run(cmd, check=True)


def upload_completed_short(
    token: str,
    folder_id: str,
    file_path: Path,
    job_id: str,
    start_sec: float,
    end_sec: float,
) -> str:
    metadata = {
        "name": file_path.name,
        "parents": [folder_id],
        "appProperties": {
            "job_id": job_id,
            "start_sec": f"{start_sec:.3f}",
            "end_sec": f"{end_sec:.3f}",
        },
    }
    files = {
        "metadata": ("metadata", json.dumps(metadata), "application/json"),
        "file": (file_path.name, file_path.read_bytes(), "video/mp4"),
    }
    response = requests.post(
        DRIVE_UPLOAD_API,
        headers={"Authorization": f"Bearer {token}"},
        params={"supportsAllDrives": "true"},
        files=files,
        timeout=600,
    )
    if response.status_code == 403:
        message = response.text[:1000]
        raise RuntimeError(
            "Google Drive upload was forbidden (HTTP 403). "
            "Verify that the service account (or delegated user) has editor permission "
            f"for folder_id={folder_id}. Response={message}"
        )
    response.raise_for_status()
    data = response.json()
    file_id = data.get("id")
    if not isinstance(file_id, str) or not file_id:
        raise RuntimeError("Failed to upload completed short to Drive.")
    return file_id


def normalize_short_payload_item(item: dict[str, Any]) -> dict[str, Any]:
    start_sec = round(float(item["start_sec"]), 3)
    end_sec = round(float(item["end_sec"]), 3)
    duration_sec = round(float(item["duration_sec"]), 3)
    return {
        "drive_file_id": item["drive_file_id"],
        "start_sec": start_sec,
        "end_sec": end_sec,
        "duration_sec": duration_sec,
    }


def build_webhook_payload(job_id: str, completed_shorts: list[dict[str, Any]]) -> dict[str, Any]:
    normalized = [normalize_short_payload_item(item) for item in completed_shorts]
    normalized.sort(key=lambda item: item["start_sec"])
    return {
        "job_id": job_id,
        "status": "completed",
        "assets": {
            "completed_shorts": normalized,
        },
    }


def sign_webhook_payload(secret: str, timestamp: str, payload_bytes: bytes) -> str:
    message = timestamp.encode("utf-8") + b"." + payload_bytes
    digest = hmac.new(secret.encode("utf-8"), message, hashlib.sha256).hexdigest()
    return f"sha256={digest}"


@retry(
    stop=stop_after_attempt(5),
    wait=wait_exponential(multiplier=1, min=1, max=30),
    retry=retry_if_exception_type((RetryableWebhookError, requests.RequestException)),
    reraise=True,
)
def post_webhook_with_retry(webhook_url: str, secret: str, payload: dict[str, Any]) -> None:
    # Why: backend verifies timestamp freshness (5 min), so each retry must re-sign with current timestamp.
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


def remove_temp_files(paths: list[Path]) -> None:
    for path in paths:
        try:
            if path.exists():
                os.remove(path)
        except OSError as exc:
            print(f"Warning: failed to delete temporary file {path}: {exc}")


def main() -> None:
    load_dotenv()

    job_id = required_env("JOB_ID")
    webhook_url = required_env("WEBHOOK_URL")
    webhook_secret = required_env("WEBHOOK_SECRET")
    gcs_bucket = required_env("GCS_UPLOAD_BUCKET")
    gcs_prefix = os.getenv("GCS_UPLOAD_PREFIX", "originals")
    folder_text_assets = required_env("DRIVE_FOLDER_02_TEXT_ASSETS")
    folder_completed = required_env("DRIVE_FOLDER_03_COMPLETED_SHORTS")
    short_count = optional_int_env("SHORT_COUNT", 3)
    max_duration = optional_float_env("SHORT_MAX_DURATION_SEC", 30.0)

    storage_client = get_storage_client()
    token = get_drive_access_token()
    ensure_drive_folder_writable(token, folder_completed)
    source_blob = find_original_video_blob(storage_client, gcs_bucket, gcs_prefix, job_id)
    source_name = Path(source_blob.name).name or f"{job_id}.mp4"
    source_video = {"id": f"gs://{gcs_bucket}/{source_blob.name}", "name": source_name}
    transcript_json = find_transcript_json(token, folder_text_assets, job_id)
    print(f"Source video selected: {source_video['name']} ({source_video['id']})")
    print(f"Transcript selected: {transcript_json['name']} ({transcript_json['id']})")

    completed_shorts: list[dict[str, Any]] = []
    with tempfile.TemporaryDirectory(prefix="clips-") as temp_dir:
        temp_path = Path(temp_dir)
        video_path = temp_path / source_video["name"]
        transcript_path = temp_path / "transcript.json"
        temp_files_to_cleanup: list[Path] = [video_path, transcript_path]
        try:
            source_blob.download_to_filename(str(video_path))
            download_drive_file(token, transcript_json["id"], transcript_path)

            segments, duration = load_segments(transcript_path)
            clip_ranges = build_clip_ranges(segments, duration, short_count, max_duration)

            for idx, clip_range in enumerate(clip_ranges, start=1):
                start_sec = clip_range["start"]
                end_sec = clip_range["end"]
                clip_name = f"{job_id}_short_{idx:02}.mp4"
                clip_path = temp_path / clip_name
                temp_files_to_cleanup.append(clip_path)
                cut_clip(video_path, clip_path, start_sec, end_sec)
                drive_file_id = upload_completed_short(
                    token,
                    folder_completed,
                    clip_path,
                    job_id,
                    start_sec,
                    end_sec,
                )
                completed_shorts.append(
                    {
                        "drive_file_id": drive_file_id,
                        "start_sec": start_sec,
                        "end_sec": end_sec,
                        "duration_sec": end_sec - start_sec,
                    }
                )
                print(f"Uploaded completed short: {clip_name} ({drive_file_id})")
        finally:
            # Why: runner local disk must be cleaned even on ffmpeg crash or unexpected exception.
            remove_temp_files(temp_files_to_cleanup)

    payload = build_webhook_payload(job_id, completed_shorts)
    post_webhook_with_retry(webhook_url, webhook_secret, payload)
    print("Completed webhook delivered successfully.")


if __name__ == "__main__":
    main()
