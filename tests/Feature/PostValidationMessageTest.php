<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ポストの入力チェックのメッセージが日本語で出ること（lang/ja は使わず、第2引数で個別に指定）。
 */
class PostValidationMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->category = Category::create(['name' => 'お知らせ']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'タイトル',
            'category_id' => $this->category->id,
            'content' => '本文',
        ], $overrides);
    }

    private function create(array $overrides = [])
    {
        return $this->actingAs($this->user)
            ->from(route('posts.create'))
            ->post(route('posts.store'), $this->payload($overrides));
    }

    private function existingPost(): Post
    {
        return Post::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'title' => '元のタイトル',
            'content' => '元の本文',
        ]);
    }

    // ---- 新規投稿 ----

    public function test_新規投稿でタイトルが空だと日本語のメッセージが出てポストは増えない(): void
    {
        $this->create(['title' => ''])
            ->assertRedirect(route('posts.create'))
            ->assertSessionHasErrors(['title' => 'タイトルを入力してください。']);

        $this->assertSame(0, Post::count());
    }

    public function test_新規投稿でタイトルが256字だと日本語のメッセージが出る(): void
    {
        $this->create(['title' => str_repeat('あ', 256)])
            ->assertSessionHasErrors(['title' => 'タイトルは255字以内で入力してください。']);

        $this->assertSame(0, Post::count());
    }

    public function test_新規投稿でタイトルが255字ちょうどなら通る(): void
    {
        $this->create(['title' => str_repeat('あ', 255)])
            ->assertRedirect(route('posts.index'))
            ->assertSessionHasNoErrors();
    }

    public function test_新規投稿でタイトルが文字でないと日本語のメッセージが出る(): void
    {
        $this->create(['title' => ['配列']])
            ->assertSessionHasErrors(['title' => 'タイトルは文字で入力してください。']);
    }

    public function test_新規投稿でトピック未選択だと日本語のメッセージが出る(): void
    {
        $this->create(['category_id' => ''])
            ->assertSessionHasErrors(['category_id' => 'トピックを選択してください。']);

        $this->assertSame(0, Post::count());
    }

    public function test_新規投稿で存在しないトピックだと日本語のメッセージが出る(): void
    {
        $this->create(['category_id' => 9999])
            ->assertSessionHasErrors(['category_id' => '選択したトピックは存在しません。']);

        $this->assertSame(0, Post::count());
    }

    public function test_エラーメッセージが作成画面に表示される(): void
    {
        $this->create(['title' => '', 'category_id' => ''])->assertRedirect(route('posts.create'));

        $this->actingAs($this->user)
            ->get(route('posts.create'))
            ->assertSee('タイトルを入力してください。')
            ->assertSee('トピックを選択してください。');
    }

    // ---- 編集 ----

    public function test_編集でタイトルが空だと日本語のメッセージが出て更新されない(): void
    {
        $post = $this->existingPost();

        $this->actingAs($this->user)
            ->from(route('posts.edit', $post))
            ->put(route('posts.update', $post), $this->payload(['title' => '']))
            ->assertRedirect(route('posts.edit', $post))
            ->assertSessionHasErrors(['title' => 'タイトルを入力してください。']);

        $this->assertSame('元のタイトル', $post->fresh()->title);
    }

    public function test_編集でトピックが存在しないと日本語のメッセージが出る(): void
    {
        $post = $this->existingPost();

        $this->actingAs($this->user)
            ->from(route('posts.edit', $post))
            ->put(route('posts.update', $post), $this->payload(['category_id' => 9999]))
            ->assertSessionHasErrors(['category_id' => '選択したトピックは存在しません。']);
    }

    // ---- 本文（既存のメッセージは変わらない）----

    public function test_本文のメッセージは今までどおり(): void
    {
        $this->create(['content' => ''])
            ->assertSessionHasErrors(['content' => '本文を入力してください。']);

        $this->create(['content' => str_repeat('あ', 141)])
            ->assertSessionHasErrors(['content' => '本文は140字以内で入力してください。']);
    }
}
