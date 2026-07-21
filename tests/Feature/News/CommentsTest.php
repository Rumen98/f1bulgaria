<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\TeamNewsItem;
use App\Models\User;

it('гост не може да коментира — праща се към login', function () {
    $item = TeamNewsItem::factory()->create();

    $this->post(route('news.comments.store', $item->slug), ['body' => 'Тест'])
        ->assertRedirect(route('login'));

    expect(Comment::count())->toBe(0);
});

it('логнат потребител с потвърден имейл може да коментира', function () {
    $user = User::factory()->create();
    $item = TeamNewsItem::factory()->create();

    $this->actingAs($user)
        ->post(route('news.comments.store', $item->slug), ['body' => 'Верстапен пак ще ги разбие.'])
        ->assertRedirect();

    $comment = Comment::first();
    expect($comment)->not->toBeNull()
        ->and($comment->user_id)->toBe($user->id)
        ->and($comment->team_news_item_id)->toBe($item->id)
        ->and($comment->body)->toBe('Верстапен пак ще ги разбие.');
});

it('отхвърля празен и прекалено дълъг коментар', function () {
    $user = User::factory()->create();
    $item = TeamNewsItem::factory()->create();

    $this->actingAs($user)->from(route('news.show', $item->slug))
        ->post(route('news.comments.store', $item->slug), ['body' => ''])
        ->assertSessionHasErrors('body');

    $this->actingAs($user)->from(route('news.show', $item->slug))
        ->post(route('news.comments.store', $item->slug), ['body' => str_repeat('а', 2001)])
        ->assertSessionHasErrors('body');

    expect(Comment::count())->toBe(0);
});

it('собственикът може да изтрие своя коментар (soft delete)', function () {
    $comment = Comment::factory()->create();

    $this->actingAs($comment->user)
        ->delete(route('comments.destroy', $comment))
        ->assertRedirect();

    expect(Comment::count())->toBe(0)
        ->and(Comment::withTrashed()->count())->toBe(1);
});

it('чужд потребител не може да изтрие коментара', function () {
    $comment = Comment::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->delete(route('comments.destroy', $comment))
        ->assertForbidden();

    expect(Comment::count())->toBe(1);
});

it('админ може да изтрие всеки коментар', function () {
    $comment = Comment::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->delete(route('comments.destroy', $comment))
        ->assertRedirect();

    expect(Comment::count())->toBe(0);
});

it('статията показва коментарите хронологично, без изтритите', function () {
    $item = TeamNewsItem::factory()->approved()->create();
    $first = Comment::factory()->create(['team_news_item_id' => $item->id, 'created_at' => now()->subHour()]);
    $second = Comment::factory()->create(['team_news_item_id' => $item->id, 'created_at' => now()]);
    Comment::factory()->create(['team_news_item_id' => $item->id, 'deleted_at' => now()]);

    $this->get(route('news.show', $item->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('comments', 2)
            ->where('comments.0.id', $first->id)
            ->where('comments.1.id', $second->id)
            ->where('comments.0.can_delete', false));
});

it('маркира кои коментари може да трие текущият потребител', function () {
    $item = TeamNewsItem::factory()->approved()->create();
    $mine = Comment::factory()->create(['team_news_item_id' => $item->id]);
    Comment::factory()->create(['team_news_item_id' => $item->id]);

    $this->actingAs($mine->user)
        ->get(route('news.show', $item->slug))
        ->assertInertia(fn ($page) => $page
            ->where('comments.0.can_delete', true)
            ->where('comments.1.can_delete', false));
});

it('ограничава честотата на коментиране (throttle)', function () {
    $user = User::factory()->create();
    $item = TeamNewsItem::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->actingAs($user)->post(route('news.comments.store', $item->slug), ['body' => "Коментар {$i}"]);
    }

    $this->actingAs($user)
        ->post(route('news.comments.store', $item->slug), ['body' => 'Шестият'])
        ->assertStatus(429);
});
