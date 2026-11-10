<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ポスト / つぶやき投稿アプリ</title>
    <style>
        @include('posts._styles')
        .detail { padding: 1rem 1rem 0.9rem; border-bottom: 1px solid #EFF1F4; }
        .detail .post-text { font-size: 1rem; }
        .section-title { padding: 0.6rem 1rem; font-weight: 800; font-size: 0.9rem; color: #5B6570; border-bottom: 1px solid #EFF1F4; }
        .reply-form { padding: 0.9rem 1rem 1.4rem; border-top: 1px solid #EFF1F4; }
        .reply-form label { display: block; color: #5B6570; margin-bottom: 0.4rem; font-size: 0.8rem; font-weight: 700; }
        .reply-form textarea { width: 100%; min-height: 90px; padding: 0.72rem 0.85rem; border: 1px solid #D3D9DE; border-radius: 10px; font-size: 0.95rem; font-family: inherit; line-height: 1.7; resize: vertical; }
        .reply-form textarea:focus { outline: none; border-color: #0F1419; box-shadow: 0 0 0 3px rgba(232, 121, 43, 0.18); }
        .reply-form .error { color: #D6402C; font-size: 0.83rem; margin-top: 0.3rem; }
        .reply-form .btn { margin-top: 0.7rem; padding: 0.55rem 1.5rem; border-radius: 999px; font-size: 0.9rem; font-weight: 700; cursor: pointer; font-family: inherit; background: #0F1419; color: #fff; border: none; }
        .reply-form .btn:hover { background: #272C30; }
        .reply-form .btn:focus-visible { outline: 2px solid #E8792B; outline-offset: 2px; }
    </style>
</head>
<body>
    @php
        $grad = config('post_ui.avatar_gradients');
        [$g1, $g2] = $grad[crc32($post->user->name) % count($grad)];
        [$tBg, $tFg] = config('post_ui.topic_tones')[$post->category->name] ?? ['#F0F1F3', '#57606A'];
    @endphp
    <div class="col">
        <header class="chrome">
            <div class="chrome-row">
                <a href="{{ route('posts.index') }}" class="brand"><span class="mark">つ</span>つぶやき投稿アプリ</a>
                <div class="me">
                    <span class="name">{{ auth()->user()->name }}（{{ auth()->user()->email }}）</span>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="logout-btn">ログアウト</button>
                    </form>
                </div>
            </div>
            <div class="page-title">ポスト</div>
        </header>

        <main>
            <article class="post detail">
                <div class="avatar" style="background: linear-gradient(135deg, {{ $g1 }}, {{ $g2 }});">{{ mb_substr($post->user->name, 0, 1) }}</div>
                <div class="post-body">
                    <div class="post-head">
                        <span class="name">{{ $post->user->name }}</span>
                        <span class="time">{{ $post->created_at->format('n月j日 H:i') }}</span>
                        <span class="topic" style="background: {{ $tBg }}; color: {{ $tFg }};">{{ $post->category->name }}</span>
                    </div>
                    <p class="post-title">{{ $post->title }}</p>
                    <p class="post-text">{{ $post->content }}</p>
                </div>
            </article>

            <h2 class="section-title">リプライ</h2>

            @forelse ($post->replies as $reply)
                @php
                    [$r1, $r2] = $grad[crc32($reply->user->name) % count($grad)];
                @endphp
                <article class="post reply">
                    <div class="avatar" style="background: linear-gradient(135deg, {{ $r1 }}, {{ $r2 }});">{{ mb_substr($reply->user->name, 0, 1) }}</div>
                    <div class="post-body">
                        <div class="post-head">
                            <span class="name">{{ $reply->user->name }}</span>
                            <span class="time">{{ $reply->created_at->format('n月j日 H:i') }}</span>
                        </div>
                        <p class="post-text">{{ $reply->content }}</p>
                        @can('delete', $reply)
                            <div class="post-actions">
                                <form action="{{ route('replies.destroy', $reply) }}" method="POST" style="display: inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit">削除</button>
                                </form>
                            </div>
                        @endcan
                    </div>
                </article>
            @empty
                <p class="empty">まだリプライがありません。</p>
            @endforelse

            <form class="reply-form" action="{{ route('replies.store', $post) }}" method="POST">
                @csrf
                <label for="content">リプライ</label>
                {{-- required 属性は付けない（空のときにサーバー側のエラーメッセージを表示するため） --}}
                <textarea id="content" name="content">{{ old('content') }}</textarea>
                @error('content')
                    <p class="error">{{ $message }}</p>
                @enderror
                <button type="submit" class="btn">返信する</button>
            </form>
        </main>
    </div>
</body>
</html>
