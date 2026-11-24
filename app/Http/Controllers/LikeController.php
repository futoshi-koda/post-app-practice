<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    /**
     * いいねする。すでに押していても数は増えない（二重送信しても 1 件のまま）。
     */
    public function store(Request $request, Post $post)
    {
        $post->likes()->syncWithoutDetaching([$request->user()->id]);

        return redirect()->route('posts.index');
    }

    /**
     * いいねを取り消す。押していなくてもエラーにはしない。
     */
    public function destroy(Request $request, Post $post)
    {
        $post->likes()->detach($request->user()->id);

        return redirect()->route('posts.index');
    }
}
