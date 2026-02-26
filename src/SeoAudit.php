<?php

declare(strict_types=1);

namespace URLCV\SeoAudit;

/**
 * SEO and AI/LLM readiness audit. Fetches key URLs and runs 22 checks.
 *
 * @return array<string, mixed>
 */
final class SeoAudit
{
    private const TIMEOUT = 5;

    private const AI_CRAWLERS = ['GPTBot', 'ClaudeBot', 'Google-Extended', 'PerplexityBot', 'Applebot-Extended'];

    /**
     * Run full audit for a domain. Returns structured result for the output Blade template.
     *
     * @return array{domain: string, error: string|null, score: int, grade: string, sections: array, recommendations: string[]}
     */
    public function check(string $domain): array
    {
        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return [
                'domain'          => '',
                'error'           => 'Please enter a domain (e.g. example.com).',
                'score'           => 0,
                'grade'           => 'F',
                'sections'        => [],
                'recommendations'  => [],
            ];
        }

        $baseUrl = 'https://' . $domain;
        $fetched = $this->fetchAll($baseUrl, $domain);

        $crawlability = $this->checkCrawlability($fetched, $baseUrl);
        $onPage = $this->checkOnPage($fetched);
        $aiLlm = $this->checkAiLlm($fetched, $baseUrl);
        $security = $this->checkSecurityHeaders($fetched);

        $sections = [
            'crawlability' => $crawlability,
            'on_page'      => $onPage,
            'ai_llm'       => $aiLlm,
            'security'     => $security,
        ];

        $score = $this->computeScore($sections);
        $grade = $this->scoreToGrade($score);
        $recommendations = $this->buildRecommendations($sections);

        return [
            'domain'         => $domain,
            'error'          => null,
            'score'          => $score,
            'grade'          => $grade,
            'sections'       => $sections,
            'recommendations' => $recommendations,
        ];
    }

    public function normalizeDomain(string $input): string
    {
        $s = trim($input);
        if ($s === '') {
            return '';
        }
        $s = preg_replace('#^https?://#i', '', $s);
        $s = rtrim($s, '/');
        if (str_contains($s, '/')) {
            $s = explode('/', $s, 2)[0];
        }
        return strtolower($s);
    }

    /**
     * @return array{home: array{body: string|null, headers: array, url: string|null, ttfb_ms: int|null}, robots: string|null, sitemap: string|null, llms_txt: string|null, llms_full_txt: string|null, security_txt: string|null}
     */
    private function fetchAll(string $baseUrl, string $domain): array
    {
        $home = $this->fetchWithTiming($baseUrl . '/');
        $robots = $this->fetchBody($baseUrl . '/robots.txt');
        $sitemap = $this->fetchBody($baseUrl . '/sitemap.xml');
        $llmsTxt = $this->fetchBody($baseUrl . '/llms.txt');
        $llmsFullTxt = $this->fetchBody($baseUrl . '/llms-full.txt');
        $securityTxt = $this->fetchBody($baseUrl . '/.well-known/security.txt');

        return [
            'home'          => $home,
            'robots'        => $robots,
            'sitemap'       => $sitemap,
            'llms_txt'      => $llmsTxt,
            'llms_full_txt' => $llmsFullTxt,
            'security_txt'  => $securityTxt,
        ];
    }

    /**
     * @return array{body: string|null, headers: array<string, string>, url: string|null, ttfb_ms: int|null}
     */
    private function fetchWithTiming(string $url): array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => self::TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'ignore_errors'   => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $start = microtime(true);
        $headers = @get_headers($url, true, $ctx);
        $ttfbMs = (int) round((microtime(true) - $start) * 1000);

        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            $body = null;
        }

        $headerMap = [];
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $headerMap[strtolower($k)] = $v;
                }
            }
            if (isset($headers[0]) && is_string($headers[0])) {
                $headerMap['_status'] = $headers[0];
            }
        }

        $finalUrl = $url;
        if (isset($headerMap['location'])) {
            $loc = $headerMap['location'];
            $finalUrl = is_array($loc) ? end($loc) : $loc;
        }

        return [
            'body'    => $body,
            'headers' => $headerMap,
            'url'     => $finalUrl,
            'ttfb_ms' => $ttfbMs,
        ];
    }

    private function fetchBody(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => ['timeout' => self::TIMEOUT, 'follow_location' => 1, 'max_redirects' => 2, 'ignore_errors' => true],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body !== false ? $body : null;
    }

    /**
     * @param array{home: array, robots: string|null, sitemap: string|null, llms_txt: string|null, llms_full_txt: string|null, security_txt: string|null} $fetched
     * @return array<string, array{status: string, label: string, value?: string, fix?: string}>
     */
    private function checkCrawlability(array $fetched, string $baseUrl): array
    {
        $home = $fetched['home'];
        $robots = $fetched['robots'];
        $sitemap = $fetched['sitemap'];
        $url = $home['url'] ?? $baseUrl . '/';
        $ttfbMs = $home['ttfb_ms'] ?? null;

        $checks = [];

        $isHttps = str_starts_with($url, 'https://');
        $checks['https'] = [
            'status' => $isHttps ? 'pass' : 'fail',
            'label'  => 'HTTPS active',
            'value'  => $isHttps ? 'Yes' : 'No',
            'fix'    => $isHttps ? null : 'Enable HTTPS and redirect HTTP to HTTPS.',
        ];

        $hasRobots = $robots !== null && trim($robots) !== '';
        // Only fail when root is blocked (Disallow: / at EOL), not Disallow: /path/
        $blocksRoot = $hasRobots && preg_match('#Disallow:\s*/\s*$#m', $robots);
        $checks['robots_txt'] = [
            'status' => $blocksRoot ? 'fail' : ($hasRobots ? 'pass' : 'warn'),
            'label'  => 'robots.txt',
            'value'  => $hasRobots ? 'Present' : 'Missing',
            'fix'    => $blocksRoot ? 'Do not Disallow: / for all user-agents.' : ($hasRobots ? null : 'Add a robots.txt at the root of your site.'),
        ];

        // Accept both regular sitemaps (<url>) and sitemap index files (<sitemap> / <sitemapindex>)
        $hasSitemap = $sitemap !== null && (
            stripos($sitemap, '<url>') !== false ||
            stripos($sitemap, '<sitemap>') !== false ||
            stripos($sitemap, '<sitemapindex') !== false
        );
        if (!$hasSitemap && $hasRobots && preg_match('#Sitemap:\s*(\S+)#i', $robots, $m)) {
            $hasSitemap = true;
        }
        $checks['sitemap'] = [
            'status' => $hasSitemap ? 'pass' : 'fail',
            'label'  => 'XML sitemap',
            'value'  => $hasSitemap ? 'Present' : 'Missing',
            'fix'    => $hasSitemap ? null : 'Add sitemap.xml or reference it in robots.txt.',
        ];

        if ($ttfbMs !== null) {
            $ttfbStatus = $ttfbMs < 400 ? 'pass' : ($ttfbMs < 1000 ? 'warn' : 'fail');
            $checks['response_time'] = [
                'status' => $ttfbStatus,
                'label'  => 'Response time',
                'value'  => $ttfbMs . ' ms',
                'fix'    => $ttfbMs >= 1000 ? 'Improve server or CDN to get TTFB under 400 ms.' : null,
            ];
        } else {
            $checks['response_time'] = [
                'status' => 'warn',
                'label'  => 'Response time',
                'value'  => 'Unknown',
                'fix'    => 'Could not measure; check that the site is reachable.',
            ];
        }

        return $checks;
    }

    /**
     * @param array{home: array{body: string|null}} $fetched
     * @return array<string, array{status: string, label: string, value?: string, fix?: string}>
     */
    private function checkOnPage(array $fetched): array
    {
        $html = $fetched['home']['body'] ?? null;
        $checks = [];

        if ($html === null || $html === '') {
            foreach (['title', 'meta_description', 'canonical', 'viewport', 'h1', 'open_graph', 'twitter_card', 'structured_data', 'lang', 'favicon'] as $key) {
                $checks[$key] = ['status' => 'warn', 'label' => $key, 'value' => '—', 'fix' => 'Homepage could not be fetched.'];
            }
            return $checks;
        }

        $dom = new \DOMDocument();
        if (@$dom->loadHTML($html, \LIBXML_NOERROR) === false) {
            foreach (['title', 'meta_description', 'canonical', 'viewport', 'h1', 'open_graph', 'twitter_card', 'structured_data', 'lang', 'favicon'] as $key) {
                $checks[$key] = ['status' => 'warn', 'label' => $key, 'value' => '—', 'fix' => 'Could not parse HTML.'];
            }
            return $checks;
        }

        $xpath = new \DOMXPath($dom);

        $title = null;
        $titleNode = $xpath->query('//title')->item(0);
        if ($titleNode) {
            $title = trim($titleNode->textContent);
        }
        $titleLen = $title !== null ? strlen($title) : 0;
        $checks['title'] = [
            'status' => $titleLen >= 30 && $titleLen <= 60 ? 'pass' : ($titleLen > 0 ? 'warn' : 'fail'),
            'label'  => 'Title tag',
            'value'  => $title !== null ? $title : 'Missing',
            'fix'    => $title === null ? 'Add a <title> tag.' : ($titleLen < 30 || $titleLen > 60 ? 'Aim for 30–60 characters.' : null),
        ];

        $metaDesc = null;
        foreach ($xpath->query('//meta[@name="description"]') as $node) {
            $metaDesc = $node->getAttribute('content') ?? '';
            break;
        }
        $descLen = $metaDesc !== null ? strlen($metaDesc) : 0;
        $checks['meta_description'] = [
            'status' => $descLen >= 120 && $descLen <= 160 ? 'pass' : ($descLen > 0 ? 'warn' : 'fail'),
            'label'  => 'Meta description',
            'value'  => $metaDesc !== null && $metaDesc !== '' ? $metaDesc : 'Missing',
            'fix'    => $metaDesc === null || $metaDesc === '' ? 'Add <meta name="description" content="...">.' : ($descLen < 120 || $descLen > 160 ? 'Aim for 120–160 characters.' : null),
        ];

        $canonical = null;
        foreach ($xpath->query('//link[@rel="canonical"]') as $node) {
            $canonical = $node->getAttribute('href') ?? '';
            break;
        }
        $checks['canonical'] = [
            'status' => $canonical !== null && $canonical !== '' ? 'pass' : 'warn',
            'label'  => 'Canonical URL',
            'value'  => $canonical ?: 'Missing',
            'fix'    => $canonical ? null : 'Add <link rel="canonical" href="...">.',
        ];

        $viewport = null;
        foreach ($xpath->query('//meta[@name="viewport"]') as $node) {
            $viewport = $node->getAttribute('content') ?? '';
            break;
        }
        $hasViewport = $viewport !== null && stripos($viewport, 'width') !== false;
        $checks['viewport'] = [
            'status' => $hasViewport ? 'pass' : 'fail',
            'label'  => 'Viewport meta',
            'value'  => $hasViewport ? 'Present' : 'Missing',
            'fix'    => $hasViewport ? null : 'Add <meta name="viewport" content="width=device-width, initial-scale=1">.',
        ];

        $h1Count = $xpath->query('//h1')->length;
        $checks['h1'] = [
            'status' => $h1Count === 1 ? 'pass' : ($h1Count > 1 ? 'warn' : 'fail'),
            'label'  => 'H1 tag',
            'value'  => $h1Count === 1 ? 'One H1' : ($h1Count . ' H1(s)'),
            'fix'    => $h1Count === 0 ? 'Add exactly one <h1> on the page.' : ($h1Count > 1 ? 'Use a single H1 per page.' : null),
        ];

        $ogTitle = $this->metaContent($xpath, 'og:title');
        $ogDesc = $this->metaContent($xpath, 'og:description');
        $ogImage = $this->metaContent($xpath, 'og:image');
        $ogUrl = $this->metaContent($xpath, 'og:url');
        $ogOk = $ogTitle && $ogDesc && $ogImage && $ogUrl;
        $checks['open_graph'] = [
            'status' => $ogOk ? 'pass' : 'warn',
            'label'  => 'Open Graph',
            'value'  => $ogOk ? 'Present' : 'Incomplete',
            'fix'    => $ogOk ? null : 'Add og:title, og:description, og:image, og:url.',
        ];

        $twCard = $this->metaContent($xpath, 'twitter:card');
        $checks['twitter_card'] = [
            'status' => $twCard ? 'pass' : 'warn',
            'label'  => 'Twitter/X card',
            'value'  => $twCard ? 'Present' : 'Missing',
            'fix'    => $twCard ? null : 'Add <meta name="twitter:card" content="summary_large_image"> (or similar).',
        ];

        $jsonLd = $xpath->query('//script[@type="application/ld+json"]');
        $hasJsonLd = $jsonLd->length > 0;
        $checks['structured_data'] = [
            'status' => $hasJsonLd ? 'pass' : 'warn',
            'label'  => 'Structured data',
            'value'  => $hasJsonLd ? 'JSON-LD present' : 'Missing',
            'fix'    => $hasJsonLd ? null : 'Add JSON-LD (e.g. Organization, WebSite) in <script type="application/ld+json">.',
        ];

        $lang = null;
        $htmlEl = $xpath->query('//html')->item(0);
        if ($htmlEl) {
            $lang = $htmlEl->getAttribute('lang');
        }
        $checks['lang'] = [
            'status' => $lang !== null && $lang !== '' ? 'pass' : 'warn',
            'label'  => 'Language attribute',
            'value'  => $lang ?: 'Missing',
            'fix'    => $lang ? null : 'Add <html lang="en"> (or your language code).',
        ];

        // Match rel="icon", rel="shortcut icon", rel="apple-touch-icon", etc.
        $favicon = $xpath->query('//link[contains(@rel,"icon")]')->length > 0;
        if (!$favicon && $html !== null) {
            $favicon = stripos($html, 'favicon.ico') !== false;
        }
        $checks['favicon'] = [
            'status' => $favicon ? 'pass' : 'warn',
            'label'  => 'Favicon',
            'value'  => $favicon ? 'Present' : 'Missing',
            'fix'    => $favicon ? null : 'Add <link rel="icon" href="/favicon.ico"> or equivalent.',
        ];

        return $checks;
    }

    private function metaContent(\DOMXPath $xpath, string $property): ?string
    {
        $prop = addslashes($property);
        // Use exact attribute match (=) rather than contains() to avoid false matches
        // e.g. "og:image" must not match "og:image:width" or "og:image:secure_url"
        foreach ($xpath->query('//meta[@property="' . $prop . '" or @name="' . $prop . '"]') as $node) {
            // getAttribute() always returns '' (not null) when absent, so check both manually
            $v = $node->getAttribute('content');
            if ($v === '') {
                $v = $node->getAttribute('value');
            }
            if ($v !== '') {
                return $v;
            }
        }
        return null;
    }

    /**
     * Returns true only when the given bot has its root disallowed (Disallow: /)
     * in robots.txt. Handles multi-line user-agent blocks and avoids false
     * positives from path-specific rules like Disallow: /r/ or Disallow: /s/.
     */
    private function botBlockedAtRoot(string $robots, string $bot): bool
    {
        // Normalise line endings
        $text = str_replace("\r\n", "\n", $robots);
        $lines = explode("\n", $text);

        $inBlock = false;
        foreach ($lines as $line) {
            $line = trim($line);

            // Blank line ends the current block
            if ($line === '' || str_starts_with($line, '#')) {
                $inBlock = false;
                continue;
            }

            if (preg_match('#^User-agent:\s*(.+)#i', $line, $m)) {
                $agent = trim($m[1]);
                // Enter block if this user-agent matches the bot or is wildcard (*)
                if (strcasecmp($agent, $bot) === 0 || $agent === '*') {
                    $inBlock = true;
                } elseif (!$inBlock) {
                    // Different user-agent, and we weren't already in a matching block
                    $inBlock = false;
                }
                continue;
            }

            if ($inBlock && preg_match('#^Disallow:\s*/\s*$#i', $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{home: array, robots: string|null, llms_txt: string|null, llms_full_txt: string|null, security_txt: string|null} $fetched
     * @return array<string, array{status: string, label: string, value?: string, fix?: string}>
     */
    private function checkAiLlm(array $fetched, string $baseUrl): array
    {
        $robots = $fetched['robots'];
        $llmsTxt = $fetched['llms_txt'];
        $llmsFullTxt = $fetched['llms_full_txt'];
        $securityTxt = $fetched['security_txt'];

        $checks = [];

        $hasLlms = $llmsTxt !== null && trim($llmsTxt) !== '';
        $hasH1 = $hasLlms && preg_match('/^#\s+.+/m', $llmsTxt);
        $hasBlockquote = $hasLlms && preg_match('#^>#m', $llmsTxt);
        $llmsValid = $hasLlms && $hasH1 && $hasBlockquote;
        $checks['llms_txt'] = [
            'status' => $llmsValid ? 'pass' : ($hasLlms ? 'warn' : 'fail'),
            'label'  => 'llms.txt',
            'value'  => $llmsValid ? 'Valid' : ($hasLlms ? 'Incomplete' : 'Missing'),
            'fix'    => $llmsValid ? null : ($hasLlms ? 'Include H1 and blockquote summary.' : 'Add /llms.txt with H1, blockquote summary, and sections.'),
        ];

        $checks['llms_full_txt'] = [
            'status' => ($llmsFullTxt !== null && trim($llmsFullTxt) !== '') ? 'pass' : 'warn',
            'label'  => 'llms-full.txt',
            'value'  => ($llmsFullTxt !== null && trim($llmsFullTxt) !== '') ? 'Present' : 'Optional',
            'fix'    => ($llmsFullTxt !== null && trim($llmsFullTxt) !== '') ? null : 'Optional: add /llms-full.txt for full content for AI.',
        ];

        $blocked = [];
        if ($robots !== null) {
            foreach (self::AI_CRAWLERS as $bot) {
                if ($this->botBlockedAtRoot($robots, $bot)) {
                    $blocked[] = $bot;
                }
            }
        }
        $checks['ai_crawler_access'] = [
            'status' => empty($blocked) ? 'pass' : 'warn',
            'label'  => 'AI crawler access',
            'value'  => empty($blocked) ? 'Allowed' : 'Blocked: ' . implode(', ', $blocked),
            'fix'    => empty($blocked) ? null : 'Consider allowing AI crawlers in robots.txt for visibility.',
        ];

        $hasSecurityTxt = $securityTxt !== null && trim($securityTxt) !== '';
        $checks['security_txt'] = [
            'status' => $hasSecurityTxt ? 'pass' : 'warn',
            'label'  => 'security.txt',
            'value'  => $hasSecurityTxt ? 'Present' : 'Missing',
            'fix'    => $hasSecurityTxt ? null : 'Add /.well-known/security.txt for security contact.',
        ];

        return $checks;
    }

    /**
     * @param array{home: array{headers: array<string, string>}} $fetched
     * @return array<string, array{status: string, label: string, value?: string, fix?: string}>
     */
    private function checkSecurityHeaders(array $fetched): array
    {
        $headers = $fetched['home']['headers'] ?? [];
        $get = function (string $key) use ($headers): ?string {
            $key = strtolower($key);
            foreach ($headers as $h => $v) {
                if (strtolower($h) === $key && is_string($v)) {
                    return $v;
                }
            }
            return null;
        };

        $checks = [];

        $hsts = $get('strict-transport-security');
        $checks['hsts'] = [
            'status' => $hsts ? 'pass' : 'warn',
            'label'  => 'Strict-Transport-Security',
            'value'  => $hsts ? 'Present' : 'Missing',
            'fix'    => $hsts ? null : 'Add Strict-Transport-Security header.',
        ];

        $xcto = $get('x-content-type-options');
        $xctoPass = $xcto !== null && stripos($xcto, 'nosniff') !== false;
        $checks['x_content_type_options'] = [
            'status' => $xctoPass ? 'pass' : 'warn',
            'label'  => 'X-Content-Type-Options',
            'value'  => $xcto ?: 'Missing',
            'fix'    => $xctoPass ? null : 'Add X-Content-Type-Options: nosniff.',
        ];

        $xfo = $get('x-frame-options');
        $csp = $get('content-security-policy');
        $hasFrameProtection = ($xfo !== null && $xfo !== '') || ($csp !== null && stripos($csp, 'frame-ancestors') !== false);
        $checks['x_frame_options'] = [
            'status' => $hasFrameProtection ? 'pass' : 'warn',
            'label'  => 'X-Frame-Options / CSP',
            'value'  => $hasFrameProtection ? 'Present' : 'Missing',
            'fix'    => $hasFrameProtection ? null : 'Add X-Frame-Options or CSP frame-ancestors.',
        ];

        $checks['content_security_policy'] = [
            'status' => $csp ? 'pass' : 'warn',
            'label'  => 'Content-Security-Policy',
            'value'  => $csp ? 'Present' : 'Missing',
            'fix'    => $csp ? null : 'Consider adding Content-Security-Policy header.',
        ];

        return $checks;
    }

    /**
     * @param array<string, array<string, array{status: string}>> $sections
     */
    private function computeScore(array $sections): int
    {
        $weights = [
            'crawlability' => 0.25,
            'on_page'      => 0.35,
            'ai_llm'       => 0.25,
            'security'     => 0.15,
        ];
        $total = 0.0;
        foreach ($weights as $name => $weight) {
            $checks = $sections[$name] ?? [];
            $sum = 0;
            $count = count($checks);
            if ($count === 0) {
                continue;
            }
            foreach ($checks as $c) {
                $sum += $c['status'] === 'pass' ? 100 : ($c['status'] === 'warn' ? 50 : 0);
            }
            $total += ($sum / $count) * $weight;
        }
        return (int) round($total);
    }

    private function scoreToGrade(int $score): string
    {
        if ($score >= 90) {
            return 'A';
        }
        if ($score >= 75) {
            return 'B';
        }
        if ($score >= 60) {
            return 'C';
        }
        if ($score >= 40) {
            return 'D';
        }
        return 'F';
    }

    /**
     * @param array<string, array<string, array{status: string, label: string, fix?: string|null}>> $sections
     * @return string[]
     */
    private function buildRecommendations(array $sections): array
    {
        $out = [];
        foreach ($sections as $checks) {
            foreach ($checks as $c) {
                if (($c['status'] === 'fail' || $c['status'] === 'warn') && !empty($c['fix'])) {
                    $out[] = $c['label'] . ': ' . $c['fix'];
                }
            }
        }
        return array_slice(array_unique($out), 0, 10);
    }
}
