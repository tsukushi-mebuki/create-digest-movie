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


def test_clip_script_contract(repo_root: Path) -> None:
    target = repo_root / "pipelines" / "src" / "clip_pipeline.py"
    content = read_text(target)

    assert_true('required_env("DRIVE_FOLDER_03_COMPLETED_SHORTS")' in content, "DRIVE_FOLDER_03_COMPLETED_SHORTS usage missing.")
    assert_true('"status": "completed"' in content, "Webhook payload status=completed is missing.")
    assert_true('"completed_shorts"' in content, "Webhook payload completed_shorts is missing.")
    assert_true("normalized.sort(key=lambda item: item[\"start_sec\"])" in content, "start_sec ascending sort is missing.")
    assert_true("round(float(item[\"start_sec\"]), 3)" in content, "start_sec 3-digit round is missing.")
    assert_true("round(float(item[\"end_sec\"]), 3)" in content, "end_sec 3-digit round is missing.")
    assert_true("round(float(item[\"duration_sec\"]), 3)" in content, "duration_sec 3-digit round is missing.")
    assert_true('"X-Hub-Signature-256"' in content, "X-Hub-Signature-256 header is missing.")
    assert_true('"X-Webhook-Timestamp"' in content, "X-Webhook-Timestamp header is missing.")
    assert_true("stop=stop_after_attempt(5)" in content, "Webhook retry max attempt(5) is missing.")
    assert_true("wait=wait_exponential" in content, "Exponential backoff retry is missing.")
    assert_true("try:" in content and "finally:" in content, "finally cleanup block is missing.")
    assert_true("remove_temp_files(temp_files_to_cleanup)" in content, "Temporary file cleanup call is missing.")
    assert_true("os.remove(path)" in content, "os.remove cleanup operation is missing.")


def test_clip_workflow_contract(repo_root: Path) -> None:
    workflow = repo_root / ".github" / "workflows" / "pipeline-clips.yml"
    content = read_text(workflow)

    assert_true("timeout-minutes: 180" in content, "Workflow timeout-minutes: 180 is missing.")
    assert_true("DRIVE_FOLDER_03_COMPLETED_SHORTS" in content, "DRIVE_FOLDER_03_COMPLETED_SHORTS env wiring is missing.")
    assert_true("GCP_SERVICE_ACCOUNT_KEY: ${{ secrets.GCP_SERVICE_ACCOUNT_KEY }}" in content, "Secret injection step missing.")
    assert_true("python pipelines/src/clip_pipeline.py" in content, "Clip pipeline execution step missing.")


def run_unit_tests(repo_root: Path) -> None:
    cmd = [sys.executable, "-m", "unittest", "pipelines.tests.test_clip_pipeline", "-v"]
    result = subprocess.run(cmd, cwd=repo_root, capture_output=True, text=True)
    if result.returncode != 0:
        raise TestFailed(
            "Issue #6 unit tests failed.\n"
            f"STDOUT:\n{result.stdout}\n"
            f"STDERR:\n{result.stderr}"
        )


def main() -> None:
    repo_root = Path(__file__).resolve().parents[2]
    test_clip_script_contract(repo_root)
    test_clip_workflow_contract(repo_root)
    run_unit_tests(repo_root)
    print("OK: Issue #6 quality tests passed.")


if __name__ == "__main__":
    try:
        main()
    except TestFailed as exc:
        print(f"FAILED: {exc}", file=sys.stderr)
        raise SystemExit(1)
