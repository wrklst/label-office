<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's signature under an agreement, in one capacity, with what
 * is known about how it was given.
 *
 * @property int $id
 * @property int $agreement_id
 * @property int|null $user_id
 * @property string $capacity One of Agreement::CAPACITIES.
 * @property string $name The name the signer typed.
 * @property string $email The address of the account at the time.
 * @property string $body_hash The SHA-256 of the text that was signed.
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $signed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['agreement_id', 'user_id', 'capacity', 'name', 'email', 'body_hash', 'ip', 'user_agent', 'signed_at'])]
class AgreementSignature extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Agreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }
}
