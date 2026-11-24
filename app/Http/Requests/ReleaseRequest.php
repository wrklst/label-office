<?php

namespace App\Http\Requests;

use App\Enums\ArtistRole;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseType;
use App\Models\Release;
use App\Rules\Upc;
use App\Services\Imports\Identifiers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class ReleaseRequest extends LabelFormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // A form with no credit rows sends an empty marker instead of a list.
        if ($this->input('artists') === '' || $this->input('artists') === null && $this->has('artists')) {
            $this->merge(['artists' => []]);
        }

        $this->nullify(['artist_id', 'released_on', 'mechanicals_rate', 'ppd_rate', 'reserves_rate', 'release_type', 'explicit', 'ai_artwork', 'original_released_on', 'preorder_on', 'files_url']);

        // Lists may arrive as "Spotify, Tidal" from a text field.
        foreach (['blocked_services' => false, 'blocked_territories' => true] as $key => $uppercase) {
            $value = $this->input($key);

            if (is_string($value)) {
                $value = preg_split('/\s*[,;\n]\s*/', $value) ?: [];
            }

            if (is_array($value)) {
                $this->merge([$key => array_values(array_unique(array_filter(array_map(
                    fn (mixed $entry): mixed => is_string($entry) ? ($uppercase ? strtoupper(trim($entry)) : trim($entry)) : $entry,
                    $value,
                ), fn (mixed $entry): bool => $entry !== '')))]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $release = $this->route('release');

        return [
            'name' => ['required', 'string', 'max:255'],
            'artist_id' => ['nullable', 'integer', $this->inLabel('artists')],
            'catalog_number' => ['nullable', 'string', 'max:255'],
            'upc' => ['nullable', 'string', 'max:32', new Upc, function (string $attribute, mixed $value, Closure $fail) use ($release): void {
                // A 12 digit UPC and its 13 digit EAN form are the same code.
                $key = Identifiers::upcKey(is_string($value) ? $value : null);
                $taken = $key === null ? null : Release::query()
                    ->whereNotNull('upc')
                    ->whereKeyNot($release instanceof Release ? $release->id : 0)
                    ->get(['id', 'name', 'upc'])
                    ->first(fn (Release $other): bool => Identifiers::upcKey($other->upc) === $key);

                if ($taken !== null) {
                    $fail("This UPC / EAN already belongs to the release \"{$taken->name}\". A code identifies one release.");
                }
            }],
            'released_on' => ['nullable', 'date'],
            'spotify_id' => ['nullable', 'string', 'max:255'],
            'discogs_id' => ['nullable', 'string', 'max:255'],
            'accounts_mechanicals' => ['boolean'],
            'mechanicals_rate' => ['nullable', 'numeric', 'min:0'],
            'ppd_rate' => ['nullable', 'numeric', 'min:0'],
            'reserves_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => [Rule::enum(ReleaseStatus::class)],
            'release_type' => ['nullable', Rule::enum(ReleaseType::class)],
            'version' => ['nullable', 'string', 'max:255'],
            'genre' => ['nullable', 'string', 'max:255'],
            'subgenre' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:8'],
            'explicit' => ['nullable', Rule::in(['explicit', 'clean'])],
            'ai_artwork' => ['nullable', Rule::in(['none', 'partly', 'all'])],
            'p_line' => ['nullable', 'string', 'max:255'],
            'c_line' => ['nullable', 'string', 'max:255'],
            'original_released_on' => ['nullable', 'date'],
            'preorder_on' => ['nullable', 'date'],
            'press_release' => ['nullable', 'string', 'max:100000'],
            'key_facts' => ['nullable', 'string', 'max:20000'],
            'todo' => ['nullable', 'string', 'max:20000'],
            'files_url' => ['nullable', 'url:http,https', 'max:2000'],
            'blocked_services' => ['nullable', 'array'],
            'blocked_services.*' => ['string', 'max:100'],
            'blocked_territories' => ['nullable', 'array'],
            'blocked_territories.*' => ['string', 'size:2', 'alpha:ascii'],
            'artists' => ['array'],
            'artists.*.artist_id' => ['required', 'integer', $this->inLabel('artists')],
            'artists.*.role' => ['required', Rule::enum(ArtistRole::class)],
            'tracks' => ['array'],
            'tracks.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('release_track', 'track_id')->where('release_id', $release instanceof Release ? $release->id : 0)],
            // The track rows validate like the track form does.
            ...TrackRequest::sharedRules('tracks.*.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['tracks.*.name' => 'track name', 'tracks.*.isrc' => 'ISRC', 'blocked_territories.*' => 'country code', 'artists.*.artist_id' => 'artist'];
    }
}
