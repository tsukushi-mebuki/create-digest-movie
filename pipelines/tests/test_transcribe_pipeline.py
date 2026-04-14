import hashlib
import hmac
import json
import sys
import unittest
from collections import namedtuple
from pathlib import Path
from unittest.mock import patch

import tenacity.nap

ROOT_DIR = Path(__file__).resolve().parents[1]
SRC_DIR = ROOT_DIR / "src"
if str(SRC_DIR) not in sys.path:
    sys.path.insert(0, str(SRC_DIR))

import transcribe_pipeline as tp


class FakeResponse:
    def __init__(self, status_code: int):
        self.status_code = status_code

    def raise_for_status(self) -> None:
        if self.status_code >= 400:
            raise tp.requests.HTTPError(f"HTTP {self.status_code}")


class TranscribePipelineTests(unittest.TestCase):
    def test_sign_webhook_payload_matches_expected_hash(self) -> None:
        timestamp = "1710000000"
        payload = b'{"job_id":"abc","status":"editing"}'
        secret = "test-secret"

        expected = "sha256=" + hmac.new(
            secret.encode("utf-8"),
            f"{timestamp}.".encode("utf-8") + payload,
            hashlib.sha256,
        ).hexdigest()

        actual = tp.sign_webhook_payload(secret, timestamp, payload)

        self.assertEqual(expected, actual)

    def test_build_webhook_payload_uses_contract_shape(self) -> None:
        payload = tp.build_webhook_payload("job-1", "video-1", "text-1")

        self.assertEqual("job-1", payload["job_id"])
        self.assertEqual("editing", payload["status"])
        self.assertEqual("video-1", payload["assets"]["original_video_id"])
        self.assertEqual("text-1", payload["assets"]["text_asset_id"])
        self.assertEqual({"job_id", "status", "assets"}, set(payload.keys()))

    def test_report_disk_space_logs_free_space(self) -> None:
        usage_tuple = namedtuple("usage", ["total", "used", "free"])
        before_free = 100 * 1024**3
        after_free = 112 * 1024**3

        with patch.object(tp.shutil, "disk_usage", return_value=usage_tuple(0, 0, after_free)):
            with patch("builtins.print") as print_mock:
                tp.report_disk_space(before_free, Path("."))

        printed = "\n".join(args[0] for args, _ in print_mock.call_args_list)
        self.assertIn("Free space: 112.00 GB", printed)
        self.assertIn("Freed by source video deletion: 12.00 GB", printed)
        self.assertIn("Freed space check passed", printed)

    def test_post_webhook_with_retry_retries_on_503_and_sends_headers(self) -> None:
        payload = tp.build_webhook_payload("job-xyz", "video-id", "text-id")
        captured_calls = []
        responses = [FakeResponse(503), FakeResponse(503), FakeResponse(200)]

        def fake_post(url, headers, data, timeout):
            captured_calls.append(
                {
                    "url": url,
                    "headers": headers,
                    "data": data,
                    "timeout": timeout,
                }
            )
            return responses.pop(0)

        with patch.object(tp.requests, "post", side_effect=fake_post):
            with patch.object(tp.time, "time", return_value=1710001234):
                with patch.object(tenacity.nap, "sleep", return_value=None):
                    tp.post_webhook_with_retry("https://example.test/webhook", "secret-1", payload)

        self.assertEqual(3, len(captured_calls))
        last_call = captured_calls[-1]
        self.assertEqual("https://example.test/webhook", last_call["url"])
        self.assertEqual(30, last_call["timeout"])

        signature_header = last_call["headers"].get("X-Hub-Signature-256")
        timestamp_header = last_call["headers"].get("X-Webhook-Timestamp")
        self.assertEqual("1710001234", timestamp_header)
        self.assertIsNotNone(signature_header)

        expected_body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
        self.assertEqual(expected_body, last_call["data"])

        expected_signature = "sha256=" + hmac.new(
            b"secret-1",
            b"1710001234." + expected_body,
            hashlib.sha256,
        ).hexdigest()
        self.assertEqual(expected_signature, signature_header)


if __name__ == "__main__":
    unittest.main()
