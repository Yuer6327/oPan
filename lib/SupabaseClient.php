<?php

declare(strict_types=1);

/**
 * Supabase REST API Client
 *
 * Uses the anon key for public read/write via PostgREST.
 */
final class SupabaseClient
{
    private string $url;
    private string $key;

    public function __construct()
    {
        $this->url = rtrim(env('PUBLIC_SUPABASE_URL'), '/');
        $this->key = env('PUBLIC_SUPABASE_ANON_KEY');
    }

    /**
     * SELECT rows from a table.
     * @param string $table Table name
     * @param array  $params Query params (e.g. ['select' => '*', 'order' => 'created_at.desc'])
     * @return array Array of row objects
     */
    public function select(string $table, array $params = []): array
    {
        $query = http_build_query($params);
        $url = "{$this->url}/rest/v1/{$table}" . ($query ? "?{$query}" : '');

        $res = httpRequest('GET', $url, [
            'headers' => [
                "apikey: {$this->key}",
                "Authorization: Bearer {$this->key}",
                'Accept: application/json',
            ],
            'timeout' => 10,
        ]);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException("Supabase SELECT failed (HTTP {$res['status']}): " . ($res['raw'] ?? ''));
        }

        return is_array($res['body']) ? $res['body'] : [];
    }

    /**
     * Upsert (INSERT or UPDATE) a row.
     * @param string $table Table name
     * @param array  $data Row data
     * @param string $onConflict Column(s) for conflict resolution
     * @return array The upserted row
     */
    public function upsert(string $table, array $data, string $onConflict = 'file_path'): array
    {
        $url = "{$this->url}/rest/v1/{$table}";

        $res = httpRequest('POST', $url, [
            'headers' => [
                "apikey: {$this->key}",
                "Authorization: Bearer {$this->key}",
                'Content-Type: application/json',
                'Accept: application/json',
                'Prefer: resolution=merge-duplicates,return=representation',
            ],
            'body'    => json_encode($data),
            'timeout' => 10,
        ]);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException("Supabase UPSERT failed (HTTP {$res['status']}): " . ($res['raw'] ?? ''));
        }

        $body = $res['body'];
        return is_array($body) ? (isset($body[0]) ? $body[0] : $body) : [];
    }

    /**
     * Update rows matching a filter.
     * @param string $table Table name
     * @param array  $data Fields to update
     * @param string $filter PostgREST filter (e.g. 'file_path=eq./oPan/file.txt')
     * @return array Updated rows
     */
    public function update(string $table, array $data, string $filter): array
    {
        $url = "{$this->url}/rest/v1/{$table}?{$filter}";

        $res = httpRequest('PATCH', $url, [
            'headers' => [
                "apikey: {$this->key}",
                "Authorization: Bearer {$this->key}",
                'Content-Type: application/json',
                'Accept: application/json',
                'Prefer: return=representation',
            ],
            'body'    => json_encode($data),
            'timeout' => 10,
        ]);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException("Supabase UPDATE failed (HTTP {$res['status']}): " . ($res['raw'] ?? ''));
        }

        $body = $res['body'];
        return is_array($body) ? $body : [];
    }
}
