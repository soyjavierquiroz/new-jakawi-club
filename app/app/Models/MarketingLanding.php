<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingLanding extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'published_configuration' => 'array', 'published_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    public function isPublic(): bool
    {
        return $this->status === 'published' && $this->published_configuration !== null;
    }

    public function isHome(): bool
    {
        return $this->slug === config('jakawi.home_landing_slug');
    }
}
