<?php namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_number',
        'item_name',
        'quantity',
        'price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
    ];

    public function scopeSmartSearch(Builder $query, ?string $term): Builder
    {
        if (empty(trim($term))) {
            return $query;
        }

        $words = preg_split('/\s+/', trim($term));

        return $query->where(function (Builder $q) use ($term, $words) {
            $q->where('item_number', 'like', "%{$term}%")
              ->orWhere('item_name', 'like', "%{$term}%")
              ->orWhere(function (Builder $sub) use ($words) {
                  foreach ($words as $word) {
                      $sub->where('item_name', 'like', "%{$word}%");
                  }
              });
        });
    }
}