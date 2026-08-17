<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the query parameters accepted by the delivery post index. Everything
 * a headless frontend can send to filter, search, sort and paginate is declared
 * (and bounded) here — unknown params are simply ignored, never trusted.
 */
class PostIndexRequest extends FormRequest
{
    /** Delivery routes are already gated by the `magna.api` token middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sort keys the client may request, mapped to a [column, direction] pair.
     * A closed allowlist keeps arbitrary column names out of the ORDER BY.
     *
     * @var array<string, array{0: string, 1: 'asc'|'desc'}>
     */
    public const SORTS = [
        '-published_at' => ['published_at', 'desc'],
        'published_at' => ['published_at', 'asc'],
        'title' => ['title', 'asc'],
        '-title' => ['title', 'desc'],
        '-views' => ['views', 'desc'],
        'views' => ['views', 'asc'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'category' => ['sometimes', 'string', 'max:190'],
            'tag' => ['sometimes', 'string', 'max:190'],
            'series' => ['sometimes', 'string', 'max:190'],
            'locale' => ['sometimes', 'string', 'max:12'],
            'author' => ['sometimes', 'string', 'max:40'],
            'year' => ['nullable', 'integer', 'min:1970', 'max:2100', 'required_with:month'],
            'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'featured' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', array_keys(self::SORTS))],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * The resolved sort as [column, direction], defaulting to newest first.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    public function sort(): array
    {
        return self::SORTS[$this->string('sort')->toString()] ?? self::SORTS['-published_at'];
    }
}
