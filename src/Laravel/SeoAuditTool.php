<?php

declare(strict_types=1);

namespace URLCV\SeoAudit\Laravel;

use App\Tools\Contracts\ToolInterface;
use URLCV\SeoAudit\SeoAudit;

class SeoAuditTool implements ToolInterface
{
    public function slug(): string
    {
        return 'seo-audit';
    }

    public function name(): string
    {
        return 'SEO Audit';
    }

    public function summary(): string
    {
        return 'Check crawlability, on-page SEO, AI/LLM readiness (llms.txt, sitemap, robots.txt), and security headers for any domain.';
    }

    public function descriptionMd(): ?string
    {
        return <<<'MD'
## SEO Audit

Enter a domain to run a full SEO and AI-readiness audit. We check:

- **Crawlability** — HTTPS, robots.txt, XML sitemap, response time
- **On-page SEO** — title, meta description, canonical, viewport, H1, Open Graph, Twitter cards, JSON-LD, language, favicon
- **AI/LLM readiness** — llms.txt, llms-full.txt, AI crawler access in robots.txt, security.txt
- **Security headers** — HSTS, X-Content-Type-Options, X-Frame-Options, Content-Security-Policy

Each check shows pass, warn, or fail with a short fix suggestion. Get a letter grade (A–F) and section scores to prioritise improvements.
MD;
    }

    public function categories(): array
    {
        return ['productivity'];
    }

    public function tags(): array
    {
        return ['seo', 'audit', 'llms', 'sitemap', 'robots', 'ai'];
    }

    public function inputSchema(): array
    {
        return [
            'domain' => [
                'type'        => 'string',
                'label'       => 'Domain',
                'placeholder' => 'example.com',
                'required'    => true,
                'max_length'  => 253,
                'help'        => 'Enter the domain to audit (with or without https://).',
            ],
        ];
    }

    public function run(array $input): array
    {
        $domain = trim((string) ($input['domain'] ?? ''));
        return (new SeoAudit())->check($domain);
    }

    public function mode(): string
    {
        return 'sync';
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function isPublic(): bool
    {
        return true;
    }

    public function frontendView(): ?string
    {
        return null;
    }

    public function rateLimitPerMinute(): int
    {
        return 10;
    }

    public function cacheTtlSeconds(): int
    {
        return 0;
    }

    public function sortWeight(): int
    {
        return 95;
    }
}
