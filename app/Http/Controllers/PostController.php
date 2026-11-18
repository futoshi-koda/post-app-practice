<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesContent;
use App\Models\Category;
use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    use ValidatesContent;

    public function index()
    {
        $posts = Post::with(['user', 'category'])->latest()->get();

        return view('posts.index', compact('posts'));
    }

    public function create()
    {
        // 編集画面と兼用。保存されていない空の Post を渡すと、入力欄が空の状態で表示される
        $post = new Post;
        $categories = Category::orderBy('id')->get();

        return view('posts.edit', compact('post', 'categories'));
    }

    public function store(Request $request)
    {
        $validated = $this->validatePost($request);

        // 投稿者は常にログイン中のユーザー。リクエストに user_id が混ざっていても使わない
        $request->user()->posts()->create($validated);

        return redirect()->route('posts.index');
    }

    public function show(Post $post)
    {
        $post->load([
            'user',
            'category',
            'replies' => fn ($query) => $query->with('user')->oldest()->orderBy('id'),
        ]);

        return view('posts.show', compact('post'));
    }

    public function edit(Post $post)
    {
        $this->authorize('update', $post);

        $categories = Category::orderBy('id')->get();

        return view('posts.edit', compact('post', 'categories'));
    }

    public function update(Request $request, Post $post)
    {
        $this->authorize('update', $post);

        $post->update($this->validatePost($request));

        return redirect()->route('posts.index');
    }

    public function destroy(Post $post)
    {
        $this->authorize('delete', $post);

        $post->delete();

        return redirect()->route('posts.index');
    }

    /**
     * 新規投稿と編集で共通の入力チェック（タイトル・トピック・本文）。
     */
    private function validatePost(Request $request): array
    {
        return $this->validateContent($request, [
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
        ], [
            'title.required' => 'タイトルを入力してください。',
            'title.string' => 'タイトルは文字で入力してください。',
            'title.max' => 'タイトルは255字以内で入力してください。',
            'category_id.required' => 'トピックを選択してください。',
            'category_id.exists' => '選択したトピックは存在しません。',
        ]);
    }
}
