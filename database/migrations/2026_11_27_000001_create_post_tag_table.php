<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ポストとタグをつなぐ中間テーブル。付け外ししか使わないので id とタイムスタンプは持たない。
        // 同じタグを同じポストに 2 回は付けられない（組み合わせで主キー）。
        // ポストやタグが消えたら、つながりだけが一緒に消える
        Schema::create('post_tag', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['post_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_tag');
    }
};
