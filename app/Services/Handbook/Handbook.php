<?php

namespace App\Services\Handbook;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The handbook: one Markdown file per article in /docs, named
 * "NN-slug.md". The number gives the order inside a chapter and, with
 * a few exceptions, its first digit the chapter; the chapters are the
 * groups of the sidebar, in its order. The title is the file's first
 * heading. The files link to one
 * another by file name, so they read the same on GitHub and in the app.
 *
 * @phpstan-type Article array{slug: string, title: string, chapter: string, order: int, body: string}
 */
class Handbook
{
    /**
     * The chapters in the order the handbook shows them. Importing,
     * Accounting, Deals, Catalog and Label are the groups of the sidebar.
     */
    public const array CHAPTERS = ['Setup', 'Importing', 'Accounting', 'Deals', 'Catalog', 'Label', 'People and settings', 'Reference'];

    /** The chapter by the first digit of an article's number. */
    private const array CHAPTER_BY_DIGIT = [0 => 'Setup', 1 => 'Importing', 2 => 'Catalog', 3 => 'Accounting', 4 => 'People and settings'];

    /** Articles whose chapter differs from their first digit. */
    private const array CHAPTER_BY_NUMBER = [30 => 'Deals', 33 => 'Label', 35 => 'Deals'];

    /**
     * @return Collection<string, Article>
     */
    public function articles(): Collection
    {
        return once(fn (): Collection => collect(glob(base_path('docs/[0-9][0-9]-*.md')) ?: [])
            ->map(fn (string $path): array => self::parse(basename($path, '.md'), (string) file_get_contents($path)))
            ->sortBy(fn (array $article): array => [array_search($article['chapter'], self::CHAPTERS, true), $article['order']])
            ->keyBy('slug'));
    }

    /**
     * @return Article|null
     */
    public function find(string $slug): ?array
    {
        return $this->articles()->get($slug);
    }

    /**
     * The article as HTML. Raw HTML in a file is escaped, and links to
     * other articles point at the handbook instead of at the file.
     *
     * @param  Article  $article
     */
    public function html(array $article): string
    {
        $html = Str::markdown($article['body'], ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        $html = (string) preg_replace_callback(
            '/href="(\d\d-[\w-]+)\.md(#[\w-]*)?"/',
            fn (array $match): string => 'href="'.e(route('handbook', $match[1], false)).($match[2] ?? '').'"',
            $html,
        );

        // Headings get the anchor GitHub gives them, so "#good-to-know" works in both places.
        return (string) preg_replace_callback(
            '/<h([23])>(.*?)<\/h\1>/s',
            fn (array $match): string => '<h'.$match[1].' id="'.Str::slug(html_entity_decode(strip_tags($match[2]))).'">'.$match[2].'</h'.$match[1].'>',
            $html,
        );
    }

    /**
     * @return Article
     */
    public static function parse(string $slug, string $markdown): array
    {
        $order = (int) substr($slug, 0, 2);
        $title = preg_match('/\A\s*#\s+(.+?)\s*$/m', $markdown, $match) === 1 ? $match[1] : Str::headline(substr($slug, 3));

        return [
            'slug' => $slug,
            'title' => $title,
            'chapter' => self::CHAPTER_BY_NUMBER[$order] ?? self::CHAPTER_BY_DIGIT[intdiv($order, 10)] ?? 'Reference',
            'order' => $order,
            // The heading is shown by the page itself.
            'body' => trim((string) preg_replace('/\A\s*#\s+.+?\R/', '', $markdown, 1)),
        ];
    }
}
