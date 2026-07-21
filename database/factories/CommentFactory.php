<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Comment;
use App\Models\TeamNewsItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_news_item_id' => TeamNewsItem::factory(),
            'body' => $this->faker->realText(160),
        ];
    }
}
