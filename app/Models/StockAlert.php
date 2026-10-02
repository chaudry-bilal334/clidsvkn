<?php

namespace App\Models;

use Database\Factories\StockAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAlert extends Model
{
    /** @use HasFactory<StockAlertFactory> */
    use HasFactory;

    protected $fillable = [
        'inventory_batch_id',
        'threshold_days',
        'alert_type',
        'message',
    ];

    protected $casts = [
        'threshold_days' => 'integer',
    ];
}
