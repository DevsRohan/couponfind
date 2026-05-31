<?php

declare(strict_types=1);

namespace CouponFind\Services;

use CouponFind\Core\Env;
use CouponFind\Support\Http;

/**
 * Thin Meilisearch REST client. The Python engine owns index population; PHP
 * uses this for typo-tolerant, ranked reads during search. All methods are
 * defensive — a Meilisearch outage causes SearchService to fall back to MySQL.
 */
final class Meilisearch
{
    private string $host;
    private string $key;
    private string $index;

    public function __construct()
    {
        $this->host = rtrim(Env::string('MEILI_HOST', 'http://127.0.0.1:7700'), '/');
        $this->key = Env::string('MEILI_MASTER_KEY', '');
        $this->index = Env::string('MEILI_INDEX', 'coupons');
    }

    private function headers(): array
    {
        $h = ['Content-Type' => 'application/json'];
        if ($this->key !== '') {
            $h['Authorization'] = 'Bearer ' . $this->key;
        }
        return $h;
    }

    public function isHealthy(): bool
    {
        $res = Http::getJson($this->host . '/health', $this->headers(), 3);
        return $res['ok'] && (($res['json']['status'] ?? '') === 'available');
    }

    /**
     * @param array $opts filter (string), sort (array), limit (int)
     * @return array{hits:array, estimatedTotalHits:int, processingTimeMs:int}|null
     */
    public function search(string $query, array $opts = []): ?array
    {
        $payload = [
            'q'     => $query,
            'limit' => $opts['limit'] ?? 40,
        ];
        if (!empty($opts['filter'])) {
            $payload['filter'] = $opts['filter'];
        }
        if (!empty($opts['sort'])) {
            $payload['sort'] = $opts['sort'];
        }
        if (!empty($opts['attributesToHighlight'])) {
            $payload['attributesToHighlight'] = $opts['attributesToHighlight'];
        }

        $res = Http::postJson("{$this->host}/indexes/{$this->index}/search", $payload, $this->headers(), 5);
        if (!$res['ok'] || !is_array($res['json'])) {
            return null;
        }
        return [
            'hits'               => $res['json']['hits'] ?? [],
            'estimatedTotalHits' => (int) ($res['json']['estimatedTotalHits'] ?? 0),
            'processingTimeMs'   => (int) ($res['json']['processingTimeMs'] ?? 0),
        ];
    }

    /** Create/configure index with the searchable + filterable + sortable attrs. */
    public function ensureIndex(): bool
    {
        // Create index (idempotent — ignores "already exists").
        Http::postJson("{$this->host}/indexes", ['uid' => $this->index, 'primaryKey' => 'id'], $this->headers(), 5);

        $settings = [
            'searchableAttributes' => ['title', 'merchant_name', 'code', 'description', 'category'],
            'filterableAttributes' => ['merchant_id', 'merchant_slug', 'status', 'discount_value', 'type', 'category'],
            'sortableAttributes'   => ['score', 'discount_value', 'valid_until_ts'],
            'rankingRules'         => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'score:desc'],
            'typoTolerance'        => ['enabled' => true, 'minWordSizeForTypos' => ['oneTypo' => 3, 'twoTypos' => 6]],
        ];
        $res = Http::request(
            'PATCH',
            "{$this->host}/indexes/{$this->index}/settings",
            $this->headers(),
            json_encode($settings, JSON_UNESCAPED_SLASHES),
            5
        );
        return $res['ok'];
    }

    public function indexDocuments(array $documents): bool
    {
        if ($documents === []) {
            return true;
        }
        $res = Http::request(
            'POST',
            "{$this->host}/indexes/{$this->index}/documents",
            $this->headers(),
            json_encode(array_values($documents), JSON_UNESCAPED_SLASHES),
            10
        );
        return $res['ok'];
    }

    public function deleteDocument(int $id): bool
    {
        $res = Http::request('DELETE', "{$this->host}/indexes/{$this->index}/documents/{$id}", $this->headers(), 5);
        return $res['ok'];
    }

    public function stats(): array
    {
        $res = Http::getJson("{$this->host}/indexes/{$this->index}/stats", $this->headers(), 3);
        return is_array($res['json']) ? $res['json'] : [];
    }
}
