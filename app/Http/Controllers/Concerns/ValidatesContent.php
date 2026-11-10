<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * ポストとリプライで共通の「本文」の入力チェック（必須・140字以内）。
 * 文字数の数え方・メッセージを変えるときは、ここだけを直す。
 */
trait ValidatesContent
{
    /**
     * 本文をチェックし、検証済みの値を返す。本文以外の項目は $rules で足す。
     */
    protected function validateContent(Request $request, array $rules = []): array
    {
        // ブラウザは textarea の改行を \r\n で送るため、1 改行を 1 字として数えるよう \n に揃える
        $request->merge([
            'content' => str_replace("\r\n", "\n", (string) $request->input('content')),
        ]);

        return $request->validate($rules + [
            'content' => 'required|string|max:140',
        ], [
            'content.required' => '本文を入力してください。',
            'content.max' => '本文は140字以内で入力してください。',
        ]);
    }
}
