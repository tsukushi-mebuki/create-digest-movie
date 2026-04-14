.PHONY: help test-issue1 test-issue1-insert

help:
	@echo "Available commands:"
	@echo "  make test-issue1         # Run Issue #1 quality tests in api container"
	@echo "  make test-issue1-insert  # Run pending insert verification script"

test-issue1:
	docker compose exec api php /var/www/tests/run_issue1_quality_tests.php

test-issue1-insert:
	docker compose exec api php /var/www/scripts/test_insert_pending_job.php
