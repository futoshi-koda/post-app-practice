<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * タイムラインのポストへのいいね（ボタンと数の表示、付ける・外す、アカウントごとの状態）。
 */
class PostLikeTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $me;

    private User $other;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['name' => 'ポスト作者']);
        $this->me = User::factory()->create(['name' => '自分']);
        $this->other = User::factory()->create(['name' => 'ほかの人']);

        $category = Category::create(['name' => 'お知らせ']);
        $this->post = $this->makePost('いいねされるポスト', $category);
    }

    private function makePost(string $title, ?Category $category = null): Post
    {
        return Post::create([
            'user_id' => $this->author->id,
            'category_id' => ($category ?? Category::first())->id,
            'title' => $title,
            'content' => '本文',
        ]);
    }

    private function like(User $user, ?Post $post = null): void
    {
        ($post ?? $this->post)->likes()->attach($user->id);
    }

    /**
     * タイムラインのうち、指定したタイトルのポスト（<article>）の HTML だけを返す。
     */
    private function article(User $as, string $title): string
    {
        $html = $this->actingAs($as)->get(route('posts.index'))->assertOk()->getContent();

        foreach (array_slice(explode('<article', $html), 1) as $article) {
            if (str_contains($article, $title)) {
                return $article;
            }
        }

        $this->fail("タイムラインに「{$title}」のポストがない");
    }

    private function likeCount(string $article): int
    {
        $this->assertMatchesRegularExpression('#<span class="like-count">(\d+)</span>#', $article);
        preg_match('#<span class="like-count">(\d+)</span>#', $article, $m);

        return (int) $m[1];
    }

    // ---- 条件1: ボタンと数が出ている ----

    public function test_ポストそれぞれにいいねのボタンと数が出る(): void
    {
        $second = $this->makePost('二つ目のポスト');
        $this->like($this->other, $second);

        $html = $this->actingAs($this->me)->get(route('posts.index'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="like-btn'));
        $this->assertSame(2, substr_count($html, 'class="like-count"'));

        $this->assertSame(0, $this->likeCount($this->article($this->me, 'いいねされるポスト')));
        $this->assertSame(1, $this->likeCount($this->article($this->me, '二つ目のポスト')));
    }

    public function test_いいねの数は押した人の人数になる(): void
    {
        $this->like($this->author);
        $this->like($this->other);

        $this->assertSame(2, $this->likeCount($this->article($this->me, 'いいねされるポスト')));
    }

    public function test_自分のポストにもいいねのボタンが出る(): void
    {
        $this->assertStringContainsString(
            'class="like-btn',
            $this->article($this->author, 'いいねされるポスト')
        );
    }

    // ---- 条件2: 押すと +1 され、押した状態の見た目になる ----

    public function test_押していないポストのボタンは押していない見た目で_いいねの送信先になる(): void
    {
        $article = $this->article($this->me, 'いいねされるポスト');

        $this->assertStringContainsString('action="'.route('posts.like', $this->post).'"', $article);
        $this->assertStringNotContainsString('liked', $article);
        $this->assertStringContainsString('aria-pressed="false"', $article);
    }

    public function test_いいねを押すと数が1つ増えて押した見た目になる(): void
    {
        $this->like($this->other);

        $this->actingAs($this->me)
            ->post(route('posts.like', $this->post))
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseHas('likes', ['user_id' => $this->me->id, 'post_id' => $this->post->id]);
        $this->assertDatabaseCount('likes', 2);

        $article = $this->article($this->me, 'いいねされるポスト');
        $this->assertSame(2, $this->likeCount($article));
        $this->assertStringContainsString('class="like-btn liked"', $article);
        $this->assertStringContainsString('aria-pressed="true"', $article);
    }

    public function test_同じポストに2回送っても数は1つしか増えない(): void
    {
        $this->actingAs($this->me)->post(route('posts.like', $this->post));
        $this->actingAs($this->me)->post(route('posts.like', $this->post));

        $this->assertDatabaseCount('likes', 1);
        $this->assertSame(1, $this->likeCount($this->article($this->me, 'いいねされるポスト')));
    }

    public function test_いいねしたポストだけが押した見た目になる(): void
    {
        $this->makePost('押さないポスト');

        $this->actingAs($this->me)->post(route('posts.like', $this->post));

        $this->assertStringContainsString('class="like-btn liked"', $this->article($this->me, 'いいねされるポスト'));
        $this->assertStringNotContainsString('liked', $this->article($this->me, '押さないポスト'));
    }

    // ---- 条件3: もう一度押すと -1 され、押していない見た目に戻る ----

    public function test_押した状態のボタンは取り消しの送信先になる(): void
    {
        $this->like($this->me);

        $article = $this->article($this->me, 'いいねされるポスト');

        $this->assertStringContainsString('action="'.route('posts.unlike', $this->post).'"', $article);
        $this->assertStringNotContainsString('action="'.route('posts.like', $this->post).'"', $article);
    }

    public function test_もう一度押すと数が1つ減って押していない見た目に戻る(): void
    {
        $this->like($this->me);
        $this->like($this->other);

        $this->actingAs($this->me)
            ->post(route('posts.unlike', $this->post))
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseMissing('likes', ['user_id' => $this->me->id, 'post_id' => $this->post->id]);
        $this->assertDatabaseHas('likes', ['user_id' => $this->other->id, 'post_id' => $this->post->id]);

        $article = $this->article($this->me, 'いいねされるポスト');
        $this->assertSame(1, $this->likeCount($article));
        $this->assertStringNotContainsString('liked', $article);
        $this->assertStringContainsString('aria-pressed="false"', $article);
    }

    public function test_押していないポストの取り消しはエラーにならず数も変わらない(): void
    {
        $this->like($this->other);

        $this->actingAs($this->me)
            ->post(route('posts.unlike', $this->post))
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('likes', 1);
    }

    public function test_押す_外す_押すを繰り返しても数が合う(): void
    {
        $this->actingAs($this->me)->post(route('posts.like', $this->post));
        $this->actingAs($this->me)->post(route('posts.unlike', $this->post));
        $this->actingAs($this->me)->post(route('posts.like', $this->post));

        $article = $this->article($this->me, 'いいねされるポスト');
        $this->assertSame(1, $this->likeCount($article));
        $this->assertStringContainsString('liked', $article);
    }

    // ---- 条件4: 別のアカウントでは押していない見た目のまま、数だけが増えている ----

    public function test_別のアカウントでは押していない見た目のまま数だけが増えている(): void
    {
        $this->actingAs($this->me)->post(route('posts.like', $this->post));

        // 別のアカウントでログインし直して見る
        $article = $this->article($this->other, 'いいねされるポスト');

        $this->assertSame(1, $this->likeCount($article));
        $this->assertStringNotContainsString('liked', $article);
        $this->assertStringContainsString('aria-pressed="false"', $article);
        $this->assertStringContainsString('action="'.route('posts.like', $this->post).'"', $article);
    }

    public function test_別のアカウントが押すと数は2になり_自分の押した状態は残る(): void
    {
        $this->actingAs($this->me)->post(route('posts.like', $this->post));
        $this->actingAs($this->other)->post(route('posts.like', $this->post));

        $this->assertSame(2, $this->likeCount($this->article($this->me, 'いいねされるポスト')));
        $this->assertStringContainsString('class="like-btn liked"', $this->article($this->me, 'いいねされるポスト'));
        $this->assertStringContainsString('class="like-btn liked"', $this->article($this->other, 'いいねされるポスト'));
    }

    public function test_自分が外しても別のアカウントのいいねは残る(): void
    {
        $this->like($this->me);
        $this->like($this->other);

        $this->actingAs($this->me)->post(route('posts.unlike', $this->post));

        $this->assertDatabaseHas('likes', ['user_id' => $this->other->id, 'post_id' => $this->post->id]);
        $this->assertStringContainsString('class="like-btn liked"', $this->article($this->other, 'いいねされるポスト'));
    }

    // ---- 守り ----

    public function test_ログインしていないといいねも取り消しもできない(): void
    {
        $this->post(route('posts.like', $this->post))->assertRedirect(route('login'));
        $this->post(route('posts.unlike', $this->post))->assertRedirect(route('login'));

        $this->assertDatabaseCount('likes', 0);
    }

    public function test_存在しないポストにはいいねできない(): void
    {
        $this->actingAs($this->me)->post('/posts/9999/like')->assertNotFound();
        $this->actingAs($this->me)->post('/posts/9999/unlike')->assertNotFound();

        $this->assertDatabaseCount('likes', 0);
    }

    public function test_ポストを消すとそのいいねも消える(): void
    {
        $this->like($this->me);

        $this->actingAs($this->author)->delete(route('posts.destroy', $this->post));

        $this->assertDatabaseCount('likes', 0);
    }

    public function test_ユーザーを消すとそのユーザーのいいねも消える(): void
    {
        $this->like($this->me);

        $this->me->delete();

        $this->assertDatabaseCount('likes', 0);
    }

    // ---- 今までどおり（回帰） ----

    public function test_いいねを付け外ししてもポストは編集済みにならない(): void
    {
        $past = now()->subHour();
        $this->post->forceFill(['created_at' => $past, 'updated_at' => $past])->save();

        $this->actingAs($this->me)->post(route('posts.like', $this->post));
        $this->actingAs($this->me)->post(route('posts.unlike', $this->post));
        $this->actingAs($this->me)->post(route('posts.like', $this->post));

        $this->assertFalse($this->post->fresh()->isEdited());

        $this->actingAs($this->me)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertDontSee('編集済み');
    }

    public function test_いいねがあっても編集済みの目印は今までどおり出る(): void
    {
        $past = now()->subHour();
        $this->post->forceFill(['created_at' => $past, 'updated_at' => $past])->save();
        $this->like($this->me);

        $this->actingAs($this->author)->put(route('posts.update', $this->post), [
            'title' => '直したタイトル',
            'category_id' => $this->post->category_id,
            'content' => '直した本文',
        ]);

        $article = $this->article($this->me, '直したタイトル');
        $this->assertStringContainsString('編集済み', $article);
        $this->assertSame(1, $this->likeCount($article));
    }

    public function test_いいねがあっても編集と削除は今までどおり本人にだけ出る(): void
    {
        $this->like($this->me);

        $this->actingAs($this->author)
            ->get(route('posts.index'))
            ->assertSee(route('posts.edit', $this->post), false)
            ->assertSee('value="DELETE"', false);

        $this->actingAs($this->me)
            ->get(route('posts.index'))
            ->assertDontSee(route('posts.edit', $this->post), false)
            ->assertDontSee('value="DELETE"', false);
    }

    public function test_いいねの付いたポストも編集と削除ができる(): void
    {
        $this->like($this->me);

        $this->actingAs($this->author)
            ->put(route('posts.update', $this->post), [
                'title' => '直したタイトル',
                'category_id' => $this->post->category_id,
                'content' => '直した本文',
            ])
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseHas('posts', ['id' => $this->post->id, 'title' => '直したタイトル']);

        $this->actingAs($this->author)
            ->delete(route('posts.destroy', $this->post))
            ->assertRedirect(route('posts.index'));

        $this->assertModelMissing($this->post);
    }

    public function test_いいねがあってもリプライは今までどおり付けられる(): void
    {
        $this->like($this->other);

        $this->actingAs($this->me)
            ->post(route('replies.store', $this->post), ['content' => 'リプライです'])
            ->assertRedirect(route('posts.show', $this->post));

        $this->assertDatabaseHas('replies', ['post_id' => $this->post->id, 'content' => 'リプライです']);
    }

    public function test_いいねがあってもポストの作成は今までどおりできる(): void
    {
        $this->actingAs($this->me)
            ->post(route('posts.store'), [
                'title' => '新しいポスト',
                'category_id' => $this->post->category_id,
                'content' => '新しい本文',
            ])
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseHas('posts', ['title' => '新しいポスト', 'user_id' => $this->me->id]);
        $this->assertSame(0, $this->likeCount($this->article($this->me, '新しいポスト')));
    }
}
