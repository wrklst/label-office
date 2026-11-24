<?php

namespace App\Models;

use App\Enums\LabelRole;
use App\Support\Services;
use Carbon\CarbonImmutable;
use Database\Factories\LabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $currency
 * @property string|null $contact_email
 * @property string|null $logo_path The label's logo as "disk:path".
 * @property string|null $signet_path The label's small mark as "disk:path".
 * @property string|null $legal_name The name the label signs agreements under, e.g. "Example Records GmbH".
 * @property string|null $legal_form One of LEGAL_FORMS.
 * @property string|null $legal_address
 * @property string|null $legal_register Court and number of the register entry, when there is one.
 * @property bool $accounts_mechanicals
 * @property bool $accounts_ppd
 * @property bool $accounts_reserves
 * @property array<string, mixed>|null $importer_settings
 * @property array<string, mixed>|null $royalty_tool_settings
 * @property list<string>|null $airplay_markets
 * @property array<string, mixed>|null $delivery_settings What the label has agreed with its services, by service.
 * @property string|null $dataforseo_login
 * @property string|null $dataforseo_password
 * @property string|null $ai_provider The AI service receipts are read with, a key of AiConnection::SERVICES.
 * @property string|null $ai_key
 * @property string|null $ai_endpoint
 * @property string|null $ai_model
 * @property array{at: string, seconds: float, missed: list<string>}|null $ai_trial
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Pivot&object{role: string, payee_id: int|null} $pivot Membership of the user when loaded through User::labels().
 */
#[Fillable(['name', 'slug', 'currency', 'contact_email', 'logo_path', 'signet_path', 'legal_name', 'legal_form', 'legal_address', 'legal_register', 'accounts_mechanicals', 'accounts_ppd', 'accounts_reserves', 'importer_settings', 'royalty_tool_settings', 'airplay_markets', 'delivery_settings', 'dataforseo_login', 'dataforseo_password', 'ai_provider', 'ai_key', 'ai_endpoint', 'ai_model', 'ai_trial'])]
#[Hidden(['dataforseo_login', 'dataforseo_password', 'ai_key'])]
class Label extends Model
{
    /** @use HasFactory<LabelFactory> */
    use HasFactory;

    /** @var list<string> What the label can be as a party to an agreement. */
    public const array LEGAL_FORMS = ['gmbh', 'gbr', 'other'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accounts_mechanicals' => 'boolean',
            'accounts_ppd' => 'boolean',
            'accounts_reserves' => 'boolean',
            'importer_settings' => 'array',
            'royalty_tool_settings' => 'array',
            'airplay_markets' => 'array',
            'delivery_settings' => 'array',
            'dataforseo_login' => 'encrypted',
            'dataforseo_password' => 'encrypted',
            'ai_key' => 'encrypted',
            'ai_trial' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['role', 'payee_id', 'signs_for_label', 'self_dealing_exempt'])->withTimestamps();
    }

    /**
     * Everyone whose signature the label needs on an agreement: all of
     * them, as for a partnership whose partners only sign together.
     *
     * @return BelongsToMany<User, $this>
     */
    public function signatories(): BelongsToMany
    {
        return $this->users()->wherePivot('role', LabelRole::Admin->value)->wherePivot('signs_for_label', true);
    }

    /**
     * The countries an airplay research searches in, as ISO codes.
     *
     * @return non-empty-list<string>
     */
    public function airplayMarkets(): array
    {
        return $this->airplay_markets ?: ['DE', 'GB', 'US'];
    }

    /**
     * Whether the label works with a service, so what is built for it is
     * shown. A label that never chose has them all. The choice is kept
     * under "distributors" in the delivery settings, where it was first
     * stored for Zebralution.
     */
    public function usesService(string $key): bool
    {
        return Services::has($key) && (bool) $this->deliverySetting("distributors.{$key}", true);
    }

    /**
     * @return list<string> The keys of the services the label works with.
     */
    public function services(): array
    {
        return array_values(array_filter(Services::keys(), $this->usesService(...)));
    }

    /**
     * Read a delivery setting, e.g. "zebralution.number".
     */
    public function deliverySetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->delivery_settings, $key, $default);
    }

    /**
     * Read an importer setting, e.g. "bandcamp.disable_fee".
     */
    public function importerSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->importer_settings, $key, $default);
    }
}
