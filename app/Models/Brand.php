<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property string $name
 * @property string $description
 * @property int    $author_id
 * @property string $uuid
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Brand extends NsModel
{
    protected $table = 'nexopos_' . 'brands';

    public function products(): HasMany
    {
        return $this->hasMany( Product::class, 'brand_id' );
    }

    public function author()
    {
        return $this->belongsTo( User::class, 'author_id' );
    }

    public function scopeFindLike( $query, $name )
    {
        return $query->where( 'name', 'like', '%' . $name . '%' );
    }
}
