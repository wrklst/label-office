<?php

namespace App\Support;

/**
 * The example agreement texts that ship with the application. They are
 * illustrations, not legal documents: nothing is ever sent from one, a
 * label can only copy one into its own templates and answer for it.
 */
class AgreementExamples
{
    /**
     * What every example is shown with.
     */
    public const string NOTICE = 'Example only, not legal advice and without any warranty. It was not written or reviewed by a lawyer and may be wrong, incomplete or unenforceable. It is drafted with German law and a German GmbH as the label in mind. Do not use it before a qualified lawyer has reviewed and adapted it for you.';

    /**
     * @return array<string, array{key: string, name: string, body: string}> By key, the file name without its ending.
     */
    public static function all(): array
    {
        $examples = [];

        foreach (glob(resource_path('agreement-examples/*.md')) ?: [] as $file) {
            // Each file opens with the notice as a comment, then a "# Name" line, then the text.
            $text = trim((string) preg_replace('/^<!--.*?-->/s', '', (string) file_get_contents($file)));
            [$heading, $body] = explode("\n", $text, 2);
            $key = basename($file, '.md');

            $examples[$key] = ['key' => $key, 'name' => ltrim($heading, '# '), 'body' => trim($body)];
        }

        return $examples;
    }
}
