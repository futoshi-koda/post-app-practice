<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostContentLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->category = Category::create(['name' => 'お知らせ']);
        $this->post = Post::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'title' => '元のタイトル',
            'content' => '元の本文',
        ]);
    }

    private function update(string $content, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->from(route('posts.edit', $this->post))
            ->put(route('posts.update', $this->post), [
                'title' => '更新後のタイトル',
                'category_id' => $this->category->id,
                'content' => $content,
            ]);
    }

    public function test_140字ちょうどなら更新できる(): void
    {
        $content = str_repeat('あ', 140);

        $this->update($content)->assertRedirect(route('posts.index'));

        $this->assertSame($content, $this->post->fresh()->content);
    }

    public function test_141字だとエラーになり保存されない(): void
    {
        $this->update(str_repeat('あ', 141))
            ->assertRedirect(route('posts.edit', $this->post))
            ->assertSessionHasErrors(['content' => '本文は140字以内で入力してください。']);

        $this->assertSame('元の本文', $this->post->fresh()->content);
    }

    public function test_ブラウザ形式の改行_crl_f_は1字として数える(): void
    {
        // 改行 1 つ + 139 字 = 140 字。CRLF のまま数えると 141 字になってしまう
        $content = str_repeat('あ', 70)."\r\n".str_repeat('い', 69);

        $this->update($content)->assertRedirect(route('posts.index'));

        $this->assertSame(
            str_repeat('あ', 70)."\n".str_repeat('い', 69),
            $this->post->fresh()->content
        );
    }

    public function test_空の本文は日本語のエラーになる(): void
    {
        $this->update('')
            ->assertSessionHasErrors(['content' => '本文を入力してください。']);
    }

    public function test_他人の投稿は更新できない(): void
    {
        $this->update('更新', User::factory()->create())->assertForbidden();

        $this->assertSame('元の本文', $this->post->fresh()->content);
    }
}
