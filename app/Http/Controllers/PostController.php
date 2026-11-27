<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesContent;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PostController extends Controller
{
    use ValidatesContent;

    public function index(Request $request)
    {
        $tags = Tag::orderBy('id')->get();

        // ?tag={タグの id} があれば、そのタグが付いたポストだけに絞る。空（?tag=）は絞り込みなしと同じ。
        // 存在しないタグのほか、配列（?tag[]=1）や数字だけでない値（?tag=1abc）も 404 にする
        // （DB に渡すと '1abc' が 1 として扱われ、タグ 1 の絞り込みになってしまう）
        $selectedTag = null;
        if ($request->filled('tag')) {
            $tagId = $request->query('tag');
            abort_unless(is_string($tagId) && ctype_digit($tagId), 404);

            $selectedTag = $tags->firstWhere('id', (int) $tagId) ?? abort(404);
        }

        // 投稿者・トピック・タグをまとめて読み込み、新しい順にタイムラインへ一覧表示する。
        // いいねの数（likes_count）と、ログイン中のユーザーが押しているか（liked_by_me）も一緒に取る
        $userId = auth()->id();
        $posts = Post::with(['user', 'category', 'tags' => fn ($query) => $query->orderBy('tags.id')])
            ->when($selectedTag, fn ($query) => $query->whereHas('tags', fn ($q) => $q->where('tags.id', $selectedTag->id)))
            ->withCount('likes')
            ->withExists(['likes as liked_by_me' => fn ($query) => $query->where('users.id', $userId)])
            ->latest()
            ->get();

        return view('posts.index', compact('posts', 'tags', 'selectedTag'));
    }

    public function create()
    {
        // 編集画面と兼用。保存されていない空の Post を渡すと、入力欄が空の状態で表示される
        $post = new Post;
        $categories = Category::orderBy('id')->get();
        $tags = Tag::orderBy('id')->get();

        return view('posts.edit', compact('post', 'categories', 'tags'));
    }

    public function store(Request $request)
    {
        [$validated, $tagIds] = $this->validatedPostAndTagIds($request);

        // ポストの保存とタグの付与は、片方だけ成功した状態を残さないよう一緒に確定する。
        // 投稿者は常にログイン中のユーザー。リクエストに user_id が混ざっていても使わない
        DB::transaction(function () use ($request, $validated, $tagIds) {
            $post = $request->user()->posts()->create($validated);
            $post->tags()->sync($tagIds);
        });

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
        $tags = Tag::orderBy('id')->get();

        return view('posts.edit', compact('post', 'categories', 'tags'));
    }

    public function update(Request $request, Post $post)
    {
        $this->authorize('update', $post);

        [$validated, $tagIds] = $this->validatedPostAndTagIds($request);

        // タグだけの付け替えでは posts の列が変わらないので、updated_at は動かない（「編集済み」にならない）。
        // ポストの更新とタグの付け替えは、一緒に確定する
        DB::transaction(function () use ($post, $validated, $tagIds) {
            $post->update($validated);
            $post->tags()->sync($tagIds);
        });

        return redirect()->route('posts.index');
    }

    public function destroy(Post $post)
    {
        $this->authorize('delete', $post);

        // 投稿者本人だけが削除でき、削除後はタイムラインへ戻る
        $post->delete();

        return redirect()->route('posts.index');
    }

    /**
     * 入力チェックのあと、posts に保存する値とタグの id に分けて返す（posts に tag_ids 列は無い）。
     * タグを 1 つも選ばなかったとき（tag_ids が送られない）は、タグなし（空配列）になる。
     *
     * @return array{0: array, 1: array}
     */
    private function validatedPostAndTagIds(Request $request): array
    {
        $validated = $this->validatePost($request);
        $tagIds = Arr::pull($validated, 'tag_ids') ?? [];

        return [$validated, $tagIds];
    }

    /**
     * 新規投稿と編集で共通の入力チェック（タイトル・トピック・本文・タグ）。
     */
    private function validatePost(Request $request): array
    {
        return $this->validateContent($request, [
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ], [
            'title.required' => 'タイトルを入力してください。',
            'title.string' => 'タイトルは文字で入力してください。',
            'title.max' => 'タイトルは255字以内で入力してください。',
            'category_id.required' => 'トピックを選択してください。',
            'category_id.exists' => '選択したトピックは存在しません。',
            'tag_ids.array' => 'タグの選択が正しくありません。',
            'tag_ids.*.integer' => '選択したタグは存在しません。',
            'tag_ids.*.exists' => '選択したタグは存在しません。',
        ]);
    }
}
