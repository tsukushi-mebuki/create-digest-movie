# Jobs API

## POST `/api/jobs/init`

初期化API。フロント受信 `settings` から、サーバー側で正規化 `settings_hash` を再計算し、重複分岐を実施する。

- Request JSON
  - `file_hash`: string (SHA-256)
  - `original_file_name`: string
  - `settings`: object
- Response JSON
  - `job_id`: string (UUID v4)
  - `status`: `pending | completed`
  - `upload_url`: string | null

### 重複分岐

- 完全一致:
  - 条件: `completed` かつ `asset_integrity=valid` かつ `settings_hash` 一致
  - 動作: `source_job_id` を参照で保存し、新規ジョブは `completed` として即時返却
  - レスポンス: `upload_url: null`
- 部分一致:
  - 条件: `file_hash` 一致かつ `settings_hash` 不一致
  - 動作: `source_job_id` を参照で保存し、新規ジョブは `pending` のまま返却
  - レスポンス: `upload_url: null`
- 新規:
  - 条件: 上記に該当しない
  - 動作: Google Drive resumable upload URL を返却
  - 認証は `GCP_SERVICE_ACCOUNT_KEY`（サービスアカウント鍵JSON）から短命トークンを都度発行して行う
  - 互換用途として `GOOGLE_OAUTH_ACCESS_TOKEN` 直指定も許容（ローカル暫定）

## GET `/api/jobs/{job_id}`

ジョブ状態の参照API。`source_job_id` を持ち、自身の `assets` が空の場合でも、API内部で親ジョブの `assets` を透過的に解決して返す。

- Response JSON
  - `job_id`
  - `file_hash`
  - `settings_hash`
  - `original_file_name`
  - `status`
  - `asset_integrity`
  - `settings`
  - `assets` (透過解決済み)
  - `completed_at`
  - `created_at`
  - `updated_at`
