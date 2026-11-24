<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the payee owes under an agreement: a line of what the
 * agreement says is to be delivered, with the file handed in for it.
 * The file is an ordinary file of the agreement's release.
 *
 * @property int $id
 * @property int $agreement_id
 * @property string $title
 * @property string $status One of the constants below.
 * @property int|null $release_file_id
 * @property string|null $returned_note Why the label asked for it again.
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['agreement_id', 'title', 'status', 'release_file_id', 'returned_note', 'delivered_at', 'accepted_at'])]
class AgreementDeliverable extends Model
{
    public const string OPEN = 'open';

    public const string DELIVERED = 'delivered';

    public const string RETURNED = 'returned';

    public const string ACCEPTED = 'accepted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivered_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Agreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /**
     * The file handed in, in whichever label the reader works.
     *
     * @return BelongsTo<ReleaseFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(ReleaseFile::class, 'release_file_id')->withoutGlobalScope('label');
    }

    /**
     * What the label and the payee both see of it.
     *
     * @return array{id: int, title: string, status: string, file: string|null, file_id: int|null, returned_note: string|null, delivered_at: string|null, accepted_at: string|null}
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'file' => $this->file?->original_name,
            'file_id' => $this->release_file_id,
            'returned_note' => $this->returned_note,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
        ];
    }
}
