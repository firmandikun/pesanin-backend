<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\BlogPost;
use App\Models\Tutorial;

/** Public site content managed from the admin dashboard: tutorials, blog, and in-app announcements. */
class ContentController extends Controller
{
    public function tutorials()
    {
        return ['data' => Tutorial::where('is_published', true)->orderBy('sort')->orderByDesc('id')
            ->get(['id', 'slug', 'title', 'category', 'description', 'video_url', 'duration', 'steps', 'views', 'created_at'])];
    }

    public function tutorialView(string $slug)
    {
        Tutorial::where('slug', $slug)->where('is_published', true)->increment('views');

        return response()->noContent();
    }

    public function blog()
    {
        return ['data' => BlogPost::where('is_published', true)->where('published_at', '<=', now())->latest('published_at')->latest('id')
            ->get(['slug', 'title', 'category', 'excerpt', 'cover_url', 'published_at', 'body'])
            ->map(fn ($p) => ['minutes' => max(1, (int) ceil(str_word_count($p->body) / 200))] + collect($p)->except('body')->all())];
    }

    public function blogPost(string $slug)
    {
        $post = BlogPost::where('slug', $slug)->where('is_published', true)->where('published_at', '<=', now())->firstOrFail();
        $post->increment('views');

        return $post->only(['slug', 'title', 'category', 'excerpt', 'cover_url', 'published_at', 'body']) + ['minutes' => max(1, (int) ceil(str_word_count($post->body) / 200))];
    }

    public function announcements()
    {
        return ['data' => Announcement::where('is_published', true)->where('published_at', '<=', now())->latest('published_at')->latest('id')->limit(30)
            ->get(['id', 'tag', 'title', 'body', 'published_at'])];
    }

    /** Public site config: contact, notice banner, registration status, and plan prices/quotas for the pricing table. */
    public function site()
    {
        $site = array_merge(AdminController::SITE_DEFAULTS, \App\Models\PlatformSetting::read('site', []));

        return $site + ['plans' => collect(\App\Support\Plans::all())->map(fn ($p, $id) => ['id' => $id, 'name' => $p['name'], 'monthly' => $p['monthly'], 'limits' => $p['limits']])->values()];
    }
}
