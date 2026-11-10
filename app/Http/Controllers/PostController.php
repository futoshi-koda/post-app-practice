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

        $validated = $this->validateContent($request, [
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
        ]);

        $post->update($validated);

        return redirect()->route('posts.index');
    }

    public function destroy(Post $post)
    {
        $this->authorize('delete', $post);

        $post->delete();

        return redirect()->route('posts.index');
    }
}
