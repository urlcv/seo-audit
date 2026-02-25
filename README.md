# urlcv/seo-audit

SEO and AI/LLM readiness audit for any domain. Checks crawlability (HTTPS, robots.txt, sitemap), on-page SEO (title, meta, Open Graph, JSON-LD), AI readiness (llms.txt, AI crawler access), and security headers.

Powers the free [SEO Audit](https://urlcv.com/tools/seo-audit) tool at urlcv.com.

## Installation

```bash
composer require urlcv/seo-audit
```

Register the tool class in `config/tools.php`:

```php
'tools' => [
    // ...
    \URLCV\SeoAudit\Laravel\SeoAuditTool::class,
],
```

The main app must provide an output view at `resources/views/tools/output/seo-audit.blade.php` that receives the audit result (domain, score, grade, sections, recommendations).

## Usage

```php
$audit = new \URLCV\SeoAudit\SeoAudit();
$result = $audit->check('example.com');
// $result: domain, error?, score, grade, sections (crawlability, on_page, ai_llm, security), recommendations
```

## Checks

- **Crawlability:** HTTPS, robots.txt, XML sitemap, response time
- **On-page:** title, meta description, canonical, viewport, H1, Open Graph, Twitter card, JSON-LD, lang, favicon
- **AI/LLM:** llms.txt, llms-full.txt, AI crawler access, security.txt
- **Security:** HSTS, X-Content-Type-Options, X-Frame-Options, CSP

## License

MIT.
