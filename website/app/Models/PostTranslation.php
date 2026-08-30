<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostTranslation extends Model
{
    protected $fillable = ['post_id', 'locale', 'title', 'excerpt', 'content', 'tags', 'auto'];

    protected $casts = ['tags' => 'array', 'auto' => 'boolean'];
}
