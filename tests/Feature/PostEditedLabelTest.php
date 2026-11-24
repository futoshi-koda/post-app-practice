<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * タイムラインの「編集済み」表示（一度でも編集されたポストにだけ出る）。
 */
class PostEditedLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $other;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => '投稿する人']);
        $this->other = User::factory()->create(['name' => 'ほかの人']);

        $this->category = Category::create(['name' => '技術メモ']);
    }

    /**
     * 作成日時を過去に固定したポスト。保存直後と更新が同じ秒に収まって
     * 「編集済み」にならない、というテストの偶然を避ける。
     */
    private function existingPost(User $user, string $title): Post
    {
        $post = Post::create([
            'user_id' => $user->id,
            'category_id' => $this->category->id,
            'title' => $title,
            'content' => '既存の本文',
        ]);

        $past = now()->subHour();
        $post->forceFill(['created_at' => $past, 'updated_at' => $past])->save();

        return $post;
    }

    public function test_一度も編集していないポストには編集済みが出ない(): void
    {
        $this->existingPost($this->user, '未編集のタイトル');

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertSee('未編集のタイトル')
            ->assertDontSee('編集済み');
    }

    public function test_自分のポストを編集して保存するとタイムラインに編集済みが出る(): void
    {
        $post = $this->existingPost($this->user, '元のタイトル');

        $this->actingAs($this->user)
            ->put(route('posts.update', $post), [
                'title' => '直したタイトル',
                'category_id' => $this->category->id,
                'content' => '直した本文',
            ])
            ->assertRedirect(route('posts.index'));

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertSee('直したタイトル')
            ->assertSee('編集済み');
    }

    public function test_編集済みは編集したポストにだけ出て_ほかのポストには出ない(): void
    {
        $edited = $this->existingPost($this->user, '編集するポスト');
        $this->existingPost($this->other, 'そのままのポスト');

        $this->actingAs($this->user)
            ->put(route('posts.update', $edited), [
                'title' => '編集したポスト',
                'category_id' => $this->category->id,
                'content' => '直した本文',
            ]);

        $html = $this->actingAs($this->user)->get(route('posts.index'))->assertOk()->getContent();

        // 並び順に依存しないよう、ポスト（<article>）ごとに分けて確かめる
        $articles = array_slice(explode('<article', $html), 1);
        $this->assertCount(2, $articles);

        foreach ($articles as $article) {
            if (str_contains($article, '編集したポスト')) {
                $this->assertStringContainsString('編集済み', $article);
            } else {
                $this->assertStringContainsString('そのままのポスト', $article);
                $this->assertStringNotContainsString('編集済み', $article);
            }
        }
    }

    public function test_リプライが付いただけでは編集済みにならない(): void
    {
        $post = $this->existingPost($this->user, '未編集のタイトル');

        $this->actingAs($this->other)
            ->post(route('replies.store', $post), ['content' => 'リプライです'])
            ->assertRedirect(route('posts.show', $post));

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertDontSee('編集済み');
    }

    public function test_編集済みが出ても_編集と削除は今までどおり本人にだけ出る(): void
    {
        $mine = $this->existingPost($this->user, '自分のポスト');
        $theirs = $this->existingPost($this->other, '他人のポスト');

        $this->actingAs($this->user)
            ->put(route('posts.update', $mine), [
                'title' => '自分のポスト（直した）',
                'category_id' => $this->category->id,
                'content' => '直した本文',
            ]);

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertSee('編集済み')
            ->assertSee(route('posts.edit', $mine))
            ->assertSee('action="'.route('posts.destroy', $mine).'"', false)
            ->assertDontSee(route('posts.edit', $theirs))
            ->assertDontSee('action="'.route('posts.destroy', $theirs).'"', false);
    }

    public function test_編集したポストを削除できる(): void
    {
        $post = $this->existingPost($this->user, '編集して消すポスト');

        $this->actingAs($this->user)
            ->put(route('posts.update', $post), [
                'title' => '直したタイトル',
                'category_id' => $this->category->id,
                'content' => '直した本文',
            ]);

        $this->actingAs($this->user)
            ->delete(route('posts.destroy', $post))
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('posts', 0);
    }
}
