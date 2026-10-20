<?php

namespace App\Http\Requests\Square;

use App\Enums\Square\PostAudience;
use App\Enums\Square\PostCommentsBy;
use App\Enums\Square\PostMediaKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:2200'],
            'caption' => ['nullable', 'string', 'max:2200'], // Alias supporté
            'audience' => ['nullable', 'string', Rule::in(PostAudience::values())],
            'comments_by' => ['nullable', 'string', Rule::in(PostCommentsBy::values())],
            'commentsBy' => ['nullable', 'string', Rule::in(PostCommentsBy::values())], // Alias camelCase
            'comments_closed' => ['nullable', 'boolean'],
            'commentsClosed' => ['nullable', 'boolean'], // Alias camelCase
            'allow_share' => ['nullable', 'boolean'],
            'allowShare' => ['nullable', 'boolean'], // Alias camelCase
            'hide_likes' => ['nullable', 'boolean'],
            'hideLikes' => ['nullable', 'boolean'], // Alias camelCase
            'as_page_id' => ['nullable', 'integer'],
            'asPageId' => ['nullable', 'integer'], // Alias camelCase
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'idempotencyKey' => ['nullable', 'string', 'max:100'], // Alias camelCase

            // Lieu descriptif
            'place' => ['nullable', 'array'],
            'place.label' => ['required_with:place', 'string', 'max:255'],
            'place.detail' => ['nullable', 'string', 'max:255'],
            'place.city' => ['nullable', 'string', 'max:255'],
            'place.neighbourhood' => ['nullable', 'string', 'max:255'],

            // Carrousel de médias (1..10)
            'media' => ['nullable', 'array', 'max:10'],
            'media.*.url' => ['required_with:media', 'string', 'max:2048'],
            'media.*.type' => ['nullable', 'string', Rule::in(PostMediaKind::values())],
            'media.*.mime_type' => ['nullable', 'string', 'max:100'],
            'media.*.file_size' => ['nullable', 'integer', 'min:0'],
            'media.*.width' => ['nullable', 'integer', 'min:0'],
            'media.*.height' => ['nullable', 'integer', 'min:0'],
            'media.*.duration' => ['nullable', 'integer', 'min:0'],
            'media.*.display_order' => ['nullable', 'integer', 'min:0', 'max:9'],
            'media.*.edit_metadata' => ['nullable', 'array'],

            // Tags de personnes identifiées
            'tags' => ['nullable', 'array'],
            'tags.*.account_id' => ['nullable', 'integer'],
            'tags.*.accountId' => ['nullable', 'integer'],
            'tags.*.x' => ['required_with:tags', 'numeric', 'between:0,1'],
            'tags.*.y' => ['required_with:tags', 'numeric', 'between:0,1'],
            'tags.*.media_index' => ['nullable', 'integer', 'min:0', 'max:9'],
            'tags.*.mediaIndex' => ['nullable', 'integer', 'min:0', 'max:9'],
        ];
    }

    /**
     * Valide qu'au moins du texte ou au moins un média est présent.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $body = $this->input('body') ?? $this->input('caption');
            $media = $this->input('media', []);

            if (empty(trim((string) $body)) && empty($media)) {
                $validator->errors()->add('body', 'Une publication doit contenir au moins un texte ou au moins un média.');
            }

            foreach ($media as $index => $item) {
                $url = trim((string) ($item['url'] ?? ''));
                if (! empty($url) && ! $this->isAllowedMediaUrl($url)) {
                    $validator->errors()->add("media.{$index}.url", 'L\'URL du média doit provenir du service de téléversement BANGO (media/upload).');
                }
            }
        });
    }

    protected function isAllowedMediaUrl(string $url): bool
    {
        // Chemins relatifs autorisés issus de notre stockage local
        if (str_starts_with($url, '/storage/') || str_starts_with($url, 'storage/') || str_starts_with($url, '/square/') || str_starts_with($url, 'square/')) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return false;
        }

        $allowedHosts = [
            parse_url(config('app.url'), PHP_URL_HOST),
            'storage.bango.cm',
            'bango.cm',
            'localhost',
            '127.0.0.1',
        ];

        $s3Endpoint = config('filesystems.disks.s3.endpoint');
        if ($s3Endpoint) {
            $allowedHosts[] = parse_url($s3Endpoint, PHP_URL_HOST);
        }

        foreach ($allowedHosts as $allowedHost) {
            if ($allowedHost && ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost) || str_contains($host, 'bango'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise les données en tenant compte des alias camelCase/snake_case.
     *
     * @return array<string, mixed>
     */
    public function normalizedData(): array
    {
        $data = $this->validated();

        $body = $data['body'] ?? $data['caption'] ?? null;
        $commentsBy = $data['comments_by'] ?? $data['commentsBy'] ?? PostCommentsBy::Everyone->value;
        $commentsClosed = $data['comments_closed'] ?? $data['commentsClosed'] ?? false;
        $allowShare = $data['allow_share'] ?? $data['allowShare'] ?? true;
        $hideLikes = $data['hide_likes'] ?? $data['hideLikes'] ?? false;
        $asPageId = $data['as_page_id'] ?? $data['asPageId'] ?? null;
        $idempotencyKey = $data['idempotency_key'] ?? $data['idempotencyKey'] ?? null;

        // Normalisation des tags
        $rawTags = $data['tags'] ?? [];
        $normalizedTags = [];
        foreach ($rawTags as $tag) {
            $normalizedTags[] = [
                'account_id' => $tag['account_id'] ?? $tag['accountId'] ?? 0,
                'x' => (float) $tag['x'],
                'y' => (float) $tag['y'],
                'media_index' => (int) ($tag['media_index'] ?? $tag['mediaIndex'] ?? 0),
            ];
        }

        return [
            'body' => $body,
            'audience' => $data['audience'] ?? PostAudience::Public->value,
            'comments_by' => $commentsBy,
            'comments_closed' => (bool) $commentsClosed,
            'allow_share' => (bool) $allowShare,
            'hide_likes' => (bool) $hideLikes,
            'as_page_id' => $asPageId ? (int) $asPageId : null,
            'place' => $data['place'] ?? null,
            'media' => $data['media'] ?? [],
            'tags' => $normalizedTags,
            'idempotency_key' => $idempotencyKey,
        ];
    }
}
