<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'order_number',
        'shipping_address',
        'total_amount',
        'status',
        'payment_method'
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    /**
     * Relasi ke User (Order belongs to User)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relasi ke OrderItems (Order has many OrderItems)
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Relasi ke Payment (Order has one Payment)
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}