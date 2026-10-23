<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $attempt_number
 */
class PayMongoAttempt extends Model
{
    protected $table = 'paymongo_attempts';

    protected $guarded = ['id'];

    /** @return BelongsTo<PayMongoFlow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(PayMongoFlow::class, 'paymongo_flow_id');
    }
}
