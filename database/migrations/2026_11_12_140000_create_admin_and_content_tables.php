<?php

use App\Support\ContentDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Platform admin flag, account suspension, and the content managed from the admin dashboard. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('plan_auto_renew');
            $table->timestamp('suspended_at')->nullable()->after('is_admin');
        });

        Schema::create('tutorials', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 140)->unique();
            $table->string('title', 140);
            $table->string('category', 20); // pemula | analitik | lanjutan
            $table->string('description', 300);
            $table->string('video_url')->nullable(); // YouTube link; empty = written guide only
            $table->string('duration', 10)->nullable(); // "5:28"
            $table->json('steps')->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('title', 160);
            $table->string('category', 40);
            $table->string('excerpt', 300);
            $table->longText('body'); // one paragraph per line; "## " heading, "- " list item
            $table->string('cover_url')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('tag', 20)->default('NEW'); // NEW | UPDATE | INFO
            $table->string('title', 120);
            $table->text('body');
            $table->boolean('is_published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        foreach (ContentDefaults::TUTORIALS as $i => [$title, $category, $desc, $steps]) {
            DB::table('tutorials')->insert(['slug' => Str::slug($title), 'title' => $title, 'category' => $category, 'description' => $desc,
                'steps' => json_encode($steps), 'sort' => $i, 'is_published' => true, 'created_at' => $now->copy()->subMinutes($i), 'updated_at' => $now]);
        }
        foreach (json_decode((string) file_get_contents(database_path('data/blog.json')), true) ?: [] as $p) {
            DB::table('blog_posts')->insert(['slug' => $p['slug'], 'title' => $p['title'], 'category' => $p['category'], 'excerpt' => $p['excerpt'],
                'body' => implode("\n", $p['body']), 'is_published' => true, 'published_at' => $p['date'].' 00:00:00', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (ContentDefaults::ANNOUNCEMENTS as $i => [$tag, $title, $body]) {
            DB::table('announcements')->insert(['tag' => $tag, 'title' => $title, 'body' => $body, 'is_published' => true,
                'published_at' => $now->copy()->subDays($i), 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('tutorials');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_admin', 'suspended_at']));
    }
};
