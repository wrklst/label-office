<?php

namespace App\Models;

use App\Enums\LabelRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_label_id
 * @property bool $may_create_labels Made to run a label, not invited to one: may open a first label.
 * @property bool $installation_admin May change settings of the whole installation, such as the file storage.
 * @property list<string>|null $hidden_notices The notices this person acknowledged and hid.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Pivot&object{role: string, payee_id: int|null, signs_for_label: bool|int, self_dealing_exempt: bool|int} $pivot Membership of the label when loaded through Label::users().
 */
#[Fillable(['name', 'email', 'password', 'current_label_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installation_admin' => 'boolean',
            'hidden_notices' => 'array',
            'may_create_labels' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Someone invited to a label does not get to open one of their own,
     * also not after being removed from it; whoever runs a label does,
     * and so does an account made to run one that has none yet.
     */
    public function canCreateLabel(): bool
    {
        return $this->labels()->wherePivot('role', LabelRole::Admin->value)->exists()
            || ($this->may_create_labels && $this->labels()->doesntExist());
    }

    /**
     * @return BelongsToMany<Label, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class)->withPivot(['role', 'payee_id'])->withTimestamps();
    }

    /**
     * @return BelongsTo<Label, $this>
     */
    public function currentLabel(): BelongsTo
    {
        return $this->belongsTo(Label::class, 'current_label_id');
    }

    /**
     * Close every way into the account other than its password. Used
     * when the owner of the email address takes over an account whose
     * address was never proven: whoever registered it may be someone else.
     */
    public function revokeAccessExceptPassword(): void
    {
        $this->passkeys()->delete();

        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table'))->where('user_id', $this->id)->delete();
        }
    }
}
