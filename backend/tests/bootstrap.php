<?php

// compose の app サービスは実行時の DB 接続(pgsql)を実環境変数で持ち込む。Laravel の env() は
// $_SERVER を最優先で読むため(vlucas/phpdotenv の既定アダプタ順)、phpunit.xml の <env>(force
// 付きでも $_ENV にしか書かない)では上書きできない。テストは常に SQLite :memory: で走らせたい
// ので、ここで $_SERVER ごと固定する。
// OTEL_SDK_DISABLED: テストは Collector なしで走る。auto-instrumentation を生かしたままだと
// 終了時の export リトライ待ちでテストが数十秒〜数分遅くなる
foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'OTEL_SDK_DISABLED' => 'true'] as $name => $value) {
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv("{$name}={$value}");
}

require __DIR__.'/../vendor/autoload.php';
