<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ホーム / つぶやき投稿アプリ</title>
    <style>
        @include('posts._styles')
    </style>
</head>
<body>
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
            <div class="page-title">ホーム</div>
        </header>

        <main class="feed">
            @if($posts->isEmpty())
                <p class="empty">まだポストがありません。</p>
            @else
                @foreach ($posts as $post)
                    @php
                        $grad = config('post_ui.avatar_gradients');
                        [$g1, $g2] = $grad[crc32($post->user->name) % count($grad)];
                        [$tBg, $tFg] = config('post_ui.topic_tones')[$post->category->name] ?? ['#F0F1F3', '#57606A'];
                    @endphp
                    <article class="post">
                        <div class="avatar" style="background: linear-gradient(135deg, {{ $g1 }}, {{ $g2 }});">{{ mb_substr($post->user->name, 0, 1) }}</div>
                        <div class="post-body">
                            <div class="post-head">
                                <span class="name">{{ $post->user->name }}</span>
                                <a href="{{ route('posts.show', $post) }}" class="time">{{ $post->created_at->format('n月j日 H:i') }}</a>
                                <span class="topic" style="background: {{ $tBg }}; color: {{ $tFg }};">{{ $post->category->name }}</span>
                            </div>
                            <p class="post-title">{{ $post->title }}</p>
                            <a href="{{ route('posts.show', $post) }}" class="post-text-link"><p class="post-text">{{ $post->content }}</p></a>
                            @canany(['update', 'delete'], $post)
                                <div class="post-actions">
                                    @can('update', $post)
                                        <a href="{{ route('posts.edit', $post) }}">編集</a>
                                    @endcan
                                    @can('delete', $post)
                                        <form action="{{ route('posts.destroy', $post) }}" method="POST" style="display: inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit">削除</button>
                                        </form>
                                    @endcan
                                </div>
                            @endcanany
                        </div>
                    </article>
                @endforeach
            @endif
        </main>
    </div>
</body>
</html>
