<?php

namespace App\Support;

use App\Services\Releases\ZebralutionGenres;

/**
 * Select box options for the forms of the current label. Lists of
 * records are not here: they are searched and paged by OptionsController.
 */
class Options
{
    /**
     * The genres to suggest: Zebralution's list while the label delivers to Zebralution, otherwise none (any text goes).
     *
     * @return list<string>
     */
    public static function genres(): array
    {
        return app(CurrentLabel::class)->get()?->usesService(Services::ZEBRALUTION) ? ZebralutionGenres::names() : [];
    }
}
