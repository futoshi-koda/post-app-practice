<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Reply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ポストの新規投稿（docs/post-create.md）。
 */
class PostCreateTest extends TestCase
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

        Category::create(['name' => 'お知らせ']);
        $this->category = Category::create(['name' => '技術メモ']);
        Category::create(['name' => '雑記']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => '新しいタイトル',
            'category_id' => $this->category->id,
            'content' => '新しい本文',
        ], $overrides);
    }

    /**
     * 作成画面から送ったときの動き（エラー時は作成画面へ戻る）で送信する。
     */
    private function send(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->from(route('posts.create'))
            ->post(route('posts.store'), $this->payload($overrides));
    }

    private function existingPost(User $user, string $title, $createdAt): Post
    {
        $post = Post::create([
            'user_id' => $user->id,
            'category_id' => $this->category->id,
            'title' => $title,
            'content' => '既存の本文',
        ]);

        // created_at は $fillable にないので、forceFill で並びの検証用に固定する
        $post->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $post;
    }

    // ---- 表示 ----

    public function test_タイムラインに固定ヘッダー内の_ポストする_ボタンが出る(): void
    {
        $response = $this->actingAs($this->user)->get(route('posts.index'))->assertOk();

        // 固定ヘッダー（.chrome は position: sticky）の中に、作成画面へのリンクがある
        $this->assertMatchesRegularExpression(
            '#<header class="chrome">.*<a href="'.preg_quote(route('posts.create'), '#').'"[^>]*>ポストする</a>.*</header>#s',
            $response->getContent()
        );
    }

    public function test_作成画面が空の入力欄とトピックの選択肢つきで開ける(): void
    {
        $this->actingAs($this->user)
            ->get('/posts/create') // {post} に取られて 404 にならない
            ->assertOk()
            ->assertSee('ポストを書く')
            ->assertSee('action="'.route('posts.store').'"', false)
            ->assertDontSee('name="_method"', false)
            ->assertSee('選択してください')
            ->assertSee('お知らせ')
            ->assertSee('技術メモ')
            ->assertSee('雑記')
            ->assertSee('更新')
            ->assertSee('残り 140 字');
    }

    public function test_編集画面は今までどおり(): void
    {
        $post = $this->existingPost($this->user, '元のタイトル', now());

        $this->actingAs($this->user)
            ->get(route('posts.edit', $post))
            ->assertOk()
            ->assertSee('ポストを編集')
            ->assertSee('value="元のタイトル"', false)
            ->assertSee('action="'.route('posts.update', $post).'"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertDontSee('選択してください')
            ->assertSee('残り 135 字'); // 「既存の本文」は 5 字
    }

    public function test_編集画面から更新できる(): void
    {
        $post = $this->existingPost($this->user, '元のタイトル', now());

        $this->actingAs($this->user)
            ->put(route('posts.update', $post), $this->payload())
            ->assertRedirect(route('posts.index'));

        $this->assertSame('新しいタイトル', $post->fresh()->title);
        $this->assertDatabaseCount('posts', 1);
    }

    // ---- 送信（正常） ----

    public function test_タイトル_トピック_本文を入れて送るとポストが増えタイムラインへ移る(): void
    {
        $this->send()->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseHas('posts', [
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'title' => '新しいタイトル',
            'content' => '新しい本文',
        ]);
    }

    public function test_送ったポストがタイムラインの先頭に出て_本人には編集と削除が出る(): void
    {
        $this->existingPost($this->other, '既存のタイトル', now()->subHour());

        $this->send()->assertRedirect(route('posts.index'));

        $post = Post::where('title', '新しいタイトル')->firstOrFail();

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertSeeInOrder(['新しいタイトル', '既存のタイトル'])
            ->assertSee('投稿する人')
            ->assertSee(route('posts.edit', $post))
            ->assertSee('action="'.route('posts.destroy', $post).'"', false)
            // 他人の既存ポストには編集・削除が出ない（今までどおり）
            ->assertDontSee(route('posts.edit', Post::where('title', '既存のタイトル')->first()));
    }

    public function test_別のユーザーがタイムラインを開いても_編集と削除は出ない(): void
    {
        $this->send();
        $post = Post::firstOrFail();

        $this->actingAs($this->other)
            ->get(route('posts.index'))
            ->assertSee('新しいタイトル')
            ->assertDontSee(route('posts.edit', $post))
            ->assertDontSee('action="'.route('posts.destroy', $post).'"', false);
    }

    public function test_140字ちょうどの本文は通る(): void
    {
        $content = str_repeat('あ', 140);

        $this->send(['content' => $content])->assertRedirect(route('posts.index'));

        $this->assertSame($content, Post::firstOrFail()->content);
    }

    public function test_ブラウザ形式の改行_crlf_は1字として数える(): void
    {
        // 改行 1 つ + 139 字 = 140 字。CRLF のまま数えると 141 字になってしまう
        $content = str_repeat('あ', 70)."\r\n".str_repeat('い', 69);

        $this->send(['content' => $content])->assertRedirect(route('posts.index'));

        $this->assertSame(
            str_repeat('あ', 70)."\n".str_repeat('い', 69),
            Post::firstOrFail()->content
        );
    }

    public function test_改行を含めて141字ならエラーになる(): void
    {
        $content = str_repeat('あ', 70)."\r\n".str_repeat('い', 70);

        $this->send(['content' => $content])
            ->assertSessionHasErrors(['content' => '本文は140字以内で入力してください。']);

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_タイトルは255字ちょうどなら通る(): void
    {
        $this->send(['title' => str_repeat('あ', 255)])->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('posts', 1);
    }

    // ---- 送信（エラー） ----

    public function test_本文が空だとエラーが出てポストは増えない(): void
    {
        $this->send(['content' => ''])
            ->assertRedirect(route('posts.create'))
            ->assertSessionHasErrors(['content' => '本文を入力してください。']);

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_空白だけの本文も空として扱われる(): void
    {
        $this->send(['content' => "   \n  "])
            ->assertSessionHasErrors(['content' => '本文を入力してください。']);

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_141字の本文はエラーになりポストは増えない(): void
    {
        $this->send(['content' => str_repeat('あ', 141)])
            ->assertRedirect(route('posts.create'))
            ->assertSessionHasErrors(['content' => '本文は140字以内で入力してください。']);

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_タイトルが空_または256字だとエラーになる(): void
    {
        $this->send(['title' => ''])->assertSessionHasErrors('title');
        $this->send(['title' => str_repeat('あ', 256)])->assertSessionHasErrors('title');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_トピックが未選択_または存在しないとエラーになる(): void
    {
        $this->send(['category_id' => ''])->assertSessionHasErrors('category_id');
        $this->send(['category_id' => 9999])->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_エラーで戻ったとき入力したタイトル_トピック_本文が残る(): void
    {
        $this->actingAs($this->user)
            ->from(route('posts.create'))
            ->post(route('posts.store'), $this->payload(['title' => '残るタイトル', 'content' => '']))
            ->assertRedirect(route('posts.create'));

        $this->get(route('posts.create'))
            ->assertOk()
            ->assertSee('value="残るタイトル"', false)
            ->assertSee('value="'.$this->category->id.'" selected', false)
            ->assertSee('本文を入力してください。');
    }

    // ---- 投稿者 ----

    public function test_他人のuser_idを混ぜて送っても_ログイン中のユーザーのポストになる(): void
    {
        $this->send(['user_id' => $this->other->id])->assertRedirect(route('posts.index'));

        $this->assertSame($this->user->id, Post::firstOrFail()->user_id);
    }

    public function test_投稿したポストにリプライを付けられる(): void
    {
        $this->send();
        $post = Post::firstOrFail();

        $this->actingAs($this->other)
            ->post(route('replies.store', $post), ['content' => 'リプライです'])
            ->assertRedirect(route('posts.show', $post));

        $this->assertSame(1, Reply::where('post_id', $post->id)->count());
    }

    // ---- ログイン ----

    public function test_未ログインでは作成画面を開けずログイン画面へ移る(): void
    {
        $this->get(route('posts.create'))->assertRedirect(route('login'));
    }

    public function test_未ログインでは送信できずポストも増えない(): void
    {
        $this->post(route('posts.store'), $this->payload())->assertRedirect(route('login'));

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_未ログインではタイムラインにたどり着けずボタンも見えない(): void
    {
        $this->get(route('posts.index'))->assertRedirect(route('login'));
    }
}
