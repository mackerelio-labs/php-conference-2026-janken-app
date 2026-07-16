# 後出しジャンケンタイムアタック!

PHP カンファレンス 2026 スポンサーブース展示用の後出しじゃんけんゲーム(人 vs CPU。合図と同時に出る CPU の手に、制限時間内に勝つ手を出す)。1 ゲームの流れを OpenTelemetry のトレース・ログ・メトリックとして記録し、Mackerel で可視化する。

## 動かし方

### 初回セットアップ

```sh
# 1. (Mackerel へテレメトリを送る場合のみ)API キーの設定
#    リポジトリ直下の .env に Write 権限キーを書く。手順 3 の backend/.env とは別物。
#    キーなしでも起動できる: Collector が送信失敗のエラーログを出し続けるだけで、
#    ゲームは全部動く
cp .env.example .env

# 2. イメージのビルド
docker compose build

# 3. バックエンド(Laravel)の初期化
#    vendor/ はホスト側 backend/ にマウントされるため、コンテナ内で composer install する。
#    後半の cp と key:generate は backend/.env の生成(手順 1 の .env とは別物)
docker compose run --rm --no-deps -e OTEL_TRACES_EXPORTER=none app sh -c "composer install && cp -n .env.example .env; php artisan key:generate"

# 4. 起動 + DB マイグレーション
docker compose up -d
docker compose run --rm app php artisan migrate --force
```

ゲーム: http://localhost:8080

### 2 回目以降

起動は `docker compose up -d` だけでよい。以下に当てはまるときだけ追加の手順が必要:

| 状況 | やること |
| --- | --- |
| `composer.json` / `composer.lock` が変わった(pull した、依存を更新した) | `docker compose run --rm --no-deps app composer install` |
| `backend/database/migrations/` にマイグレーションが増えた | `docker compose run --rm app php artisan migrate --force` |
| `frontend/` か `docker/` を変更した | `docker compose build && docker compose up -d`(フロントのビルド成果物は web イメージに焼き込まれるため) |

## テスト

テストは SQLite(`:memory:`)で走るため DB コンテナ不要:

```sh
docker compose run --rm --no-deps app php artisan test
```
