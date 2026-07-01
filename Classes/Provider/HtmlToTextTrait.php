<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Provider;

/**
 * Converts RTE/CType HTML stored in tt_content (or tx_news bodytext) into clean
 * plain text suitable for embedding. Shared by all database-backed providers.
 */
trait HtmlToTextTrait
{
    /**
     * Converts RTE HTML (tags + entities) into clean plain text for embedding.
     */
    protected function htmlToText(string $html): string
    {
        // 1. Turn block-level tags / line breaks into real newlines.
        //    str_ireplace is case-insensitive, covering the common variants.
        $html = str_ireplace(
            [
                '<br>',
                '<br/>',
                '<br />',
                '</p>',
                '</h1>',
                '</h2>',
                '</h3>',
                '</h4>',
                '</h5>',
                '</h6>',
                '</li>',
                '</div>',
                '</tr>'
            ],
            "\n",
            $html
        );

        // 2. Strip all remaining tags.
        $text = strip_tags($html);

        // 3. Decode HTML entities (&nbsp; &amp; &rsquo; etc.).
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 4. Normalise tabs / carriage returns / non-breaking spaces to plain spaces.
        $text = str_replace(["\t", "\r", "\xC2\xA0"], ' ', $text);

        // 5. Clean line by line: collapse repeated spaces, drop empty lines,
        //    then re-join with blank lines into paragraphs.
        $cleanLines = [];
        foreach (explode("\n", $text) as $line) {
            // explode on spaces + filter empties = collapse runs of spaces into one.
            $words = array_filter(explode(' ', $line), fn($w) => $w !== '');
            $line  = implode(' ', $words);
            if ($line !== '') {
                $cleanLines[] = $line;
            }
        }

        return implode("\n\n", $cleanLines);
    }

    /**
     * Removes call-to-action button links (e.g. "See our work", "View case studies")
     * before converting to text. These are navigation noise, not readable content.
     */
    protected function stripCtaButtons(string $html): string
    {
        return preg_replace('/<a\b[^>]*class="[^"]*\bbtn\b[^"]*"[^>]*>.*?<\/a>/si', '', $html) ?? $html;
    }
}
