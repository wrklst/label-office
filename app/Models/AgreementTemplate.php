<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLabel;
use Carbon\CarbonImmutable;
use Database\Factories\AgreementTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The label's own text for a kind of agreement. The label answers for
 * it: the examples that ship with the application are only copied from.
 *
 * @property int $id
 * @property int $label_id
 * @property string $name
 * @property string $body
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['label_id', 'name', 'body'])]
class AgreementTemplate extends Model
{
    /** @use HasFactory<AgreementTemplateFactory> */
    use BelongsToLabel, HasFactory;

    /**
     * What a template can leave open for the single agreement to fill in.
     *
     * @var array<string, string>
     */
    public const array FIELDS = [
        'effective_date' => 'The date the agreement takes effect',
        'label.legal_name' => 'Legal name of the label',
        'label.address' => 'Address of the label',
        'label.register' => 'Register entry of the label',
        'label.signatories' => 'Names of everyone who signs for the label',
        'payee.name' => 'Name of the payee as an artist',
        'payee.legal_name' => 'Legal name of the payee',
        'payee.address' => 'Address of the payee',
        'artist.name' => 'Name of the original artist, when they are a party of their own',
        'artist.legal_name' => 'Legal name of the original artist',
        'artist.address' => 'Address of the original artist',
        'work.title' => 'Title of the remix or release',
        'work.original_title' => 'Title of the recording that is remixed',
        'work.artist' => 'Artist of the release or of the recording that is remixed',
        'work.tracks' => 'The tracks the agreement covers, one per line',
        'share' => 'Share of the payee in percent',
        'publishing_share' => 'Share of the payee in the publishing of a remix in percent',
        'term' => 'How long the rights are granted for',
        'deliverables' => 'What has to be delivered',
        'delivery_date' => 'The date delivery is due',
    ];

    /**
     * The fields a text uses that no agreement could fill in.
     *
     * @return list<string>
     */
    public static function unknownFields(string $body): array
    {
        preg_match_all('/\{\{\s*(.*?)\s*\}\}/s', $body, $matches);

        return array_values(array_unique(array_diff($matches[1], array_keys(self::FIELDS))));
    }
}
