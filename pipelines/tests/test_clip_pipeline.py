import hashlib
import hmac
import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

import tenacity.nap

ROOT_DIR = Path(__file__).resolve().parents[1]
SRC_DIR = ROOT_DIR / "src"
if str(SRC_DIR) not in sys.path:
    sys.path.insert(0, str(SRC_DIR))

import clip_pipeline as cp


class FakeResponse:
    def __init__(self, status_code: int):
        self.status_code = status_code

    def raise_for_status(self) -> None:
        if self.status_code >= 400:
            raise cp.requests.HTTPError(f"HTTP {self.status_code}")


class ClipPipelineTests(unittest.TestCase):
    def test_build_webhook_payload_sorts_and_rounds(self) -> None:
        payload = cp.build_webhook_payload(
            "job-1",
            [
                {
                    "drive_file_id": "f2",
                    "start_sec": 12.5004,
                    "end_sec": 42.4999,
                    "duration_sec": 29.9999,
                },
                {
                    "drive_file_id": "f1",
                    "start_sec": 2.0004,
                    "end_sec": 31.9996,
                    "duration_sec": 29.9992,
                },
            ],
        )

        self.assertEqual("completed", payload["status"])
        self.assertEqual(["f1", "f2"], [item["drive_file_id"] for item in payload["assets"]["completed_shorts"]])
        self.assertEqual(2.0, payload["assets"]["completed_shorts"][0]["start_sec"])
        self.assertEqual(32.0, payload["assets"]["completed_shorts"][0]["end_sec"])
        self.assertEqual(29.999, payload["assets"]["completed_shorts"][0]["duration_sec"])

    def test_post_webhook_with_retry_retries_on_503(self) -> None:
        payload = cp.build_webhook_payload(
            "job-xyz",
            [{"drive_file_id": "short-a", "start_sec": 0, "end_sec": 30, "duration_sec": 30}],
        )
        captured_calls = []
        responses = [FakeResponse(503), FakeResponse(200)]

        def fake_post(url, headers, data, timeout):
            captured_calls.append({"url": url, "headers": headers, "data": data, "timeout": timeout})
            return responses.pop(0)

        with patch.object(cp.requests, "post", side_effect=fake_post):
            with patch.object(cp.time, "time", return_value=1710001234):
                with patch.object(tenacity.nap, "sleep", return_value=None):
                    cp.post_webhook_with_retry("https://example.test/webhook", "secret-1", payload)

        self.assertEqual(2, len(captured_calls))
        last_call = captured_calls[-1]
        expected_body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
        expected_signature = "sha256=" + hmac.new(
            b"secret-1",
            b"1710001234." + expected_body,
            hashlib.sha256,
        ).hexdigest()
        self.assertEqual(30, last_call["timeout"])
        self.assertEqual(expected_body, last_call["data"])
        self.assertEqual(expected_signature, last_call["headers"]["X-Hub-Signature-256"])
        self.assertEqual("1710001234", last_call["headers"]["X-Webhook-Timestamp"])

    def test_remove_temp_files_removes_existing_files(self) -> None:
        with self.subTest("existing files"):
            with patch.object(cp.os, "remove") as remove_mock:
                path = Path("temp.mp4")
                with patch.object(Path, "exists", return_value=True):
                    cp.remove_temp_files([path])
                remove_mock.assert_called_once_with(path)


if __name__ == "__main__":
    unittest.main()
