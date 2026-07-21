<?php

namespace Fixtures\CommerceApp\Models;

use Illuminate\Database\Eloquent\Model;

final class Order extends Model
{
    protected $table = 'orders';

    protected $guarded = [];

    protected $casts = [
        'status' => 'string',
    ];

    public function canBeCancelled(): bool
    {
        return $this->status !== 'cancelled';
    }
}
