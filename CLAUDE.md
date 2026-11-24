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
- **投稿機能**は `PostController`（index / create / store / show / edit / update / destroy）。リプライは `ReplyController`（store / destroy）。ルートはすべて `auth` ミドルウェア内。
- **認可は `PostPolicy` と `ReplyPolicy`**。ポストは update / delete とも、リプライは delete が「投稿者本人のみ」（ポストの作者でも他人のリプライは消せない）。`AuthServiceProvider::$policies` には登録しておらず、Laravel の自動検出に頼っている。コントローラで `$this->authorize()` を呼び、他人のものは 403。ビュー側でも編集・削除ボタンを本人にしか出さない。
- **データモデル**: `User` 1—* `Post` *—1 `Category`、`Post` 1—* `Reply` *—1 `User`。いいねは `likes` テーブル（`user_id` + `post_id` で一意）で、`Post::likes()` が `User` との多対多。タイムラインのボタンは `POST /posts/{post}/like` と `/unlike`（取り消しを DELETE にしないのは、既存テストが `value="DELETE"` の有無で削除ボタンを見分けているため）。カテゴリは seeder で固定 3 件（お知らせ・技術メモ・雑記）を入れるだけで、管理画面はない。
- ファクトリは `UserFactory` のみ。`Post` / `Category` のテストデータが必要なら作る必要がある。
- 練習用アカウント（seeder）: `usera@example.com` / `userb@example.com`、パスワードはどちらも `password`。

## アプリの前提

依頼文に毎回書かなくてよいように、ここに置く。この節を正とする。コードが食い違っていたら、コードのほうを直す対象として扱い、勝手に直さず報告する。

### タイムライン（`/posts`）

- タイムラインの今ある動き（一覧・編集・削除）を壊さない。

### 入力チェックのメッセージ

- 画面に出す入力チェックのメッセージは日本語で書く。
- 本文（必須・140 字以内）のメッセージは `ValidatesContent` に定義済み。ポストとリプライで共通なので、ここだけを直す。
- `lang/ja` は使わない（`locale` は `en` のまま）。メッセージは `$request->validate($rules, $messages)` の第2引数で、ルールごとに個別に指定する。新しい入力チェックを足すときも同じ書き方に合わせる。
  - 例: タイトル・トピックは `PostController::validatePost` から `validateContent` の `$messages` 引数に渡している。
- Fortify の Actions（新規登録・パスワード変更・リセット・プロフィール更新）とログイン失敗のメッセージは、まだ英語のまま。

## 作業の進め方

### PR

- PR の本文に、次の 2 つを書く。
  - テスト結果（`sail test` の結果）。
  - 受け入れ条件の突き合わせ（条件ごとに、満たしたか・どのテストや画面で確かめたか）。

## アプリ以外のフォルダ

- `docs/` — 学習者が Tutorial 13 で作る設計書（章ごとに `13-N/`）。`.md` と draw.io（`.drawio` / `.png`）。
- `answers/` — 設計書の解答例。アプリのコードとは無関係で、機能追加時に読む必要はない。学習者の `docs/` と見比べる・遅れた章を `cp answers/13-N/* docs/13-N/` で追いつくための置き場。
- ファイル名の接頭辞: `post-` はこのアプリ、`cafe-` は章末演習のカフェ注文アプリ、`register-` は会員登録のテスト設計。
