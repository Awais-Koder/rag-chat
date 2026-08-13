<?php

namespace Awais\RagChat\Crawl;

use Awais\RagChat\Models\RagCrawlRun;
use Awais\RagChat\Rag\Ingestor;
use Awais\RagChat\Support\RagProjectScope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Crawls a website and ingests its pages into the local RAG store.
 *
 * Discovery is sitemap-first (UrlDiscoverer), each page is reduced to plain
 * text via HtmlExtractor, and the text is handed to the existing Ingestor so
 * chunking, embedding, and dedup behave identically to file uploads. Every
 * ingested document carries safe citation metadata: the page URL (source_url)
 * and document_type "web", so chatbot citations link back to the source page.
 */
class SiteCrawler
{
    public function __construct(
        protected UrlDiscoverer $discoverer,
        protected HtmlExtractor $extractor,
        protected Ingestor $ingestor,
    ) {}

    /**
     * Crawl a site and ingest every discovered page.
     *
     * When a $run is provided, per-page counters are persisted on it after
     * every page so the knowledge UI can show live progress. The caller owns
     * the run's status lifecycle (running → completed | failed).
     *
     * @param  array<string, mixed>  $options  max_pages, max_depth, project_id
     * @return array{urls: list<string>, ingested: int, skipped: list<string>, failed: list<string>}
     */
    public function crawl(string $seedUrl, array $options = [], ?RagCrawlRun $run = null): array
    {
        $urls = $this->discoverer->discover($seedUrl, $options);

        $ingested = [];
        $skipped = [];
        $failed = [];

        if ($run !== null) {
            $run->discovered = count($urls);
            $run->save();
        }

        foreach ($urls as $url) {
            try {
                // Optional defense-in-depth: block requests to private/loopback
                // ranges (disabled by default — enable via rag-chat.crawl.
                // block_private_ips). Redirects are still followed by the HTTP
                // client, so this is not a complete SSRF boundary on its own.
                if (config('rag-chat.crawl.block_private_ips', false) && $this->isPrivateUrl($url)) {
                    $failed[] = $url;
                    $this->persistProgress($run, $ingested, $skipped, $failed);

                    continue;
                }

                $response = Http::withHeaders([
                    'User-Agent' => $this->userAgent(),
                ])->timeout($this->timeout())->connectTimeout($this->connectTimeout())->get($url);

                $contentType = strtolower((string) $response->header('Content-Type'));

                if ($response->failed() || ($contentType !== '' && ! str_contains($contentType, 'html') && ! str_contains($contentType, 'xhtml') && ! str_contains($contentType, 'text'))) {
                    $failed[] = $url;
                    $this->persistProgress($run, $ingested, $skipped, $failed);

                    continue;
                }

                $content = $response->body();

                if (strlen($content) > $this->maxPageBytes()) {
                    $skipped[] = $url;
                    $this->persistProgress($run, $ingested, $skipped, $failed);

                    continue;
                }

                $extracted = $this->extractor->extract($content);

                if (strlen($extracted['text']) < $this->minTextChars()) {
                    $skipped[] = $url;
                    $this->persistProgress($run, $ingested, $skipped, $failed);

                    continue;
                }

                $this->ingestor->ingestText(
                    text: $extracted['text'],
                    source: $url,
                    title: $extracted['title'] ?? $this->titleFromUrl($url),
                    meta: [
                        'project_id' => $options['project_id'] ?? RagProjectScope::get(),
                        'source_url' => $url,
                        'document_type' => 'web',
                        'crawled_at' => now()->toISOString(),
                    ],
                );

                $ingested[] = $url;
            } catch (ConnectionException|RequestException) {
                $failed[] = $url;
            } catch (\Throwable $exception) {
                report($exception);
                $failed[] = $url;
            }

            $this->persistProgress($run, $ingested, $skipped, $failed);
        }

        return [
            'urls' => $urls,
            'ingested' => $ingested,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * Persist the run's live counters after each page is processed.
     *
     * @param  list<string>  $ingested
     * @param  list<string>  $skipped
     * @param  list<string>  $failed
     */
    protected function persistProgress(?RagCrawlRun $run, array $ingested, array $skipped, array $failed): void
    {
        if ($run === null) {
            return;
        }

        $run->ingested = count($ingested);
        $run->skipped = count($skipped);
        $run->failed = count($failed);
        $run->failed_urls = $failed !== [] ? $failed : null;
        $run->save();
    }

    /**
     * Best-effort private-address check (only used when block_private_ips is on).
     */
    protected function isPrivateUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return true;
        }

        // Literal IPs.
        $ip = filter_var($host, FILTER_VALIDATE_IP);

        if ($ip === false) {
            $resolved = gethostbynamel($host);
            $ip = is_array($resolved) && $resolved !== [] ? $resolved[0] : null;
        }

        return $ip === null
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Derive a human title from a URL when the page has no <title>.
     */
    protected function titleFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = array_filter(explode('/', $path));

        if ($segments === []) {
            $host = (string) parse_url($url, PHP_URL_HOST);

            return str_contains($host, '.') ? $host : $url;
        }

        return str_replace('-', ' ', ucwords((string) end($segments), '-'));
    }

    protected function userAgent(): string
    {
        return (string) config('rag-chat.crawl.user_agent', 'RagChatCrawler/1.0 (+https://github.com/Awais-Koder/rag-chat)');
    }

    protected function timeout(): int
    {
        return (int) config('rag-chat.crawl.timeout', 15);
    }

    protected function connectTimeout(): int
    {
        return (int) config('rag-chat.crawl.connect_timeout', 5);
    }

    protected function maxPageBytes(): int
    {
        return (int) config('rag-chat.crawl.max_page_bytes', 2_000_000);
    }

    protected function minTextChars(): int
    {
        return (int) config('rag-chat.crawl.min_text_chars', 40);
    }
}
