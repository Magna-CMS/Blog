<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

/**
 * FAQPage JSON-LD for the FAQ block, so search results can render it as an
 * accordion.
 */
final class FaqSchema
{
    /**
     * Answer text is stripped to plain text and the whole payload is
     * json_encoded with tags hex-escaped, so it cannot break out of the
     * <script> element.
     *
     * @param  array<int, mixed>  $items
     */
    public static function script(array $items): string
    {
        $entities = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $question = trim(strip_tags((string) ($item['question'] ?? '')));
            $answer = trim(strip_tags((string) ($item['answer'] ?? '')));
            if ($question === '' || $answer === '') {
                continue;
            }
            $entities[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
        }

        if ($entities === []) {
            return '';
        }

        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

        return '<script type="application/ld+json">'.($json !== false ? $json : '').'</script>';
    }
}
