<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentRequest;
use App\Models\Comment;
use App\Models\TeamNewsItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class CommentsController extends Controller
{
    public function store(StoreCommentRequest $request, string $slug): RedirectResponse
    {
        $item = TeamNewsItem::query()->where('slug', $slug)->firstOrFail();

        Comment::create([
            'user_id' => $request->user()->id,
            'team_news_item_id' => $item->id,
            'body' => trim($request->validated('body')),
        ]);

        return back();
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        Gate::authorize('delete', $comment);

        $comment->delete();

        return back();
    }
}
