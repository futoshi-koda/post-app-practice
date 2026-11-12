<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Reply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplyTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $other;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['name' => 'ポスト作者']);
        $this->other = User::factory()->create(['name' => 'ほかの人']);

        $category = Category::create(['name' => 'お知らせ']);
        $this->post = Post::create([
            'user_id' => $this->author->id,
            'category_id' => $category->id,
            'title' => '元のタイトル',
            'content' => '元の本文',
        ]);
    }

    private function reply(User $user, string $content, ?Post $post = null, ?string $createdAt = null): Reply
    {
        $reply = Reply::create([
            'user_id' => $user->id,
            'post_id' => ($post ?? $this->post)->id,
            'content' => $content,
        ]);

        if ($createdAt) {
            $reply->forceFill(['created_at' => $createdAt])->save();
        }

        return $reply;
    }

    private function send(string $content, ?User $as = null, ?Post $post = null)
    {
        $post ??= $this->post;

        return $this->actingAs($as ?? $this->other)
            ->from(route('posts.show', $post))
            ->post(route('replies.store', $post), ['content' => $content]);
    }

    // ---- 表示 ----

    public function test_詳細画面にポストとリプライが古い順で出る(): void
    {
        $this->reply($this->other, '二番目のリプライ', createdAt: '2026-11-10 10:00:00');
        $this->reply($this->author, '一番目のリプライ', createdAt: '2026-11-10 09:00:00');

        $this->actingAs($this->other)
            ->get(route('posts.show', $this->post))
            ->assertOk()
            ->assertSee('元のタイトル')
            ->assertSee('元の本文')
            ->assertSeeInOrder(['一番目のリプライ', '二番目のリプライ']);
    }

    public function test_別のポストのリプライは出ない(): void
    {
        $another = Post::create([
            'user_id' => $this->author->id,
            'category_id' => $this->post->category_id,
            'title' => '別のタイトル',
            'content' => '別の本文',
        ]);
        $this->reply($this->other, '別ポストへのリプライ', $another);

        $this->actingAs($this->other)
            ->get(route('posts.show', $this->post))
            ->assertOk()
            ->assertDontSee('別ポストへのリプライ');
    }

    public function test_リプライがないときは案内が出る(): void
    {
        $this->actingAs($this->other)
            ->get(route('posts.show', $this->post))
            ->assertSee('まだリプライがありません。');
    }

    public function test_自分のリプライにだけ削除ボタンが出る(): void
    {
        $mine = $this->reply($this->other, '自分のリプライ');
        $theirs = $this->reply($this->author, '他人のリプライ');

        $this->actingAs($this->other)
            ->get(route('posts.show', $this->post))
            ->assertSee(route('replies.destroy', $mine), false)
            ->assertDontSee(route('replies.destroy', $theirs), false);
    }

    public function test_ポストの作者でも他人のリプライには削除ボタンが出ない(): void
    {
        $reply = $this->reply($this->other, '他人のリプライ');

        $this->actingAs($this->author)
            ->get(route('posts.show', $this->post))
            ->assertDontSee(route('replies.destroy', $reply), false);
    }

    public function test_存在しないポストは404(): void
    {
        $this->actingAs($this->other)->get('/posts/9999')->assertNotFound();
    }

    public function test_タイムラインの本文と日時が詳細画面へのリンクになっている(): void
    {
        $html = $this->actingAs($this->other)
            ->get(route('posts.index'))
            ->assertOk()
            ->getContent();

        $url = route('posts.show', $this->post);

        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote($url, '#').'" class="time">#',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote($url, '#').'" class="post-text-link"><p class="post-text">元の本文</p></a>#',
            $html
        );
    }

    public function test_タイムラインの編集と削除ボタンの出方は変わらない(): void
    {
        $this->actingAs($this->author)
            ->get(route('posts.index'))
            ->assertSee(route('posts.edit', $this->post), false)
            ->assertSee('value="DELETE"', false); // 削除ボタンのフォーム（URL は詳細画面と同じなので _method で見分ける）

        $this->actingAs($this->other)
            ->get(route('posts.index'))
            ->assertDontSee(route('posts.edit', $this->post), false)
            ->assertDontSee('value="DELETE"', false);
    }

    // ---- 送信 ----

    public function test_本文を入れて送るとリプライが増え詳細画面へ戻る(): void
    {
        $this->send('はじめまして')->assertRedirect(route('posts.show', $this->post));

        $this->assertDatabaseHas('replies', [
            'post_id' => $this->post->id,
            'user_id' => $this->other->id,
            'content' => 'はじめまして',
        ]);

        $this->actingAs($this->other)
            ->get(route('posts.show', $this->post))
            ->assertSee('はじめまして');
    }

    public function test_本文が空だとエラーになりリプライは増えない(): void
    {
        $this->send('')
            ->assertRedirect(route('posts.show', $this->post))
            ->assertSessionHasErrors(['content' => '本文を入力してください。']);

        $this->assertDatabaseCount('replies', 0);
    }

    public function test_空白だけの本文もエラーになる(): void
    {
        $this->send("   \n  ")
            ->assertSessionHasErrors(['content' => '本文を入力してください。']);

        $this->assertDatabaseCount('replies', 0);
    }

    public function test_エラー画面にメッセージが表示される(): void
    {
        $this->send('');

        $this->actingAs($this->other)
            ->get(route('posts.show', $this->post))
            ->assertSee('本文を入力してください。');
    }

    public function test_140字ちょうどは通り141字はエラーになる(): void
    {
        $this->send(str_repeat('あ', 140))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('replies', 1);

        $this->send(str_repeat('あ', 141))
            ->assertSessionHasErrors(['content' => '本文は140字以内で入力してください。']);
        $this->assertDatabaseCount('replies', 1);
    }

    public function test_ブラウザ形式の改行_crl_f_は1字として数える(): void
    {
        // 改行 1 つ + 139 字 = 140 字。CRLF のまま数えると 141 字になってしまう
        $content = str_repeat('あ', 70)."\r\n".str_repeat('い', 69);

        $this->send($content)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('replies', [
            'content' => str_repeat('あ', 70)."\n".str_repeat('い', 69),
        ]);

        $this->send(str_repeat('あ', 70)."\r\n".str_repeat('い', 70))
            ->assertSessionHasErrors('content');
        $this->assertDatabaseCount('replies', 1);
    }

    public function test_エラーのとき入力した本文が入力欄に残る(): void
    {
        $content = str_repeat('あ', 141);

        $this->followingRedirects()
            ->actingAs($this->other)
            ->from(route('posts.show', $this->post))
            ->post(route('replies.store', $this->post), ['content' => $content])
            ->assertSee($content);
    }

    public function test_存在しないポストへは送れない(): void
    {
        $this->actingAs($this->other)
            ->post('/posts/9999/replies', ['content' => 'こんにちは'])
            ->assertNotFound();

        $this->assertDatabaseCount('replies', 0);
    }

    // ---- 削除 ----

    public function test_自分のリプライは消せて詳細画面へ戻る(): void
    {
        $reply = $this->reply($this->other, '自分のリプライ');

        $this->actingAs($this->other)
            ->delete(route('replies.destroy', $reply))
            ->assertRedirect(route('posts.show', $this->post));

        $this->assertModelMissing($reply);
    }

    public function test_他人のリプライは消せない(): void
    {
        $reply = $this->reply($this->author, '他人のリプライ');

        $this->actingAs($this->other)
            ->delete(route('replies.destroy', $reply))
            ->assertForbidden();

        $this->assertModelExists($reply);
    }

    public function test_ポストの作者でも他人のリプライは消せない(): void
    {
        $reply = $this->reply($this->other, '他人のリプライ');

        $this->actingAs($this->author)
            ->delete(route('replies.destroy', $reply))
            ->assertForbidden();

        $this->assertModelExists($reply);
    }

    // ---- ログイン・連動 ----

    public function test_ログインしていないと表示_送信_削除ができない(): void
    {
        $reply = $this->reply($this->other, 'リプライ');

        $this->get(route('posts.show', $this->post))->assertRedirect(route('login'));
        $this->post(route('replies.store', $this->post), ['content' => 'こんにちは'])->assertRedirect(route('login'));
        $this->delete(route('replies.destroy', $reply))->assertRedirect(route('login'));

        $this->assertDatabaseCount('replies', 1);
    }

    public function test_親のポストを消すとリプライも消える(): void
    {
        $reply = $this->reply($this->other, 'リプライ');

        $this->actingAs($this->author)
            ->delete(route('posts.destroy', $this->post))
            ->assertRedirect(route('posts.index'));

        $this->assertModelMissing($reply);
    }

    public function test_ユーザーを消すとそのユーザーのリプライも消える(): void
    {
        $reply = $this->reply($this->other, 'リプライ');

        $this->other->delete();

        $this->assertModelMissing($reply);
    }
}
