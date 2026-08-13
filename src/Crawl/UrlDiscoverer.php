<?php

namespace Awais\RagChat\Crawl;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;

/**
 * Discovers the pages of a site that should be ingested.
 *
 * Sitemap-first: if {origin}/sitemap.xml (or a sitemap index pointing at
 * sub-sitemaps) resolves, its URLs are used. Otherwise it falls back to
 * breadth-first link following from the seed URL, staying on the same host
 * and respecting the configured page/depth limits.
 *
 * Only http/https URLs on the seed host are ever returned.
 */
class UrlDiscoverer
{
    /**
     * File extensions never worth ingesting as pages.
     *
     * @var string[]
     */
    protected const SKIP_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'css', 'js', 'json', 'xml', 'zip', 'rar', '7z', 'tar', 'gz',
        'mp3', 'mp4', 'webm', 'avi', 'mov', 'pdf', 'doc', 'docx',
    ];

    /**
     * Discover crawlable URLs for the given seed URL.
     *
     * @return list<string>
     */
    public function discover(string $seedUrl, array $options = []): array
    {
        $maxPages = (int) ($options['max_pages'] ?? config('rag-chat.crawl.max_pages', 50));
        $maxDepth = (int) ($options['max_depth'] ?? config('rag-chat.crawl.max_depth', 3));

        $seed = $this->normalizeUrl($seedUrl);

        if ($seed === null) {
            return [];
        }

        $sitemap = $this->fromSitemap($seed, $maxPages);

        if ($sitemap !== []) {
            return $sitemap;
        }

        return $this->fromLinks($seed, $maxPages, $maxDepth);
    }

    /**
     * Prefer URLs advertised in the site's sitemap(s).
     *
     * @return list<string>
     */
    protected function fromSitemap(string $seed, int $maxPages): array
    {
        $urls = [];

        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->userAgent(),
            ])->timeout($this->timeout())->get($this->sitemapUrl($seed));
        } catch (\Throwable) {
            return [];
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        // Some servers serve sitemaps as text/plain or omit the header; only
        // bail when the type clearly rules out XML.
        if (! $response->successful() || ($contentType !== '' && ! str_contains($contentType, 'xml') && ! str_contains($contentType, 'text'))) {
            return [];
        }

        $document = $this->loadXml((string) $response->body());

        if ($document === null) {
            return [];
        }

        $xpath = new DOMXPath($document);

        // A sitemap index (<sitemapindex>) lists <sitemap><loc> entries that
        // point at real sitemaps; follow them, bounded by the page limit.
        foreach ($xpath->query('//*[local-name()="sitemap"]/*[local-name()="loc"]') as $node) {
            $loc = trim((string) $node->textContent);

            if ($loc === '' || count($urls) >= $maxPages) {
                continue;
            }

            $childUrls = $this->childSitemapUrls($loc);

            foreach ($childUrls as $childUrl) {
                $urls[] = $childUrl;

                if (count($urls) >= $maxPages) {
                    break 2;
                }
            }
        }

        // A plain <urlset> lists <url><loc> entries directly.
        foreach ($xpath->query('//*[local-name()="url"]/*[local-name()="loc"]') as $node) {
            $urls[] = trim((string) $node->textContent);

            if (count($urls) >= $maxPages) {
                break;
            }
        }

        $urls = array_values(array_filter($urls, fn (?string $url) => $this->normalizeUrl((string) $url) !== null));
        $urls = array_values(array_unique(array_map(fn (string $url) => (string) $this->normalizeUrl($url), $urls)));

        return array_slice($urls, 0, $maxPages);
    }

    /**
     * Fetch a child sitemap and return its page URLs.
     *
     * @return list<string>
     */
    protected function childSitemapUrls(string $sitemapUrl): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->userAgent(),
            ])->timeout($this->timeout())->get($sitemapUrl);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $document = $this->loadXml((string) $response->body());

        if ($document === null) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $urls = [];

        foreach ($xpath->query('//*[local-name()="url"]/*[local-name()="loc"]') as $node) {
            $urls[] = trim((string) $node->textContent);
        }

        return $urls;
    }

    /**
     * Breadth-first link following when no sitemap is available.
     *
     * @return list<string>
     */
    protected function fromLinks(string $seed, int $maxPages, int $maxDepth): array
    {
        $host = parse_url($seed, PHP_URL_HOST);
        $queue = [[$seed, 0]];
        $visited = [];
        $found = [];

        while ($queue !== [] && count($found) < $maxPages) {
            [$url, $depth] = array_shift($queue);

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;

            if ($depth > $maxDepth) {
                continue;
            }

            try {
                $response = Http::withHeaders([
                    'User-Agent' => $this->userAgent(),
                ])->timeout($this->timeout())->get($url);
            } catch (\Throwable) {
                continue;
            }

            $contentType = strtolower((string) $response->header('Content-Type'));

            if (! $response->successful() || ($contentType !== '' && ! str_contains($contentType, 'html') && ! str_contains($contentType, 'xhtml') && ! str_contains($contentType, 'text'))) {
                continue;
            }

            $found[] = $url;

            if (count($found) >= $maxPages || $depth === $maxDepth) {
                continue;
            }

            foreach ($this->extractLinks((string) $response->body(), $url) as $link) {
                $absolute = $this->normalizeUrl($this->resolve($link, $url));

                if ($absolute === null) {
                    continue;
                }

                $linkHost = parse_url($absolute, PHP_URL_HOST);

                if ($linkHost !== $host || isset($visited[$absolute]) || $this->shouldSkip($absolute)) {
                    continue;
                }

                $queue[] = [$absolute, $depth + 1];
            }
        }

        return $found;
    }

    /**
     * All <a href> values from an HTML document.
     *
     * @return list<string>
     */
    protected function extractLinks(string $html, string $baseUrl): array
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $links = [];

        foreach ($document->getElementsByTagName('a') as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }

            $links[] = $href;
        }

        return $links;
    }

    /**
     * Resolve a possibly-relative href against the page it was found on.
     */
    protected function resolve(string $href, string $baseUrl): string
    {
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $base = $scheme.'://'.$host.$port;
        $path = $parsed['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?? '/';

        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        if (str_starts_with($href, '/')) {
            return $base.$href;
        }

        return $base.$dir.$href;
    }

    /**
     * Normalize a URL: absolute http(s) only, strip fragments, collapse slashes.
     */
    protected function normalizeUrl(string $url): ?string
    {
        $url = trim($url);

        if (preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        $url = strtok($url, '#');

        if ($url === false || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $path = preg_replace('#/{2,}#', '/', $path) ?? '/';
        $query = isset($parts['query']) ? '?'.$this->cleanQuery($parts['query']) : '';

        return $scheme.'://'.$host.$port.$path.($query !== '?' ? $query : '');
    }

    /**
     * Skip URLs pointing at files we never want as pages.
     */
    protected function shouldSkip(string $url): bool
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($extension, static::SKIP_EXTENSIONS, true);
    }

    protected function sitemapUrl(string $seed): string
    {
        $parsed = parse_url($seed);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        return $scheme.'://'.$host.$port.'/sitemap.xml';
    }

    /**
     * Drop common tracking parameters so ?utm_source=x and ?utm_source=y do
     * not produce duplicate pages.
     */
    protected function cleanQuery(string $query): string
    {
        parse_str($query, $params);

        foreach (array_keys($params) as $key) {
            $normalized = strtolower((string) $key);

            if (str_starts_with($normalized, 'utm_') || in_array($normalized, ['fbclid', 'gclid'], true)) {
                unset($params[$key]);
            }
        }

        return http_build_query($params);
    }

    protected function loadXml(string $xml): ?DOMDocument
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);

        if (! $document->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING)) {
            libxml_clear_errors();

            return null;
        }

        libxml_clear_errors();

        return $document;
    }

    protected function userAgent(): string
    {
        return (string) config('rag-chat.crawl.user_agent', 'RagChatCrawler/1.0 (+https://github.com/Awais-Koder/rag-chat)');
    }

    protected function timeout(): int
    {
        return (int) config('rag-chat.crawl.timeout', 15);
    }
}
