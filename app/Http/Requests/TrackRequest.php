<?php

namespace App\Http\Requests;

use App\Enums\ArtistRole;
use App\Models\Track;
use App\Rules\Isrc;
use App\Services\Imports\Identifiers;
use App\Support\CurrentLabel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class TrackRequest extends LabelFormRequest
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

        $this->nullify(['artist_id', 'contract_id', 'track_number', 'duration', 'accounts_mechanicals', 'mechanicals_rate', 'ppd_rate', 'disc_number', 'explicit', 'bpm', 'preview_start', 'ai_recording', 'ai_composition', 'ai_lyrics']);

        // Other spellings arrive one per line from a text field.
        if (is_string($this->input('aliases')) || $this->input('aliases') === null && $this->has('aliases')) {
            $this->merge(['aliases' => array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string) $this->input('aliases')) ?: []), fn (string $name): bool => $name !== '')))]);
        }

        // Credits may arrive as "Name One, Name Two" per role from text fields.
        $credits = $this->input('credits');

        if (is_array($credits)) {
            $this->merge(['credits' => array_filter(array_map(function (mixed $names): mixed {
                if ($names === null) {
                    return [];
                }

                if (is_string($names)) {
                    $names = preg_split('/\s*[,;\n]\s*/', $names) ?: [];
                }

                return is_array($names) ? array_values(array_filter(array_map(fn (mixed $name): mixed => is_string($name) ? trim($name) : $name, $names), fn (mixed $name): bool => $name !== '')) : $names;
            }, $credits), fn (mixed $names): bool => $names !== [])]);
        }
    }

    /**
     * The rules for the fields every place that edits a track has: this
     * request, and the track rows of the release form under their prefix.
     *
     * @return array<string, list<mixed>>
     */
    public static function sharedRules(string $prefix = ''): array
    {
        $labelId = app(CurrentLabel::class)->get()->id;
        $inLabel = fn (string $table): Exists => Rule::exists($table, 'id')->where('label_id', $labelId);

        return [
            "{$prefix}name" => ['required', 'string', 'max:255'],
            "{$prefix}artist_id" => ['nullable', 'integer', $inLabel('artists')],
            "{$prefix}contract_id" => ['nullable', 'integer', $inLabel('contracts')],
            "{$prefix}isrc" => ['nullable', 'string', 'max:16', new Isrc],
            "{$prefix}iswc" => ['nullable', 'string', 'max:16'],
            "{$prefix}track_number" => ['nullable', 'integer', 'min:1', 'max:999'],
            "{$prefix}duration" => ['nullable', 'integer', 'min:0'],
            "{$prefix}version" => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $track = $this->route('track');
        $trackId = $track instanceof Track ? $track->id : null;

        return [
            ...self::sharedRules(),
            // A recording is one track, whatever releases it is on.
            'isrc' => ['nullable', 'string', 'max:16', new Isrc, function (string $attribute, mixed $value, Closure $fail) use ($trackId): void {
                $other = Track::query()->where('isrc', Identifiers::isrc((string) $value))->when($trackId, fn ($query) => $query->whereKeyNot($trackId))->first();

                if ($other !== null && Identifiers::isrc((string) $value) !== null) {
                    $fail(__('Another track already has this ISRC: :name.', ['name' => $other->name]));
                }
            }],
            'aliases' => ['array'],
            'aliases.*' => ['string', 'max:255'],
            // The release the numbers are for, when the track is on several.
            'release_id' => ['nullable', 'integer', Rule::exists('release_track', 'release_id')->where('track_id', $trackId ?? 0)],
            // Null follows the release.
            'accounts_mechanicals' => ['nullable', 'boolean'],
            'mechanicals_rate' => ['nullable', 'numeric', 'min:0'],
            'ppd_rate' => ['nullable', 'numeric', 'min:0'],
            'disc_number' => ['nullable', 'integer', 'min:1', 'max:99'],
            'genre' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:8'],
            'explicit' => ['nullable', Rule::in(['explicit', 'clean'])],
            'ai_recording' => ['nullable', Rule::in(['none', 'partly', 'all'])],
            'ai_composition' => ['nullable', Rule::in(['none', 'partly', 'all'])],
            'ai_lyrics' => ['nullable', Rule::in(['none', 'partly', 'all'])],
            'bpm' => ['nullable', 'numeric', 'decimal:0,2', 'min:20', 'max:400'],
            'musical_key' => ['nullable', 'string', 'max:20'],
            'preview_start' => ['nullable', 'integer', 'min:0', 'max:36000'],
            'p_line' => ['nullable', 'string', 'max:255'],
            'lyrics' => ['nullable', 'string', 'max:50000'],
            'credits' => ['nullable', 'array:composers,lyricists,producers,remixers,publishers'],
            'credits.*' => ['array'],
            'credits.*.*' => ['string', 'max:255'],
            'artists' => ['array'],
            'artists.*.artist_id' => ['required', 'integer', $this->inLabel('artists')],
            'artists.*.role' => ['required', Rule::enum(ArtistRole::class)],
        ];
    }
}
