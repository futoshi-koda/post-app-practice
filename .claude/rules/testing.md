# テストの決まり

`tests/` の既存テスト（`PostCreateTest` / `PostContentLimitTest` / `PostValidationMessageTest` / `ReplyTest`）の書き方に合わせる。

## 進め方

- 機能を足す・直すときは、テストも書く。
- `./vendor/bin/sail test` で全体を回し、落ちたら通るまで直す。最後に結果（通った件数・直した内容）を報告する。
- 通らないまま終わるときは、通らないテストとその理由を、そのまま報告する。

## 置き場所と形

- アプリの動きのテストは `tests/Feature/` に、機能ごとのクラスとして置く（例: `PostCreateTest`、`ReplyTest`）。HTTP リクエストを送って、画面・リダイレクト・DB で確かめる。
- クラスは `Tests\TestCase` を継承し、`RefreshDatabase` を使う。DB は `phpunit.xml` の `testing` を使うので、開発用のデータには触れない。
- クラスの docblock に、何のテストかを日本語で一行書く（設計書があれば `docs/` のファイル名も）。

## 確かめること

- URL は `route()` で書く。`/posts/create` が `{post}` に取られないかを確かめる場合のように、URL そのものが対象のときだけ文字列で書く。
- 入力チェックのエラーは、メッセージまで確かめる: `assertSessionHasErrors(['content' => '本文は140字以内で入力してください。'])`。メッセージは日本語で、`$request->validate($rules, $messages)` の指定どおりに出ること。
- 既存の動き（タイムラインの一覧・編集・削除）を壊さないことを、「今までどおり」のテストで確かめる。変更した機能の周りの、既存の画面も回帰として押さえる。
