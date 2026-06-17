<?php

namespace App\Services;

use Illuminate\Http\Request;

class GoicFollowInputService
{
    /**
     * @return array{uri?: string, reason?: string, error?: string}
     */
    public function resolveSourceGoicUri(Request $request, array $validated): array
    {
        if ($request->has('bron_goic_uris')) {
            return [
                'error' => 'Gebruik exact één bron_goic_uri per request.',
                'reason' => 'multiple_input_field',
            ];
        }

        if (is_array($request->input('bron_goic_uri'))) {
            return [
                'error' => 'bron_goic_uri mag geen lijst zijn.',
                'reason' => 'bron_goic_uri_array',
            ];
        }

        $uri = trim((string) ($validated['bron_goic_uri'] ?? ''));
        if ($uri === '' || preg_match('/[\s,;]/', $uri)) {
            return [
                'error' => 'Gebruik exact één geldige bron_goic_uri.',
                'reason' => 'invalid_single_uri_syntax',
            ];
        }

        if (! $this->isHttpUri($uri)) {
            return [
                'error' => 'Ongeldige bron GOIC URI.',
                'reason' => 'invalid_uri_format',
            ];
        }

        return ['uri' => $uri];
    }

    /**
     * @return array{uri?: string, reason?: string, error?: string}
     */
    public function resolveAssociationUri(array $validated): array
    {
        $uri = trim((string) ($validated['association_uri'] ?? ''));
        if ($uri === '' || ! $this->isHttpUri($uri)) {
            return [
                'error' => 'Ongeldige association URI.',
                'reason' => 'invalid_uri_format',
            ];
        }

        return ['uri' => $uri];
    }

    private function isHttpUri(string $uri): bool
    {
        return preg_match('/^https?:\/\/[^\s<>"\']+$/', $uri) === 1;
    }
}
