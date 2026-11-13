# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 概要

Tutorial 13〜15 の教材用の小さな投稿アプリ（Laravel 10 / PHP / MySQL / Laravel Sail / Laravel Fortify）。ドキュメント・コメントは日本語で書かれているので、それに合わせる。

## コマンド

すべて Sail（Docker）経由で実行する。ホストの `php` / `composer` は前提にしない。

```bash
./vendor/bin/sail up -d                     # 起動（http://localhost）
./vendor/bin/sail down                      # 停止
./vendor/bin/sail artisan migrate --seed    # テーブル作成＋練習用データ
./vendor/bin/sail artisan migrate:fresh --seed  # DB を作り直す

./vendor/bin/sail artisan test                                  # 全テスト
./vendor/bin/sail artisan test --filter=テストメソッド名またはクラス名  # 1 件だけ
./vendor/bin/sail artisan test tests/Feature/ExampleTest.php    # ファイル指定

./vendor/bin/sail pint                      # コード整形（Laravel Pint）
./vendor/bin/sail npm run dev               # Vite
```

- テストは `phpunit.xml` で `DB_DATABASE=testing` を使う。`testing` DB は Sail の MySQL コンテナ初回起動時に自動作成される（`compose.yaml`）。
- 初回起動直後の `migrate` で「Connection refused」が出たら MySQL の起動待ちなので、少し待って再実行する。

## アーキテクチャ

- **認証は Fortify**。ログイン・登録・ログアウトのルートは Fortify が提供し、`routes/web.php` には書かれていない。有効な機能は `config/fortify.php`（registration, resetPasswords）。ビューは `FortifyServiceProvider` で `auth.login` / `auth.register` に割り当て、ユーザー作成ロジックは `app/Actions/Fortify/CreateNewUser.php`。ログイン後の遷移先は `/posts`（`config/fortify.php` の `home` と `RouteServiceProvider::HOME`）。
- **投稿機能**は `PostController`（index / edit / update / destroy のみ。新規作成・詳細表示はない）。ルートはすべて `auth` ミドルウェア内。
- **認可は `PostPolicy`**（update / delete とも「投稿者本人のみ」）。`AuthServiceProvider::$policies` には登録しておらず、Laravel の自動検出に頼っている。コントローラで `$this->authorize()` を呼び、他人の投稿は 403。ビュー側でも編集・削除ボタンを本人にしか出さない。
- **データモデル**: `User` 1—* `Post` *—1 `Category`。カテゴリは seeder で固定 3 件（お知らせ・技術メモ・雑記）を入れるだけで、管理画面はない。
- ファクトリは `UserFactory` のみ。`Post` / `Category` のテストデータが必要なら作る必要がある。
- 練習用アカウント（seeder）: `usera@example.com` / `userb@example.com`、パスワードはどちらも `password`。

## アプリ以外のフォルダ

- `docs/` — 学習者が Tutorial 13 で作る設計書（章ごとに `13-N/`）。`.md` と draw.io（`.drawio` / `.png`）。
- `answers/` — 設計書の解答例。アプリのコードとは無関係で、機能追加時に読む必要はない。学習者の `docs/` と見比べる・遅れた章を `cp answers/13-N/* docs/13-N/` で追いつくための置き場。
- ファイル名の接頭辞: `post-` はこのアプリ、`cafe-` は章末演習のカフェ注文アプリ、`register-` は会員登録のテスト設計。
