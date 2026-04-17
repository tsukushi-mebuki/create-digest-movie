from __future__ import annotations

import subprocess
import sys
from pathlib import Path


class TestFailed(RuntimeError):
    pass


def assert_true(condition: bool, message: str) -> None:
    if not condition:
        raise TestFailed(message)


def read_text(path: Path) -> str:
    if not path.exists():
        raise TestFailed(f"Required file not found: {path}")
    return path.read_text(encoding="utf-8")


def test_transcribe_script_contract(repo_root: Path) -> None:
    target = repo_root / "pipelines" / "src" / "transcribe_pipeline.py"
    content = read_text(target)

    # Why: Issue #5 requires immediate source video deletion to avoid runner disk exhaustion.
    assert_true("os.remove(video_path)" in content, "Source video immediate deletion is missing.")
    assert_true("report_disk_space(before_free, temp_path)" in content, "Free space logging call is missing.")
    assert_true('print(f"Free space: {free_gb:.2f} GB")' in content, "Free space log format is missing.")
    assert_true("freed_gb >= 10" in content, "10GB release threshold check is missing.")

    # Why: Drive folder contracts must be explicit env variables.
    assert_true('required_env("DRIVE_FOLDER_01_ORIGINAL")' in content, "DRIVE_FOLDER_01_ORIGINAL usage missing.")
    assert_true('required_env("DRIVE_FOLDER_02_TEXT_ASSETS")' in content, "DRIVE_FOLDER_02_TEXT_ASSETS usage missing.")
    assert_true('required_env("DRIVE_FOLDER_03_COMPLETED_SHORTS")' in content, "DRIVE_FOLDER_03_COMPLETED_SHORTS usage missing.")

    # Why: Webhook headers must match backend verifier exactly.
    assert_true('"X-Hub-Signature-256"' in content, "X-Hub-Signature-256 header is missing.")
    assert_true('"X-Webhook-Timestamp"' in content, "X-Webhook-Timestamp header is missing.")
    assert_true("hashlib.sha256" in content and "hmac.new" in content, "HMAC-SHA256 signature generation missing.")
    assert_true("stop=stop_after_attempt(5)" in content, "Webhook retry max attempt(5) is missing.")
    assert_true("wait=wait_exponential" in content, "Exponential backoff retry is missing.")

    # Why: JSON contract must send editing status with required assets keys.
    assert_true('"status": "editing"' in content, "Webhook payload status=editing is missing.")
    assert_true('"original_video_id"' in content, "Webhook payload original_video_id is missing.")
    assert_true('"text_asset_id"' in content, "Webhook payload text_asset_id is missing.")


def test_workflow_contract(repo_root: Path) -> None:
    workflow = repo_root / ".github" / "workflows" / "pipeline-transcribe.yml"
    content = read_text(workflow)

    assert_true("timeout-minutes: 180" in content, "Workflow timeout-minutes: 180 is missing.")
    assert_true("GCP_SERVICE_ACCOUNT_KEY: ${{ secrets.GCP_SERVICE_ACCOUNT_KEY }}" in content, "Secret injection step missing.")
    assert_true("GCP_SERVICE_ACCOUNT_KEY_FILE=$KEY_FILE" in content, "Secret file materialization is missing.")
    assert_true("python pipelines/src/transcribe_pipeline.py" in content, "Transcribe pipeline execution step missing.")


def test_requirements(repo_root: Path) -> None:
    requirements = repo_root / "pipelines" / "requirements.txt"
    content = read_text(requirements)
    for pkg in ("faster-whisper", "google-auth", "python-dotenv", "requests", "tenacity"):
        assert_true(pkg in content, f"Required dependency missing: {pkg}")


def run_unit_tests(repo_root: Path) -> None:
    cmd = [sys.executable, "-m", "unittest", "pipelines.tests.test_transcribe_pipeline", "-v"]
    result = subprocess.run(cmd, cwd=repo_root, capture_output=True, text=True)
    if result.returncode != 0:
        raise TestFailed(
            "Issue #5 unit tests failed.\n"
            f"STDOUT:\n{result.stdout}\n"
            f"STDERR:\n{result.stderr}"
        )


def main() -> None:
    repo_root = Path(__file__).resolve().parents[2]
    test_transcribe_script_contract(repo_root)
    test_workflow_contract(repo_root)
    test_requirements(repo_root)
    run_unit_tests(repo_root)
    print("OK: Issue #5 quality tests passed.")


if __name__ == "__main__":
    try:
        main()
    except TestFailed as exc:
        print(f"FAILED: {exc}", file=sys.stderr)
        raise SystemExit(1)
