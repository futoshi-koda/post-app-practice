<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ポストのタグ（付与・付け替え・タイムラインでの表示と絞り込み）。設計書は docs/post-tag.md。
 */
class PostTagTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $other;

    private Category $category;

    private Tag $learn;

    private Tag $stumble;

    private Tag $question;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => '投稿する人']);
        $this->other = User::factory()->create(['name' => 'ほかの人']);

        $this->category = Category::create(['name' => '技術メモ']);

        // tags.id の昇順が画面の並び順になる
        $this->learn = Tag::create(['name' => '学び']);
        $this->stumble = Tag::create(['name' => 'つまずき']);
        $this->question = Tag::create(['name' => '質問']);
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
     * 作成日時を過去に固定したポスト。保存直後と更新が同じ秒に収まって
     * 「編集済み」にならない、というテストの偶然を避ける。
     *
     * @param  array<int, Tag>  $tags
     */
    private function existingPost(User $user, string $title, array $tags = [], $createdAt = null): Post
    {
        $post = Post::create([
            'user_id' => $user->id,
            'category_id' => $this->category->id,
            'title' => $title,
            'content' => '既存の本文',
        ]);

        $at = $createdAt ?? now()->subHour();
        $post->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        $post->tags()->sync(array_map(fn (Tag $tag) => $tag->id, $tags));

        return $post;
    }

    /**
     * タイムラインの HTML から、タイトルを含むポスト 1 件分（<article>）だけを取り出す。
     * 上部の絞り込みの一覧にも「#学び」が出るので、ポストに出ているタグだけを調べるために使う。
     */
    private function articleOf(string $html, string $title): string
    {
        foreach (explode('<article class="post">', $html) as $chunk) {
            if (str_contains($chunk, $title)) {
                return explode('</article>', $chunk)[0];
            }
        }

        $this->fail("タイムラインに「{$title}」のポストがない");
    }

    /**
     * @return array<int, int> ポストに付いているタグの id（昇順）
     */
    private function tagIdsOf(Post $post): array
    {
        return $post->tags()->orderBy('tags.id')->pluck('tags.id')->all();
    }

    // ---- 作成 ----

    public function test_作成画面にタグのチェックボックスが出る(): void
    {
        $this->actingAs($this->user)
            ->get(route('posts.create'))
            ->assertOk()
            ->assertSee('name="tag_ids[]" value="'.$this->learn->id.'"', false)
            ->assertSee('name="tag_ids[]" value="'.$this->stumble->id.'"', false)
            ->assertSee('name="tag_ids[]" value="'.$this->question->id.'"', false)
            ->assertSeeInOrder(['#学び', '#つまずき', '#質問'])
            // 何も選ばれていない状態で開く
            ->assertDontSee('checked');
    }

    public function test_タグを複数選んで保存するとそのポストにタグが付く(): void
    {
        $this->actingAs($this->user)
            ->post(route('posts.store'), $this->payload(['tag_ids' => [$this->learn->id, $this->question->id]]))
            ->assertRedirect(route('posts.index'));

        $post = Post::firstOrFail();
        $this->assertSame([$this->learn->id, $this->question->id], $this->tagIdsOf($post));
        $this->assertDatabaseCount('posts', 1);
    }

    public function test_タグを選ばずに保存するとタグなしのポストができる(): void
    {
        $this->actingAs($this->user)
            ->post(route('posts.store'), $this->payload())
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseCount('posts', 1);
        $this->assertSame([], $this->tagIdsOf(Post::firstOrFail()));
        $this->assertDatabaseCount('post_tag', 0);
    }

    public function test_同じタグを重ねて送っても1回だけ付く(): void
    {
        $this->actingAs($this->user)
            ->post(route('posts.store'), $this->payload(['tag_ids' => [$this->learn->id, $this->learn->id]]))
            ->assertRedirect(route('posts.index'));

        $this->assertSame([$this->learn->id], $this->tagIdsOf(Post::firstOrFail()));
    }

    // ---- 編集・付け替え ----

    public function test_編集画面で今付いているタグにだけチェックが入っている(): void
    {
        $post = $this->existingPost($this->user, '元のタイトル', [$this->learn, $this->question]);

        $html = $this->actingAs($this->user)
            ->get(route('posts.edit', $post))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/value="'.$this->learn->id.'"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/value="'.$this->question->id.'"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="'.$this->stumble->id.'"\s+checked/', $html);
    }

    public function test_タグを付け替えて保存できる_外す_足す(): void
    {
        $post = $this->existingPost($this->user, '元のタイトル', [$this->learn, $this->question]);

        // 「学び」を外して「つまずき」を足す（「質問」はそのまま）
        $this->actingAs($this->user)
            ->put(route('posts.update', $post), $this->payload([
                'title' => '元のタイトル',
                'tag_ids' => [$this->stumble->id, $this->question->id],
            ]))
            ->assertRedirect(route('posts.index'));

        $this->assertSame([$this->stumble->id, $this->question->id], $this->tagIdsOf($post));
    }

    public function test_チェックを全部外して保存するとタグなしになる(): void
    {
        $post = $this->existingPost($this->user, '元のタイトル', [$this->learn, $this->question]);

        $this->actingAs($this->user)
            ->put(route('posts.update', $post), $this->payload(['title' => '元のタイトル']))
            ->assertRedirect(route('posts.index'));

        $this->assertSame([], $this->tagIdsOf($post));
        // タグ本体は残る
        $this->assertDatabaseCount('tags', 3);
    }

    public function test_タグだけ付け替えても編集済みにならない(): void
    {
        $post = $this->existingPost($this->user, '元のタイトル', [$this->learn]);
        $before = $post->fresh()->updated_at;

        $this->actingAs($this->user)
            ->put(route('posts.update', $post), [
                'title' => '元のタイトル',
                'category_id' => $this->category->id,
                'content' => '既存の本文',
                'tag_ids' => [$this->stumble->id],
            ])
            ->assertRedirect(route('posts.index'));

        $this->assertSame([$this->stumble->id], $this->tagIdsOf($post));
        $this->assertTrue($post->fresh()->updated_at->equalTo($before));

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertSee('元のタイトル')
            ->assertDontSee('編集済み');
    }

    public function test_タイトルを変えたときは今までどおり編集済みになる(): void
    {
        $post = $this->existingPost($this->user, '元のタイトル', [$this->learn]);

        $this->actingAs($this->user)
            ->put(route('posts.update', $post), $this->payload(['tag_ids' => [$this->learn->id]]))
            ->assertRedirect(route('posts.index'));

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertSee('新しいタイトル')
            ->assertSee('編集済み');
    }

    // ---- 入力チェック ----

    public function test_存在しないタグのidは日本語のメッセージで弾かれ_d_bは変わらない(): void
    {
        $this->actingAs($this->user)
            ->from(route('posts.create'))
            ->post(route('posts.store'), $this->payload(['tag_ids' => [9999]]))
            ->assertRedirect(route('posts.create'))
            ->assertSessionHasErrors(['tag_ids.0' => '選択したタグは存在しません。']);

        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('post_tag', 0);
    }

    public function test_編集でも存在しないタグのidは弾かれ_タグもほかの項目も変わらない(): void
    {
        $post = $this->existingPost($this->user, '元のタイトル', [$this->learn]);

        $this->actingAs($this->user)
            ->from(route('posts.edit', $post))
            ->put(route('posts.update', $post), $this->payload(['tag_ids' => [$this->stumble->id, 9999]]))
            ->assertRedirect(route('posts.edit', $post))
            ->assertSessionHasErrors(['tag_ids.1' => '選択したタグは存在しません。']);

        $this->assertSame([$this->learn->id], $this->tagIdsOf($post));
        $this->assertSame('元のタイトル', $post->fresh()->title);
    }

    public function test_タグのidが数字でないときも同じメッセージで弾かれる(): void
    {
        $this->actingAs($this->user)
            ->post(route('posts.store'), $this->payload(['tag_ids' => ['abc']]))
            ->assertSessionHasErrors(['tag_ids.0' => '選択したタグは存在しません。']);

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_tag_idsが配列でないときは日本語のメッセージで弾かれる(): void
    {
        $this->actingAs($this->user)
            ->post(route('posts.store'), $this->payload(['tag_ids' => (string) $this->learn->id]))
            ->assertSessionHasErrors(['tag_ids' => 'タグの選択が正しくありません。']);

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_エラーで戻ったとき選んでいたタグのチェックが残る(): void
    {
        $this->actingAs($this->user)
            ->from(route('posts.create'))
            ->post(route('posts.store'), $this->payload(['content' => '', 'tag_ids' => [$this->stumble->id]]))
            ->assertRedirect(route('posts.create'));

        $html = $this->get(route('posts.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/value="'.$this->stumble->id.'"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="'.$this->learn->id.'"\s+checked/', $html);
    }

    public function test_編集でエラーのまま戻ったとき_全部外した状態も残る(): void
    {
        $post = $this->existingPost($this->user, '元のタイトル', [$this->learn]);

        // 本文を空にし、タグは全部外して送る → 編集画面に戻る
        $this->actingAs($this->user)
            ->from(route('posts.edit', $post))
            ->put(route('posts.update', $post), $this->payload(['content' => '']))
            ->assertRedirect(route('posts.edit', $post));

        $html = $this->get(route('posts.edit', $post))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/value="'.$this->learn->id.'"\s+checked/', $html);
    }

    // ---- 認可 ----

    public function test_他人のポストの編集画面は403(): void
    {
        $post = $this->existingPost($this->other, '他人のタイトル', [$this->learn]);

        $this->actingAs($this->user)
            ->get(route('posts.edit', $post))
            ->assertForbidden();
    }

    public function test_他人のポストのタグはputで変えようとしても403で_d_bは変わらない(): void
    {
        $post = $this->existingPost($this->other, '他人のタイトル', [$this->learn]);

        $this->actingAs($this->user)
            ->put(route('posts.update', $post), $this->payload(['tag_ids' => [$this->stumble->id]]))
            ->assertForbidden();

        $this->assertSame([$this->learn->id], $this->tagIdsOf($post));
        $this->assertSame('他人のタイトル', $post->fresh()->title);
    }

    // ---- タイムラインの表示 ----

    public function test_タイムラインの各ポストに付いているタグが_名前で出る(): void
    {
        $this->existingPost($this->user, 'タグありのポスト', [$this->learn, $this->question]);

        $html = $this->actingAs($this->user)->get(route('posts.index'))->assertOk()->getContent();
        $article = $this->articleOf($html, 'タグありのポスト');

        $this->assertStringContainsString('#学び', $article);
        $this->assertStringContainsString('#質問', $article);
        // 付いていないタグは、そのポストには出ない
        $this->assertStringNotContainsString('#つまずき', $article);
        // 付いているタグは、クリックで絞り込めるリンクになっている
        $this->assertStringContainsString(route('posts.index', ['tag' => $this->learn->id]), $article);
    }

    public function test_タグなしのポストにはタグの欄が出ない(): void
    {
        $this->existingPost($this->user, 'タグなしのポスト');

        $html = $this->actingAs($this->user)->get(route('posts.index'))->assertOk()->getContent();
        $article = $this->articleOf($html, 'タグなしのポスト');

        // アバターの色（#6D7BE0 など）にも # があるので、タグの表示（# + 名前）だけを調べる
        foreach (['学び', 'つまずき', '質問'] as $name) {
            $this->assertStringNotContainsString('#'.$name, $article);
        }
        $this->assertStringNotContainsString('post-tags', $article);
    }

    public function test_タグを複数持つポストのタグは_タグのid順に並ぶ(): void
    {
        $this->existingPost($this->user, 'タグありのポスト', [$this->question, $this->learn]);

        $html = $this->actingAs($this->user)->get(route('posts.index'))->getContent();

        $this->assertSame(
            1,
            preg_match('/#学び.*#質問/s', $this->articleOf($html, 'タグありのポスト'))
        );
    }

    // ---- 絞り込み ----

    public function test_絞り込みの一覧にすべてと用意されたタグが出る(): void
    {
        $html = $this->actingAs($this->user)->get(route('posts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<nav class="tag-filter"', $html);
        $this->assertMatchesRegularExpression('/tag-chip active"[^>]*>すべて</', $html);
        $this->assertStringContainsString('>#学び</a>', $html);
        $this->assertStringContainsString('>#つまずき</a>', $html);
        $this->assertStringContainsString('>#質問</a>', $html);
        $this->assertStringContainsString(route('posts.index', ['tag' => $this->learn->id]), $html);
    }

    public function test_タグで絞ると_そのタグのポストだけが出て_付いていないポストは出ない(): void
    {
        $this->existingPost($this->user, '学びのポスト', [$this->learn]);
        $this->existingPost($this->user, '学びと質問のポスト', [$this->learn, $this->question]);
        $this->existingPost($this->user, 'つまずきのポスト', [$this->stumble]);
        $this->existingPost($this->user, 'タグなしのポスト');

        $this->actingAs($this->user)
            ->get(route('posts.index', ['tag' => $this->learn->id]))
            ->assertOk()
            ->assertSee('学びのポスト')
            ->assertSee('学びと質問のポスト')
            ->assertDontSee('つまずきのポスト')
            ->assertDontSee('タグなしのポスト')
            ->assertDontSee('該当するポストがありません。');
    }

    public function test_絞り込んだあとも_並びは新しい順で_各ポストのタグは全部出る(): void
    {
        $this->existingPost($this->user, '古い学び', [$this->learn], now()->subDays(2));
        $this->existingPost($this->user, '新しい学び', [$this->learn, $this->question], now()->subDay());

        $html = $this->actingAs($this->user)
            ->get(route('posts.index', ['tag' => $this->learn->id]))
            ->assertOk()
            ->assertSeeInOrder(['新しい学び', '古い学び'])
            ->getContent();

        // 絞り込んだタグ（学び）以外のタグも、そのポストには出る
        $this->assertStringContainsString('#質問', $this->articleOf($html, '新しい学び'));
    }

    public function test_絞り込み中は選んでいるタグが見た目で分かる(): void
    {
        $html = $this->actingAs($this->user)
            ->get(route('posts.index', ['tag' => $this->stumble->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/tag-chip active"[^>]*>#つまずき</', $html);
        // 「すべて」は選ばれていない状態になる
        $this->assertDoesNotMatchRegularExpression('/tag-chip active"[^>]*>すべて</', $html);
        $this->assertDoesNotMatchRegularExpression('/tag-chip active"[^>]*>#学び</', $html);
    }

    public function test_絞り込み結果が0件のときは専用の文言が出る(): void
    {
        $this->existingPost($this->user, '学びのポスト', [$this->learn]);

        $this->actingAs($this->user)
            ->get(route('posts.index', ['tag' => $this->stumble->id]))
            ->assertOk()
            ->assertSee('該当するポストがありません。')
            ->assertDontSee('まだポストがありません。')
            ->assertDontSee('学びのポスト');
    }

    public function test_ポストが1件もないときは今までどおりの文言が出る(): void
    {
        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertSee('まだポストがありません。')
            ->assertDontSee('該当するポストがありません。');
    }

    public function test_存在しないタグで絞ると404(): void
    {
        $this->actingAs($this->user)
            ->get(route('posts.index', ['tag' => 9999]))
            ->assertNotFound();

        $this->actingAs($this->user)
            ->get(route('posts.index', ['tag' => 'abc']))
            ->assertNotFound();
    }

    public function test_tagが空のときは絞り込みなしと同じ(): void
    {
        $this->existingPost($this->user, '学びのポスト', [$this->learn]);
        $this->existingPost($this->user, 'タグなしのポスト');

        $this->actingAs($this->user)
            ->get('/posts?tag=')
            ->assertOk()
            ->assertSee('学びのポスト')
            ->assertSee('タグなしのポスト');
    }

    public function test_未ログインでは絞り込みもできずログイン画面へ移る(): void
    {
        $this->get(route('posts.index', ['tag' => $this->learn->id]))->assertRedirect(route('login'));
    }

    // ---- 連動 ----

    public function test_ポストを消すと中間テーブルの行も消え_タグ本体は残る(): void
    {
        $post = $this->existingPost($this->user, '消すポスト', [$this->learn, $this->question]);
        $kept = $this->existingPost($this->user, '残るポスト', [$this->learn]);

        $this->actingAs($this->user)
            ->delete(route('posts.destroy', $post))
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseMissing('post_tag', ['post_id' => $post->id]);
        $this->assertDatabaseHas('post_tag', ['post_id' => $kept->id, 'tag_id' => $this->learn->id]);
        $this->assertDatabaseCount('tags', 3);
    }

    public function test_タグを消すと中間テーブルの行も消え_ポスト本体は残る(): void
    {
        $post = $this->existingPost($this->user, '残るポスト', [$this->learn, $this->question]);

        $this->learn->delete();

        $this->assertDatabaseMissing('post_tag', ['tag_id' => $this->learn->id]);
        $this->assertSame([$this->question->id], $this->tagIdsOf($post));
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    public function test_同じタグを同じポストに2回付けることはできない(): void
    {
        $post = $this->existingPost($this->user, 'ポスト', [$this->learn]);

        $this->expectException(QueryException::class);

        $post->tags()->attach($this->learn->id);
    }

    public function test_タグ名は一意(): void
    {
        $this->expectException(QueryException::class);

        Tag::create(['name' => '学び']);
    }

    // ---- seeder ----

    public function test_seederでタグ5件が入り_半分ほどのポストに付き_5つのタグすべてが使われる(): void
    {
        // setUp で作ったテスト用のタグ・トピック・ユーザーを消してから、本物の seeder を流す
        Tag::query()->delete();
        Category::query()->delete();
        User::query()->delete();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            ['学び', 'つまずき', '質問', '発見', 'おすすめ'],
            Tag::orderBy('id')->pluck('name')->all()
        );

        $total = Post::count();
        $tagged = Post::has('tags')->count();
        $this->assertGreaterThan(0, $total);
        $this->assertGreaterThan(0, $tagged);
        $this->assertLessThan($total, $tagged); // タグなしのポストも残る
        $this->assertEqualsWithDelta($total / 2, $tagged, 4); // 半分程度

        foreach (Tag::all() as $tag) {
            $this->assertGreaterThanOrEqual(1, $tag->posts()->count(), "#{$tag->name} がどのポストにも付いていない");
        }
    }

    // ---- 回帰（今までどおり） ----

    public function test_タグなしで今までどおり作成_編集_削除できる(): void
    {
        $this->actingAs($this->user)
            ->post(route('posts.store'), $this->payload())
            ->assertRedirect(route('posts.index'));

        $post = Post::firstOrFail();

        $this->actingAs($this->user)
            ->put(route('posts.update', $post), $this->payload(['title' => '直したタイトル']))
            ->assertRedirect(route('posts.index'));
        $this->assertSame('直したタイトル', $post->fresh()->title);

        $this->actingAs($this->user)
            ->delete(route('posts.destroy', $post))
            ->assertRedirect(route('posts.index'));
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_タイムラインの一覧と編集_削除ボタンの出方は今までどおり(): void
    {
        $mine = $this->existingPost($this->user, '自分のポスト', [$this->learn], now()->subHour());
        $others = $this->existingPost($this->other, '他人のポスト', [$this->learn], now()->subHours(2));

        $this->actingAs($this->user)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertSeeInOrder(['自分のポスト', '他人のポスト'])
            ->assertSee(route('posts.edit', $mine))
            ->assertSee('action="'.route('posts.destroy', $mine).'"', false)
            ->assertDontSee(route('posts.edit', $others))
            ->assertDontSee('action="'.route('posts.destroy', $others).'"', false)
            // 削除ボタン（value="DELETE"）は自分のポストの 1 つだけ
            ->assertSee('value="DELETE"', false);
    }

    public function test_タグがあってもいいね_リプライ_トピックは今までどおり(): void
    {
        $post = $this->existingPost($this->user, 'タグありのポスト', [$this->learn]);

        $this->actingAs($this->other)
            ->post(route('posts.like', $post))
            ->assertRedirect();
        $this->actingAs($this->other)
            ->post(route('replies.store', $post), ['content' => 'リプライです'])
            ->assertRedirect(route('posts.show', $post));

        $this->assertSame(1, $post->likes()->count());
        $this->assertSame(1, $post->replies()->count());

        $this->actingAs($this->other)
            ->get(route('posts.index'))
            ->assertOk()
            ->assertSee('技術メモ') // トピックの表示
            ->assertSee('action="'.route('posts.unlike', $post).'"', false);

        // 詳細画面にはタグを出さない（今回はタイムラインのみ）
        $this->actingAs($this->other)
            ->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('リプライです')
            ->assertDontSee('#学び');
    }

    public function test_トピックの入力チェックは今までどおり(): void
    {
        $this->actingAs($this->user)
            ->post(route('posts.store'), $this->payload(['category_id' => '', 'tag_ids' => [$this->learn->id]]))
            ->assertSessionHasErrors(['category_id' => 'トピックを選択してください。']);

        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('post_tag', 0);
    }
}
