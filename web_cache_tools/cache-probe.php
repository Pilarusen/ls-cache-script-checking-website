<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

if (php_sapi_name() !== 'cli') {
    http_response_code(400);
    echo 'This script is intended to be run from CLI.';
    exit(1);
}

function stderr(string $msg): void {
    fwrite(STDERR, $msg . PHP_EOL);
    traceLog($msg);
}

$TRACE_LOG_HANDLE = null;

function traceLog(string $msg): void {
    global $TRACE_LOG_HANDLE;
    if ($TRACE_LOG_HANDLE !== null) {
        fwrite($TRACE_LOG_HANDLE, $msg . PHP_EOL);
    }
}

function usage(string $err = ''): void {
    if ($err !== '') {
        stderr($err);
        stderr('');
    }

    stderr('Usage: php cache-probe.php --site=https://example.com [--limit=10] [--variant=both] [--method=get] [--passes=2] [--concurrency=1] [--delay-ms=500] [--seed=123] [--expect-nocache=none|woocommerce] [--output=report.html] [--trace-log=trace.log] [--verbose] [--trace]');;
    exit($err === '' ? 0 : 1);
}

function parseArgs(array $argv): array {
    $args = [
        'site' => null,
        'limit' => 10,
        'variant' => 'both',
        'method' => 'get',
        'passes' => 2,
        'concurrency' => 1,
        'delay_ms' => 500,
        'seed' => null,
        'expect_nocache' => 'none',
        'output' => null,
        'trace_log' => null,
        'verbose' => false,
        'trace' => false,
    ];;

    for ($i = 1; $i < count($argv); $i++) {
        $a = $argv[$i];

        if ($a === '--verbose') {
            $args['verbose'] = true;
            continue;
        }

        if ($a === '--trace') {
            $args['trace'] = true;
            continue;
        }

        if (strpos($a, '--') !== 0 || strpos($a, '=') === false) {
            usage('Invalid argument: ' . $a);
        }

        [$k, $v] = explode('=', substr($a, 2), 2);

        switch ($k) {
            case 'site':
                $args['site'] = rtrim($v, '/');
                if (!preg_match('~^https?://~i', $args['site'])) {
                    $args['site'] = 'https://' . ltrim($args['site'], '/');
                }
                break;
            case 'limit':
                $args['limit'] = max(1, (int)$v);
                break;
            case 'variant':
                $v = strtolower(trim($v));
                if (!in_array($v, ['desktop', 'mobile', 'both'], true)) {
                    usage('Invalid --variant. Use: desktop|mobile|both');
                }
                $args['variant'] = $v;
                break;
            case 'method':
                $v = strtolower(trim($v));
                if (!in_array($v, ['head', 'get'], true)) {
                    usage('Invalid --method. Use: head|get');
                }
                $args['method'] = $v;
                break;
            case 'passes':
                $args['passes'] = max(1, (int)$v);
                break;
            case 'concurrency':
                $args['concurrency'] = max(1, (int)$v);
                break;
            case 'delay-ms':
                $args['delay_ms'] = max(0, (int)$v);
                break;
            case 'seed':
                $args['seed'] = (string)$v;
                break;
            case 'output':
                $args['output'] = (string)$v;
                break;
            case 'trace-log':
                $args['trace_log'] = (string)$v;
                break;
            case 'expect-nocache':
                $v = strtolower(trim($v));
                if (!in_array($v, ['none', 'woocommerce'], true)) {
                    usage('Invalid --expect-nocache. Use: none|woocommerce');
                }
                $args['expect_nocache'] = $v;
                break;
            default:
                usage('Unknown argument: --' . $k);
        }
    }

    if (!$args['site']) {
        usage('Missing required: --site=...');
    }

    return $args;
}

function curlHeadOrGet(string $url, string $ua, string $method, int $timeoutSec = 20): array {
    $ch = curl_init($url);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => [
            'Accept: */*',
        ],
    ];

    if ($method === 'head') {
        $opts[CURLOPT_NOBODY] = true;
    } else {
        $opts[CURLOPT_NOBODY] = false;
    }

    curl_setopt_array($ch, $opts);

    $start = microtime(true);
    $raw = curl_exec($ch);
    $elapsedMs = (int)round((microtime(true) - $start) * 1000);

    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    curl_close($ch);

    if (!is_string($raw)) {
        $raw = '';
    }

    $headersOnly = $headerSize > 0 ? substr($raw, 0, $headerSize) : $raw;

    return [
        'url' => $url,
        'effective_url' => $effectiveUrl,
        'http' => $http,
        'elapsed_ms' => $elapsedMs,
        'errno' => $errno,
        'error' => $err,
        'raw_headers' => $raw,
        'headers_only' => $headersOnly,
    ];
}

function traceHeaders(string $headersOnly): void {
    $lines = preg_split('/\r\n|\n|\r/', $headersOnly);
    if (!is_array($lines)) {
        return;
    }

    $keep = [
        'server',
        'cf-cache-status',
        'x-litespeed-cache',
        'x-litespeed-cache-control',
        'cache-control',
        'vary',
        'age',
        'location',
        'content-type',
    ];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (strpos($line, 'HTTP/') === 0) {
            stderr('<< ' . $line);
            continue;
        }

        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $name = strtolower(trim(substr($line, 0, $pos)));
        if (in_array($name, $keep, true)) {
            stderr('<< ' . $line);
        }
    }
}

function parseHeader(string $rawHeaders, string $name): ?string {
    $name = strtolower($name);
    if (preg_match_all('~^' . preg_quote($name, '~') . '\s*:\s*(.+)$~im', $rawHeaders, $m) && !empty($m[1])) {
        return trim($m[1][count($m[1]) - 1]);
    }
    return null;
}

function cacheStatusFromHeaders(string $rawHeaders): string {
    $val = parseHeader($rawHeaders, 'x-litespeed-cache');
    if ($val !== null) {
        return strtolower($val);
    }

    $lscc = parseHeader($rawHeaders, 'x-litespeed-cache-control');
    if ($lscc !== null) {
        $lsccNorm = strtolower($lscc);
        if (strpos($lsccNorm, 'public') !== false && strpos($lsccNorm, 'max-age') !== false) {
            return $lsccNorm;
        }
    }

    $cc = parseHeader($rawHeaders, 'cache-control');
    if ($cc !== null) {
        $ccNorm = strtolower($cc);
        if (strpos($ccNorm, 'public') !== false && strpos($ccNorm, 'max-age') !== false) {
            return $ccNorm;
        }
    }

    $qc = parseHeader($rawHeaders, 'x-qc-cache');
    if ($qc !== null) {
        return 'qc:' . strtolower($qc);
    }

    return 'n/a';
}

function isExpectedNoCache(string $url, string $mode): bool {
    if ($mode !== 'woocommerce') {
        return false;
    }

    return (bool)preg_match('~/(cart|koszyk|checkout|do-kasy|my-account|moje-konto)(/|$)~i', $url)
        || (bool)preg_match('~(\?|&)add-to-cart=~i', $url)
        || (bool)preg_match('~(\?|&)wc-ajax=~i', $url)
        || (bool)preg_match('~/wp-admin/|/wp-json/|wp-login\.php$~i', $url);
}

function findSitemap(string $site): string {
    $candidates = [
        $site . '/wp-sitemap.xml',
        $site . '/sitemap.xml',
        $site . '/sitemap_index.xml',
    ];

    foreach ($candidates as $u) {
        $r = curlHeadOrGet($u, 'LSCache-Probe/1.0', 'head', 10);
        if ($r['errno'] === 0 && $r['http'] === 200) {
            return $u;
        }
    }

    throw new RuntimeException('Could not find sitemap for site: ' . $site);
}

function fetchSitemapUrls(string $sitemapUrl, string $site, bool $isNested = false): array {
    $r = curlHeadOrGet($sitemapUrl, 'LSCache-Probe/1.0', 'get', 30);
    if ($r['errno'] !== 0 || $r['http'] < 200 || $r['http'] >= 300) {
        if ($isNested) {
            stderr('Warning: skipping nested sitemap (HTTP ' . $r['http'] . '): ' . $sitemapUrl);
            return [];
        }
        throw new RuntimeException('Failed to fetch sitemap: ' . $sitemapUrl . ' (HTTP ' . $r['http'] . ')');
    }

    $raw = $r['raw_headers'];

    $headerEnd = strpos($raw, "\r\n\r\n");
    $body = $headerEnd === false ? '' : substr($raw, $headerEnd + 4);

    libxml_use_internal_errors(true);
    $sx = simplexml_load_string($body);
    if (!$sx) {
        $errs = libxml_get_errors();
        libxml_clear_errors();
        throw new RuntimeException('Failed to parse sitemap XML (' . count($errs) . ' libxml errors)');
    }

    $urls = [];

    if (isset($sx->sitemap)) {
        foreach ($sx->sitemap as $sm) {
            $loc = trim((string)$sm->loc);
            if ($loc !== '') {
                $urls = array_merge($urls, fetchSitemapUrls($loc, $site, true));
            }
        }
    } elseif (isset($sx->url)) {
        foreach ($sx->url as $u) {
            $loc = trim((string)$u->loc);
            if ($loc !== '') {
                $urls[] = $loc;
            }
        }
    } else {
        if (preg_match_all('~<loc>([^<]+)</loc>~i', $body, $m)) {
            foreach ($m[1] as $loc) {
                $loc = trim($loc);
                if ($loc !== '') {
                    $urls[] = $loc;
                }
            }
        }
    }

    $siteHost = parse_url($site, PHP_URL_HOST);
    if ($siteHost === null || $siteHost === false) {
        $siteHost = preg_replace('~^https?://~i', '', $site);
        $siteHost = preg_replace('~/.*$~', '', $siteHost);
    }
    $siteHost = strtolower($siteHost);
    $siteHostNormalized = preg_replace('~^www\.~', '', $siteHost);

    $urls = array_values(array_filter($urls, function (string $u) use ($siteHost, $siteHostNormalized): bool {
        $uHost = parse_url($u, PHP_URL_HOST);
        if ($uHost === null || $uHost === false) {
            return false;
        }
        $uHost = strtolower($uHost);
        $uHostNormalized = preg_replace('~^www\.~', '', $uHost);
        return $uHost === $siteHost || $uHostNormalized === $siteHostNormalized;
    }));

    $urls = array_values(array_unique($urls));

    return $urls;
}

function sampleUrls(array $urls, int $limit, ?string $seed): array {
    if ($seed !== null) {
        mt_srand((int)crc32($seed));
    }

    $n = count($urls);
    if ($n <= $limit) {
        return $urls;
    }

    $picked = [];
    $pickedIdx = [];

    while (count($picked) < $limit) {
        $idx = mt_rand(0, $n - 1);
        if (isset($pickedIdx[$idx])) {
            continue;
        }
        $pickedIdx[$idx] = true;
        $picked[] = $urls[$idx];
    }

    return $picked;
}

function statusClass(string $v): string {
    $lv = strtolower($v);
    if (strpos($lv, 'hit') !== false) return 'hit';
    if (strpos($lv, 'miss') !== false) return 'miss';
    if (strpos($lv, 'bypass') !== false || strpos($lv, 'no-cache') !== false) return 'bypass';
    if (strpos($lv, 'public') !== false && strpos($lv, 'max-age') !== false) return 'hit';
    if (strpos($lv, 'qc:hit') !== false) return 'hit';
    if (strpos($lv, 'qc:miss') !== false) return 'miss';
    return 'na';
}

function htmlEscape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function buildQueue(array $urls, array $variants, int $passes): array {
    $queue = [];
    foreach ($urls as $u) {
        foreach ($variants as $variant => $ua) {
            for ($pass = 1; $pass <= $passes; $pass++) {
                $queue[] = ['url' => $u, 'variant' => $variant, 'ua' => $ua, 'pass' => $pass];
            }
        }
    }
    return $queue;
}

function runProbe(array $queue, string $method, int $concurrency, int $delayMs, bool $verbose, bool $trace, int $passes): array {
    $results = [];

    $total = count($queue);
    $processed = 0;

    $nextProgress = 0;

    $logProgress = function () use (&$nextProgress, $total, &$processed): void {
        if ($total <= 0) {
            return;
        }

        $pct = (int)floor(($processed / $total) * 100);
        if ($pct >= $nextProgress) {
            stderr(sprintf('Progress: %d/%d (%d%%)', $processed, $total, $pct));
            $nextProgress += 2;
        }
    };

    if ($concurrency <= 1) {
        foreach ($queue as $item) {
            if ($delayMs > 0) {
                $jitter = mt_rand(0, (int)($delayMs * 0.3));
                usleep(($delayMs + $jitter) * 1000);
            }

            $r = curlHeadOrGet($item['url'], $item['ua'], $method);
            $cache = $r['errno'] ? 'error' : cacheStatusFromHeaders($r['headers_only']);

            $cfStatus = parseHeader($r['headers_only'], 'cf-cache-status');
            $originUnverifiable = $cfStatus !== null && stripos($cfStatus, 'hit') !== false;

            $row = [
                'url' => $item['url'],
                'variant' => $item['variant'],
                'pass' => $item['pass'],
                'http' => $r['http'],
                'elapsed_ms' => $r['elapsed_ms'],
                'cache' => $cache,
                'server' => parseHeader($r['headers_only'], 'server'),
                'cf_cache_status' => $cfStatus,
                'origin_unverifiable' => $originUnverifiable,
                'vary' => parseHeader($r['headers_only'], 'vary'),
                'x_litespeed_cache_control' => parseHeader($r['headers_only'], 'x-litespeed-cache-control'),
                'cache_control' => parseHeader($r['headers_only'], 'cache-control'),
                'age' => parseHeader($r['headers_only'], 'age'),
                'error' => $r['errno'] ? $r['error'] : null,
            ];

            $results[] = $row;

            $processed++;
            $logProgress();

            if ($verbose) {
                $cfNote = $originUnverifiable ? ' [CF-HIT: origin unverifiable]' : '';
                stderr(sprintf('[%s] Pass %d/%d: %s | HTTP %d | %s | %dms%s', strtoupper($item['variant']), $item['pass'], $passes, $item['url'], $r['http'], $cache, $r['elapsed_ms'], $cfNote));
            }

            if ($trace) {
                $uaShort = strlen($item['ua']) > 80 ? substr($item['ua'], 0, 77) . '...' : $item['ua'];
                stderr(sprintf('>> %s %s [%s] UA="%s"', strtoupper($method), $item['url'], strtoupper($item['variant']), $uaShort));
                if (!empty($r['effective_url']) && $r['effective_url'] !== $item['url']) {
                    stderr('<< Effective-URL: ' . $r['effective_url']);
                }
                traceHeaders((string)$r['headers_only']);
            }
        }

        return $results;
    }

    $mh = curl_multi_init();
    $handles = [];
    $i = 0;

    $startHandle = function (array $item) use ($method, $mh, &$handles): void {
        $ch = curl_init($item['url']);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $item['ua'],
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
            ],
        ];

        if ($method === 'head') {
            $opts[CURLOPT_NOBODY] = true;
        } else {
            $opts[CURLOPT_NOBODY] = false;
        }

        curl_setopt_array($ch, $opts);

        $handles[(int)$ch] = [
            'ch' => $ch,
            'url' => $item['url'],
            'variant' => $item['variant'],
            'pass' => $item['pass'],
            'start' => microtime(true),
        ];

        curl_multi_add_handle($mh, $ch);
    };

    $initial = min($concurrency, $total);
    for (; $i < $initial; $i++) {
        if ($delayMs > 0) {
            $jitter = mt_rand(0, (int)($delayMs * 0.3));
            usleep(($delayMs + $jitter) * 1000);
        }
        $startHandle($queue[$i]);
    }

    do {
        $mrc = curl_multi_exec($mh, $active);
        if ($mrc !== CURLM_OK) {
            // continue
        }

        $status = curl_multi_select($mh, 1.0);
        if ($status === -1) {
            usleep(1000);
        }

        while ($info = curl_multi_info_read($mh)) {
            $done = $info['handle'];
            $meta = $handles[(int)$done] ?? null;
            if (!$meta) {
                curl_multi_remove_handle($mh, $done);
                curl_close($done);
                continue;
            }

            unset($handles[(int)$done]);

            $raw = curl_multi_getcontent($done);
            $errno = curl_errno($done);
            $err = curl_error($done);
            $http = (int)curl_getinfo($done, CURLINFO_HTTP_CODE);
            $effectiveUrl = (string)curl_getinfo($done, CURLINFO_EFFECTIVE_URL);
            $headerSize = (int)curl_getinfo($done, CURLINFO_HEADER_SIZE);
            $elapsedMs = (int)round((microtime(true) - $meta['start']) * 1000);

            $rawStr = is_string($raw) ? $raw : '';
            $headersOnly = $headerSize > 0 ? substr($rawStr, 0, $headerSize) : $rawStr;
            $cache = $errno ? 'error' : cacheStatusFromHeaders($headersOnly);

            $cfStatus = parseHeader($headersOnly, 'cf-cache-status');
            $originUnverifiable = $cfStatus !== null && stripos($cfStatus, 'hit') !== false;

            $row = [
                'url' => $meta['url'],
                'variant' => $meta['variant'],
                'pass' => $meta['pass'],
                'http' => $http,
                'elapsed_ms' => $elapsedMs,
                'cache' => $cache,
                'server' => parseHeader($headersOnly, 'server'),
                'cf_cache_status' => $cfStatus,
                'origin_unverifiable' => $originUnverifiable,
                'vary' => parseHeader($headersOnly, 'vary'),
                'x_litespeed_cache_control' => parseHeader($headersOnly, 'x-litespeed-cache-control'),
                'cache_control' => parseHeader($headersOnly, 'cache-control'),
                'age' => parseHeader($headersOnly, 'age'),
                'error' => $errno ? $err : null,
            ];

            $results[] = $row;

            $processed++;
            $logProgress();

            if ($verbose) {
                $cfNote = $originUnverifiable ? ' [CF-HIT: origin unverifiable]' : '';
                stderr(sprintf('[%s] Pass %d/%d: %s | HTTP %d | %s | %dms%s', strtoupper($meta['variant']), $meta['pass'], $passes, $meta['url'], $http, $cache, $elapsedMs, $cfNote));
            }

            if ($trace) {
                stderr(sprintf('>> %s %s [%s]', strtoupper($method), $meta['url'], strtoupper($meta['variant'])));
                if ($effectiveUrl !== '' && $effectiveUrl !== $meta['url']) {
                    stderr('<< Effective-URL: ' . $effectiveUrl);
                }
                traceHeaders($headersOnly);
            }

            curl_multi_remove_handle($mh, $done);
            curl_close($done);

            if ($i < $total) {
                if ($delayMs > 0) {
                    $jitter = mt_rand(0, (int)($delayMs * 0.3));
                    usleep(($delayMs + $jitter) * 1000);
                }
                $startHandle($queue[$i]);
                $i++;
            }
        }
    } while ($active || !empty($handles));

    curl_multi_close($mh);

    return $results;
}

function buildHtmlReport(array $results, array $config, array $sampleUrls): string {
    $variants = [];
    foreach ($results as $r) {
        $variants[$r['variant']] = true;
    }
    $variantList = array_keys($variants);
    sort($variantList, SORT_STRING);

    $byUrl = [];
    foreach ($results as $r) {
        $byUrl[$r['url']][$r['variant']][$r['pass']] = $r;
    }

    $stats = [];
    foreach ($variantList as $v) {
        $stats[$v] = [
            'hit' => 0,
            'miss' => 0,
            'bypass' => 0,
            'na' => 0,
            'error' => 0,
            'cf_unverifiable' => 0,
            'total' => 0,
            'expected_total' => 0,
            'expected_problem' => 0,
            'public_total' => 0,
            'public_problem' => 0,
        ];
    }

    $passes = (int)$config['passes'];
    $urlVariantPairs = [];
    foreach ($sampleUrls as $u) {
        foreach ($variantList as $v) {
            $urlVariantPairs[] = ['url' => $u, 'variant' => $v];
        }
    }

    foreach ($urlVariantPairs as $pair) {
        $u = $pair['url'];
        $v = $pair['variant'];
        
        $isExpected = isExpectedNoCache($u, $config['expect_nocache']);
        if ($isExpected) {
            $stats[$v]['expected_total']++;
        } else {
            $stats[$v]['public_total']++;
        }

        $finalPass = $byUrl[$u][$v][$passes] ?? null;
        if (!$finalPass) {
            continue;
        }

        $stats[$v]['total']++;
        $cls = statusClass($finalPass['cache']);

        if ($finalPass['error'] !== null) {
            $stats[$v]['error']++;
            if ($isExpected) $stats[$v]['expected_problem']++;
            else $stats[$v]['public_problem']++;
            continue;
        }

        if ($finalPass['origin_unverifiable']) {
            $stats[$v]['cf_unverifiable']++;
        }

        if ($cls === 'hit') $stats[$v]['hit']++;
        elseif ($cls === 'miss') $stats[$v]['miss']++;
        elseif ($cls === 'bypass') $stats[$v]['bypass']++;
        else $stats[$v]['na']++;

        if (!$isExpected) {
            if ($finalPass['origin_unverifiable']) {
                $stats[$v]['public_problem']++;
            } elseif (in_array($cls, ['na', 'bypass', 'miss'], true)) {
                $stats[$v]['public_problem']++;
            }
        }
    }

    $title = 'Cache Probe - ' . $config['site'];

    $html = "<!doctype html>\n";
    $html .= "<html lang=\"pl\">\n";
    $html .= "<head>\n";
    $html .= "  <meta charset=\"utf-8\"/>\n";
    $html .= "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"/>\n";
    $html .= "  <title>" . htmlEscape($title) . "</title>\n";
    $html .= "  <style>\n";
    $html .= "    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;padding:20px;color:#222;background:#f6f7f9;}\n";
    $html .= "    .container{max-width:1200px;margin:0 auto;background:#fff;padding:18px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08);} \n";
    $html .= "    h1{margin:0 0 6px;font-size:20px;}\n";
    $html .= "    .meta{background:#f8f9fb;border:1px solid #e7e9ef;padding:12px;border-radius:8px;margin:12px 0;}\n";
    $html .= "    .meta code{background:#fff;border:1px solid #e7e9ef;padding:2px 6px;border-radius:6px;}\n";
    $html .= "    .summary{display:flex;gap:14px;flex-wrap:wrap;margin:14px 0;}\n";
    $html .= "    .card{flex:1;min-width:260px;border:1px solid #e7e9ef;border-radius:10px;padding:12px;background:#fff;}\n";
    $html .= "    .card h2{margin:0 0 8px;font-size:14px;color:#2c3e50;text-transform:uppercase;letter-spacing:.3px;}\n";
    $html .= "    .badge{display:inline-block;padding:3px 8px;border-radius:999px;font-weight:600;font-size:12px;border:1px solid transparent;}\n";
    $html .= "    .hit{background:#e6f7ea;color:#187a2f;border-color:#bfe8c7;}\n";
    $html .= "    .miss{background:#e9f2ff;color:#1450a3;border-color:#c9dcff;}\n";
    $html .= "    .bypass{background:#fff3e0;color:#e65100;border-color:#ffe0b2;}\n";
    $html .= "    .na{background:#f2f2f2;color:#616161;border-color:#e0e0e0;}\n";
    $html .= "    .error{background:#ffebee;color:#c62828;border-color:#ffcdd2;}\n";
    $html .= "    table{width:100%;border-collapse:collapse;margin-top:12px;font-size:13px;}\n";
    $html .= "    th,td{padding:10px 10px;border-bottom:1px solid #e7e9ef;text-align:left;vertical-align:top;}\n";
    $html .= "    th{background:#fafbfc;font-size:12px;color:#2c3e50;text-transform:uppercase;letter-spacing:.3px;}\n";
    $html .= "    tr:hover{background:#fafbff;}\n";
    $html .= "    .url{max-width:520px;word-break:break-all;}\n";
    $html .= "    .small{color:#6b7280;font-size:12px;}\n";
    $html .= "  </style>\n";
    $html .= "</head>\n";
    $html .= "<body>\n";
    $html .= "<div class=\"container\">\n";
    $html .= "  <h1>" . htmlEscape($title) . "</h1>\n";

    $html .= "  <div class=\"meta\">\n";
    $html .= "    <div><strong>Site:</strong> <code>" . htmlEscape($config['site']) . "</code></div>\n";
    $html .= "    <div><strong>Limit:</strong> <code>" . (int)$config['limit'] . "</code> | <strong>Variant:</strong> <code>" . htmlEscape($config['variant']) . "</code> | <strong>Method:</strong> <code>" . htmlEscape($config['method']) . "</code> | <strong>Passes:</strong> <code>" . (int)$config['passes'] . "</code></div>\n";
    $html .= "    <div><strong>Concurrency:</strong> <code>" . (int)$config['concurrency'] . "</code> | <strong>Delay:</strong> <code>" . (int)$config['delay_ms'] . "ms</code> | <strong>Seed:</strong> <code>" . htmlEscape($config['seed'] ?? '(none)') . "</code></div>\n";
    $html .= "    <div><strong>Expect no-cache:</strong> <code>" . htmlEscape($config['expect_nocache']) . "</code></div>\n";
    $html .= "  </div>\n";

    $html .= "  <div class=\"summary\">\n";
    foreach ($variantList as $v) {
        $s = $stats[$v];
        $html .= "    <div class=\"card\">\n";
        $html .= "      <h2>" . htmlEscape(strtoupper($v)) . "</h2>\n";
        $html .= "      <div><span class=\"badge hit\">hit</span> " . (int)$s['hit'] . "</div>\n";
        $html .= "      <div><span class=\"badge miss\">miss</span> " . (int)$s['miss'] . "</div>\n";
        $html .= "      <div><span class=\"badge bypass\">bypass</span> " . (int)$s['bypass'] . "</div>\n";
        $html .= "      <div><span class=\"badge na\">n/a</span> " . (int)$s['na'] . "</div>\n";
        if ($s['error'] > 0) {
            $html .= "      <div><span class=\"badge error\">error</span> " . (int)$s['error'] . "</div>\n";
        }
        if ($s['cf_unverifiable'] > 0) {
            $html .= "      <div class=\"small\" style=\"color:#e65100;\">⚠ CF-HIT (origin unverifiable): " . (int)$s['cf_unverifiable'] . "</div>\n";
        }
        $html .= "      <div class=\"small\" style=\"margin-top:8px;\">Public URLs checked: " . (int)$s['public_total'] . ", public problems: " . (int)$s['public_problem'] . "</div>\n";
        if ($config['expect_nocache'] !== 'none') {
            $html .= "      <div class=\"small\">Expected no-cache URLs checked: " . (int)$s['expected_total'] . ", errors: " . (int)$s['expected_problem'] . "</div>\n";
        }
        $html .= "    </div>\n";
    }
    $html .= "  </div>\n";

    $html .= "  <table>\n";
    $html .= "    <thead>\n";
    $html .= "      <tr>\n";
    $html .= "        <th>#</th>\n";
    $html .= "        <th>URL</th>\n";
    $html .= "        <th>Expected no-cache</th>\n";

    foreach ($variantList as $v) {
        for ($p = 1; $p <= $passes; $p++) {
            $passLabel = $passes > 1 ? " P{$p}" : '';
            $html .= "        <th>" . htmlEscape(strtoupper($v)) . "{$passLabel} status</th>\n";
        }
        $html .= "        <th>" . htmlEscape(strtoupper($v)) . " HTTP</th>\n";
        $html .= "        <th>" . htmlEscape(strtoupper($v)) . " time</th>\n";
        $html .= "        <th>" . htmlEscape(strtoupper($v)) . " server/cf</th>\n";
    }

    $html .= "      </tr>\n";
    $html .= "    </thead>\n";
    $html .= "    <tbody>\n";

    $idx = 1;
    foreach ($sampleUrls as $u) {
        $expected = isExpectedNoCache($u, $config['expect_nocache']);
        $html .= "      <tr>\n";
        $html .= "        <td>" . $idx++ . "</td>\n";
        $html .= "        <td class=\"url\">" . htmlEscape($u) . "</td>\n";
        $html .= "        <td>" . ($expected ? 'yes' : 'no') . "</td>\n";

        foreach ($variantList as $v) {
            $variantPasses = $byUrl[$u][$v] ?? null;
            if (!$variantPasses) {
                $colSpan = $passes + 3;
                $html .= "        <td colspan=\"{$colSpan}\"><span class=\"badge na\">n/a</span></td>\n";
                continue;
            }

            for ($p = 1; $p <= $passes; $p++) {
                $r = $variantPasses[$p] ?? null;
                if (!$r) {
                    $html .= "        <td><span class=\"badge na\">-</span></td>\n";
                    continue;
                }

                if ($r['error'] !== null) {
                    $html .= "        <td><span class=\"badge error\" title=\"" . htmlEscape((string)$r['error']) . "\">error</span></td>\n";
                    continue;
                }

                $cls = statusClass((string)$r['cache']);
                $titleParts = [];
                if ($r['origin_unverifiable']) $titleParts[] = 'CF-HIT: origin unverifiable';
                if (!empty($r['x_litespeed_cache_control'])) $titleParts[] = 'x-litespeed-cache-control: ' . $r['x_litespeed_cache_control'];
                if (!empty($r['cache_control'])) $titleParts[] = 'cache-control: ' . $r['cache_control'];
                if (!empty($r['vary'])) $titleParts[] = 'vary: ' . $r['vary'];
                if (!empty($r['age'])) $titleParts[] = 'age: ' . $r['age'];
                $title = implode(' | ', $titleParts);

                $badgeText = (string)$r['cache'];
                if ($r['origin_unverifiable']) {
                    $badgeText .= ' ⚠';
                }

                $html .= "        <td><span class=\"badge " . htmlEscape($cls) . "\" title=\"" . htmlEscape($title) . "\">" . htmlEscape($badgeText) . "</span></td>\n";
            }

            $lastPass = $variantPasses[$passes] ?? null;
            if ($lastPass && $lastPass['error'] === null) {
                $html .= "        <td>" . (int)$lastPass['http'] . "</td>\n";
                $html .= "        <td>" . (int)$lastPass['elapsed_ms'] . "ms</td>\n";

                $srv = (string)($lastPass['server'] ?? '');
                $cf = (string)($lastPass['cf_cache_status'] ?? '');
                $srvCf = trim($srv . ($cf !== '' ? (' / ' . $cf) : ''));
                $html .= "        <td class=\"small\">" . htmlEscape($srvCf) . "</td>\n";
            } else {
                $html .= "        <td>-</td><td>-</td><td>-</td>\n";
            }
        }

        $html .= "      </tr>\n";
    }

    $html .= "    </tbody>\n";
    $html .= "  </table>\n";

    $html .= "  <div class=\"small\" style=\"margin-top:12px;\">Generated: " . htmlEscape(date('Y-m-d H:i:s (T)')) . "</div>\n";
    $html .= "</div>\n";
    $html .= "</body>\n";
    $html .= "</html>\n";

    return $html;
}

try {
    $cfg = parseArgs($argv);

    // Start timer
    $startTime = microtime(true);

    // Auto-generate filenames if not provided
    if ($cfg['output'] === null) {
        $cfg['output'] = 'cache-probe-' . date('Y-m-d_H-i-s') . '.html';
    }
    if ($cfg['trace_log'] === null) {
        $cfg['trace_log'] = 'cache-probe-' . date('Y-m-d_H-i-s') . '.log';
    }

    // Open trace log file
    $TRACE_LOG_HANDLE = fopen($cfg['trace_log'], 'w');
    if ($TRACE_LOG_HANDLE === false) {
        throw new RuntimeException('Cannot open trace log file: ' . $cfg['trace_log']);
    }

    stderr('Trace log: ' . $cfg['trace_log']);
    stderr('Output HTML: ' . $cfg['output']);

    $sitemap = findSitemap($cfg['site']);
    stderr('Sitemap: ' . $sitemap);

    $urls = fetchSitemapUrls($sitemap, $cfg['site']);
    if (count($urls) === 0) {
        throw new RuntimeException('No URLs found in sitemap.');
    }

    if ($cfg['seed'] === null) {
        $cfg['seed'] = (string)(time());
    }

    $sample = sampleUrls($urls, (int)$cfg['limit'], $cfg['seed']);

    $UAS = [
        'desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        'mobile' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    ];

    $variants = [];
    if ($cfg['variant'] === 'desktop') {
        $variants = ['desktop' => $UAS['desktop']];
    } elseif ($cfg['variant'] === 'mobile') {
        $variants = ['mobile' => $UAS['mobile']];
    } else {
        $variants = ['desktop' => $UAS['desktop'], 'mobile' => $UAS['mobile']];
    }

    $queue = buildQueue($sample, $variants, (int)$cfg['passes']);

    stderr(sprintf('URLs sampled: %d (from sitemap: %d). Requests: %d (%s, %d passes).', count($sample), count($urls), count($queue), $cfg['variant'], (int)$cfg['passes']));

    $results = runProbe($queue, $cfg['method'], (int)$cfg['concurrency'], (int)$cfg['delay_ms'], (bool)$cfg['verbose'], (bool)$cfg['trace'], (int)$cfg['passes']);

    $html = buildHtmlReport($results, $cfg, $sample);

    // Write HTML to file
    $written = file_put_contents($cfg['output'], $html);
    if ($written === false) {
        throw new RuntimeException('Failed to write HTML report to: ' . $cfg['output']);
    }

    // Calculate execution time
    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;
    $minutes = floor($executionTime / 60);
    $seconds = $executionTime - ($minutes * 60);

    stderr('');
    stderr('✓ HTML report saved: ' . $cfg['output']);
    stderr('✓ Trace log saved: ' . $cfg['trace_log']);
    stderr('');
    stderr(sprintf('⏱ Execution time: %dm %.2fs (%.2f seconds total)', $minutes, $seconds, $executionTime));
    stderr(sprintf('⚡ Average time per request: %.2fms', ($executionTime / count($queue)) * 1000));

    // Close trace log
    if ($TRACE_LOG_HANDLE !== null) {
        fclose($TRACE_LOG_HANDLE);
    }

} catch (Throwable $e) {
    if ($TRACE_LOG_HANDLE !== null) {
        fclose($TRACE_LOG_HANDLE);
    }
    usage('Error: ' . $e->getMessage());
}
