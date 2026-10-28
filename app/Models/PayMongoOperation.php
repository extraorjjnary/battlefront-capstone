<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayMongoOperation extends Model
{
    protected $table = 'paymongo_operations';

    protected $guarded = ['id'];

    /** @return BelongsTo<PayMongoFlow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(PayMongoFlow::class, 'paymongo_flow_id');
    }
}
