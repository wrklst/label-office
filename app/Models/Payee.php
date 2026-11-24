<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLabel;
use Carbon\CarbonImmutable;
use Database\Factories\PayeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $label_id
 * @property string $name
 * @property string|null $email
 * @property list<string>|null $tags
 * @property string|null $notes
 * @property string|null $legal_name The name the payee signs agreements under, when it is not the name above.
 * @property string|null $address
 * @property string|null $country Where the payee is resident for tax, as an ISO code.
 * @property string|null $vat_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['label_id', 'name', 'email', 'tags', 'notes', 'legal_name', 'address', 'country', 'vat_id'])]
class Payee extends Model
{
    /** @use HasFactory<PayeeFactory> */
    use BelongsToLabel, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    /**
     * @return HasMany<ContractSplit, $this>
     */
    public function splits(): HasMany
    {
        return $this->hasMany(ContractSplit::class);
    }

    /**
     * @return HasMany<Payout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /**
     * @return HasMany<Statement, $this>
     */
    public function statements(): HasMany
    {
        return $this->hasMany(Statement::class);
    }

    /**
     * Why the payee cannot be deleted, or null when it can.
     */
    public function deleteBlock(): ?string
    {
        $hasTransfers = Transaction::query()->where('payee_id', $this->id)->exists();

        return $hasTransfers || $this->payouts()->exists() || $this->statements()->exists()
            ? 'This payee has transfers, payouts or statements and cannot be deleted.'
            : null;
    }
}
