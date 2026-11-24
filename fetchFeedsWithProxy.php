<?php
// fetchFeedsWithProxy.php
// CLI-only RSS-Aggregator mit parallelem Abruf (curl_multi + Fallback),
// additiver Speicherung, JSON-Verschlankung, Log-Rotation und Status-Tracking.

// CLI-only Schutz (Strato-kompatibel: erlaubt auch cgi-fcgi Cronjobs ohne REMOTE_ADDR)
if (PHP_SAPI !== 'cli' && !empty($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

date_default_timezone_set('Europe/Berlin');

define('USER_AGENT', 'Mozilla/5.0 (compatible; SumpfberichteFeedReader/1.0; +https://sumpfberichte.de)');
define('CONFIG_FILE', __DIR__ . '/feeds.config.json');
define('LOG_DIR', __DIR__ . '/logs');
define('LOG_FILE', LOG_DIR . '/feeds.log');
define('STATUS_FILE', LOG_DIR . '/feed_status.json');
define('LOCK_FILE', LOG_DIR . '/fetch.lock');
define('LOG_MAX_BYTES', 1024 * 1024); // 1 MB Rotation
define('MAX_FEED_BYTES', 5 * 1024 * 1024); // Max. 5 MB pro Feed-Antwort

$jsonFilePathMitte = __DIR__ . '/feeds_mitte.json';
$jsonFilePathBerlin = __DIR__ . '/feeds_berlin.json';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && ($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        $msg = sprintf("Shutdown Error: [%d] %s in %s on line %d", $err['type'], $err['message'], $err['file'], $err['line']);
        error_log($msg);
        writeFeedLog('CRITICAL', $msg);
    }
});

function ensureLogDir() {
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0755, true);
    }
}

function writeFeedLog($level, $message) {
    ensureLogDir();
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) >= LOG_MAX_BYTES) {
        @rename(LOG_FILE, LOG_FILE . '.1');
    }
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function writeJsonAtomic($filePath, $data, $pretty = false) {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0);
    $json = json_encode($data, $flags);
    if ($json === false) {
        throw new Exception("JSON encode failed for $filePath: " . json_last_error_msg());
    }
    $tmpFile = $filePath . '.tmp.' . getmypid();
    if (file_put_contents($tmpFile, $json, LOCK_EX) === false) {
        throw new Exception("Failed writing temp file $tmpFile");
    }
    if (!rename($tmpFile, $filePath)) {
        @unlink($tmpFile);
        throw new Exception("Failed moving $tmpFile to $filePath");
    }
}

function loadFeedStatus() {
    if (!file_exists(STATUS_FILE)) return [];
    $raw = @file_get_contents(STATUS_FILE);
    if ($raw === false) return [];
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}

function gzdecode_safe($data) {
    if (function_exists('gzdecode')) return @gzdecode($data);
    $len = strlen($data);
    if ($len < 18) return false;
    return @gzinflate(substr($data, 10, -8));
}

function cleanXmlString($content) {
    $trimmed = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content));
    $utf8 = @iconv('UTF-8', 'UTF-8//IGNORE', $trimmed);
    if ($utf8 === false) $utf8 = $trimmed;
    return preg_replace('/[^\x09\x0A\x0D\x20-\x{10FFFF}]+/u', '', $utf8);
}

function validateFeedResponse($content, $httpCode, $contentType, $method) {
    if ($httpCode !== null && $httpCode >= 400) {
        return ['success' => false, 'error' => "HTTP $httpCode", 'content' => $content, 'http_code' => $httpCode, 'content_type' => $contentType, 'method' => $method];
    }
    if (stripos($contentType ?? '', 'html') !== false || preg_match('/^\s*<\!doctype html|^\s*<html/i', substr($content, 0, 512))) {
        return ['success' => false, 'error' => 'HTML response', 'content' => $content, 'http_code' => $httpCode, 'content_type' => $contentType, 'method' => $method];
    }
    if (strlen($content) >= 2 && substr($content, 0, 2) === "\x1f\x8b") {
        $decoded = @gzdecode_safe($content);
        if ($decoded === false) {
            return ['success' => false, 'error' => 'GZIP decode failed', 'content' => $content, 'http_code' => $httpCode, 'content_type' => $contentType, 'method' => $method];
        }
        $content = $decoded;
    }
    return ['success' => true, 'content' => cleanXmlString($content), 'http_code' => $httpCode, 'content_type' => $contentType, 'method' => $method];
}

function fetchUrlFileGet($url) {
    $startedAt = microtime(true);
    for ($i = 1; $i <= 2; $i++) {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: " . USER_AGENT . "\r\nAccept: text/xml, application/rss+xml, application/xml, */*;q=0.1\r\nAccept-Language: de-DE,de;q=0.9\r\nAccept-Encoding: gzip, deflate\r\n",
                'timeout' => 20,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ];
        $content = @file_get_contents($url, false, stream_context_create($opts), 0, MAX_FEED_BYTES);

        $httpCode = null;
        $contentType = null;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\d+\.\d+\s+(\d{3})#i', $h, $m)) $httpCode = (int)$m[1];
                if (stripos($h, 'Content-Type:') === 0) $contentType = trim(substr($h, strlen('Content-Type:')));
            }
        }

        if ($content === false) {
            sleep($i);
            continue;
        }
        $res = validateFeedResponse($content, $httpCode, $contentType, 'file_get_contents');
        $res['duration_ms'] = (int)round((microtime(true) - $startedAt) * 1000);
        return $res;
    }
    return [
        'success' => false,
        'error' => 'Connection failed',
        'content' => null,
        'http_code' => null,
        'content_type' => null,
        'method' => 'file_get_contents',
        'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000)
    ];
}

function fetchUrlsParallel($urlsByName, $maxConcurrent = 5) {
    if (!function_exists('curl_multi_init')) {
        $results = [];
        foreach ($urlsByName as $name => $url) {
            $results[$name] = fetchUrlFileGet($url);
        }
        return $results;
    }

    // In kleinen Batches abrufen (schont Verbindungs-/Prozesslimits auf Shared-Hosting wie Strato)
    $allResults = [];
    foreach (array_chunk($urlsByName, $maxConcurrent, true) as $batch) {
        $mh = curl_multi_init();
        $handles = [];
        $startTimes = [];

        foreach ($batch as $name => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => USER_AGENT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_MAXFILESIZE => MAX_FEED_BYTES,
                CURLOPT_HTTPHEADER => [
                    'Accept: text/xml, application/rss+xml, application/xml, */*;q=0.1',
                    'Accept-Language: de-DE,de;q=0.9'
                ]
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$name] = $ch;
            $startTimes[$name] = microtime(true);
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($handles as $name => $ch) {
            $content = curl_multi_getcontent($ch);
            $curlErr = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: null;
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null;
            $totalTimeMs = (int)round((curl_getinfo($ch, CURLINFO_TOTAL_TIME) ?: (microtime(true) - $startTimes[$name])) * 1000);

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($curlErr !== '' || $content === false || $content === null) {
                $fallback = fetchUrlFileGet($batch[$name]);
                if ($fallback['success']) {
                    $allResults[$name] = $fallback;
                } else {
                    $allResults[$name] = [
                        'success' => false,
                        'error' => $curlErr !== '' ? "cURL: $curlErr" : ($fallback['error'] ?? 'Connection failed'),
                        'content' => null,
                        'http_code' => $httpCode,
                        'content_type' => $contentType,
                        'method' => 'curl_multi',
                        'duration_ms' => $totalTimeMs
                    ];
                }
                continue;
            }

            $res = validateFeedResponse($content, $httpCode, $contentType, 'curl_multi');
            $res['duration_ms'] = $totalTimeMs;
            $allResults[$name] = $res;
        }

        curl_multi_close($mh);
    }
    return $allResults;
}

function normalizeStringField($val) {
    if (is_string($val)) return trim($val);
    if (is_array($val)) {
        $flat = array_filter($val, 'is_string');
        return trim(implode(' ', $flat));
    }
    return '';
}

function cleanArticleTitle($rawTitle) {
    // Zeilenumbrüche und Mehrfach-Leerzeichen normalisieren
    $t = preg_replace('/[\r\n\t]+/u', ' ', (string)$rawTitle);
    $t = preg_replace('/\s{2,}/u', ' ', trim($t));

    // Paywall-Markierung "(+)" erkennen und aus dem Titel entfernen
    $paywall = false;
    if (preg_match('/^\(\+\)\s*/u', $t)) {
        $paywall = true;
        $t = preg_replace('/^\(\+\)\s*/u', '', $t);
    }

    // Typische FetchRSS-Präfixe sauber formatieren
    $t = preg_replace('/^(Update|Live)\s+(?!:)/u', '$1: ', $t);
    $t = preg_replace('/^meinung\s*[•·-]\s*/iu', 'Meinung: ', $t);

    return ['title' => trim($t), 'paywall' => $paywall];
}

function isFetchRssPlaceholderItem($title, $link) {
    $t = mb_strtolower(trim((string)$title), 'UTF-8');
    $l = trim((string)$link);
    if (preg_match('/^https?:\/\/(www\.)?fetchrss\.com\/?$/i', $l)) {
        return true;
    }
    return in_array($t, ['feed is deleted', 'this feed has been deleted', 'feed deleted', 'feed has been removed'], true);
}

function extractItemDate($item) {
    foreach (['pubDate', 'dc:date', 'isoDate', 'updated', 'published'] as $key) {
        if (!empty($item[$key])) {
            $str = normalizeStringField($item[$key]);
            if ($str !== '' && strtotime($str) !== false) {
                return $str;
            }
        }
    }
    return '';
}

function parseFeedXml(SimpleXMLElement $rss) {
    $channelLink = '';
    $items = [];

    // 1. RSS 2.0 (<rss><channel><item>)
    if (isset($rss->channel)) {
        $channelLink = trim((string)($rss->channel->link ?? ''));
        if (isset($rss->channel->item)) {
            foreach ($rss->channel->item as $item) {
                $dc = $item->children('http://purl.org/dc/elements/1.1/');
                $dcterms = $item->children('http://purl.org/dc/terms/');
                $pubDate = trim((string)($item->pubDate ?? ''));
                if ($pubDate === '' && isset($dc->date)) {
                    $pubDate = trim((string)$dc->date);
                }
                $accessRights = isset($dcterms->accessRights) ? strtolower(trim((string)$dcterms->accessRights)) : '';
                $isPaywall = in_array($accessRights, ['paid', 'subscription', 'paywall', 'plus'], true);
                $items[] = [
                    'title' => trim((string)($item->title ?? '')),
                    'link' => trim((string)($item->link ?? '')),
                    'pubDate' => $pubDate,
                    'paywall' => $isPaywall
                ];
            }
        }
        return ['channel_link' => $channelLink, 'items' => $items];
    }

    // 2. Atom 1.0 (<feed><entry>)
    if (isset($rss->entry)) {
        foreach ($rss->link as $l) {
            $attrs = $l->attributes();
            $rel = (string)($attrs['rel'] ?? 'alternate');
            if ($rel === 'alternate' && !empty($attrs['href'])) {
                $channelLink = trim((string)$attrs['href']);
                break;
            }
        }
        foreach ($rss->entry as $entry) {
            $link = '';
            foreach ($entry->link as $l) {
                $attrs = $l->attributes();
                $href = trim((string)($attrs['href'] ?? ''));
                $rel = (string)($attrs['rel'] ?? 'alternate');
                if ($href !== '' && ($rel === 'alternate' || $link === '')) {
                    $link = $href;
                    if ($rel === 'alternate') break;
                }
            }
            $pubDate = trim((string)($entry->published ?? ''));
            if ($pubDate === '') $pubDate = trim((string)($entry->updated ?? ''));
            $items[] = [
                'title' => trim((string)($entry->title ?? '')),
                'link' => $link,
                'pubDate' => $pubDate
            ];
        }
        return ['channel_link' => $channelLink, 'items' => $items];
    }

    // 3. RDF / RSS 1.0 (<rdf:RDF><item>)
    if (isset($rss->item)) {
        foreach ($rss->item as $item) {
            $dc = $item->children('http://purl.org/dc/elements/1.1/');
            $pubDate = isset($dc->date) ? trim((string)$dc->date) : '';
            $items[] = [
                'title' => trim((string)($item->title ?? '')),
                'link' => trim((string)($item->link ?? '')),
                'pubDate' => $pubDate
            ];
        }
    }

    return ['channel_link' => $channelLink, 'items' => $items];
}

function canonicalArticleKey($link, $title = '') {
    $link = trim((string)$link);
    if ($link === '') {
        return 'title:' . mb_strtolower(trim((string)$title), 'UTF-8');
    }

    $u = @parse_url($link);
    if ($u === false || empty($u['host'])) {
        return 'raw:' . $link;
    }

    $host = strtolower(preg_replace('/^www\./i', '', $u['host']));
    $path = rtrim($u['path'] ?? '', '/');
    $query = [];
    if (!empty($u['query'])) {
        parse_str($u['query'], $query);
    }

    // 1. UUID am Ende (z. B. Spiegel: ...-a-00fd7e0d-49c4-4708-949d-b44061171dda)
    if (preg_match('/-a-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $path, $m)) {
        return $host . ':uuid:' . strtolower($m[1]);
    }
    // 2. Morgenpost (/article413752542/slug.html)
    if (preg_match('/\/article(\d+)(?:\/|$)/i', $path, $m)) {
        return $host . ':article:' . $m[1];
    }
    // 3. TAZ (/Slug/!6230747)
    if (preg_match('/\/!(\d+)$/', $path, $m)) {
        return $host . ':taz:' . $m[1];
    }
    // 4. nd-aktuell (/artikel/1204053.slug.html)
    if (preg_match('/\/artikel\/(\d+)\./i', $path, $m)) {
        return $host . ':nd:' . $m[1];
    }
    // 5. Tagesspiegel & Berliner Zeitung (...-16315451.html oder ...-10619729 oder ...-li.2281234)
    if (preg_match('/(?:-|li\.)(\d{6,})(?:\.html?)?$/i', $path, $m)) {
        return $host . ':id:' . $m[1];
    }
    // 6. berlin.de Pressemitteilungen (pressemitteilung.1741449.php)
    if (preg_match('/pressemitteilung\.(\d+)\.php$/i', $path, $m)) {
        return $host . ':pm:' . $m[1];
    }
    // 7. Führende Artikel-ID im Slug (z. B. QM Brunnenstraße / Pankstraße: /14505-slug)
    if (preg_match('/\/(\d{4,})-[^\/]+$/', $path, $m)) {
        return $host . ':pre:' . $m[1];
    }
    // 8. Query-basierte Artikel-IDs (z. B. WordPress ?p=119245 oder TYPO3 tx_news_pi1[news]=357)
    if (!empty($query['p']) && is_scalar($query['p'])) {
        return $host . ':p:' . $query['p'];
    }
    if (!empty($query['tx_news_pi1']['news']) && is_scalar($query['tx_news_pi1']['news'])) {
        return $host . ':txnews:' . $query['tx_news_pi1']['news'];
    }

    // 9. Fallback: bereinigte URL ohne Schema, www, Tracking-Parameter, Fragment & Trailing Slash
    $stripParams = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'ref', 'source', 'cHash'];
    foreach ($stripParams as $sp) {
        unset($query[$sp]);
    }
    ksort($query);
    $cleanQuery = http_build_query($query);

    return $host . $path . ($cleanQuery !== '' ? '?' . $cleanQuery : '');
}

function loadAppConfig() {
    if (!file_exists(CONFIG_FILE)) {
        throw new Exception("Konfigurationsdatei fehlt: " . CONFIG_FILE);
    }
    $raw = file_get_contents(CONFIG_FILE);
    $cfg = json_decode($raw, true);
    if (!is_array($cfg) || empty($cfg['groups']) || !is_array($cfg['groups'])) {
        throw new Exception("Ungültige JSON-Konfiguration in " . CONFIG_FILE);
    }
    return $cfg;
}

function createJsonFile($configKey, $filePath, $feedConfigs, $keywords, $excludeKeywords, &$statusReport) {
    $currentConfig = $feedConfigs[$configKey];
    $results = [];
    $prevGroupStatus = $statusReport['groups'][$configKey] ?? [];
    $groupStatus = [];
    $stats = ['ok' => 0, 'stale' => 0, 'empty' => 0, 'error' => 0, 'total' => count($currentConfig['feeds'])];

    // Bestand laden
    $existingData = [];
    if (file_exists($filePath)) {
        $raw = @file_get_contents($filePath);
        if ($raw !== false) {
            $parsed = json_decode($raw, true);
            if (isset($parsed['feeds']) && is_array($parsed['feeds'])) $existingData = $parsed['feeds'];
        }
    }

    $daysToShow = (int)($currentConfig['days_to_show'] ?? 10);
    $maxItems = (int)($currentConfig['max_items'] ?? 100);
    $cutoffTimestamp = strtotime("today -$daysToShow days") ?: 0;

    // Alle URLs dieser Gruppe parallel abrufen
    $urlsToFetch = [];
    foreach ($currentConfig['feeds'] as $name => $entry) {
        $primaryUrl = is_array($entry) ? ($entry['url'] ?? null) : $entry;
        if (!empty($primaryUrl)) {
            $urlsToFetch[$name] = $primaryUrl;
        }
    }
    $fetchedResponses = fetchUrlsParallel($urlsToFetch);

    foreach ($currentConfig['feeds'] as $name => $entry) {
        $nowIso = date('c');
        $prev = $prevGroupStatus[$name] ?? [];
        $primaryUrl = is_array($entry) ? ($entry['url'] ?? null) : $entry;

        try {
            if (empty($primaryUrl)) throw new Exception("No URL configured");

            $fetch = $fetchedResponses[$name] ?? ['success' => false, 'error' => 'missing response', 'duration_ms' => 0];
            $durationMs = $fetch['duration_ms'] ?? 0;

            if ($fetch['success'] === false) {
                $errMsg = $fetch['error'] ?? 'unknown';
                $failures = ($prev['consecutive_failures'] ?? 0) + 1;
                $stats['error']++;
                $groupStatus[$name] = [
                    'url' => $primaryUrl,
                    'status' => 'error',
                    'http_code' => $fetch['http_code'] ?? null,
                    'method' => $fetch['method'] ?? null,
                    'duration_ms' => $durationMs,
                    'fetched_items' => 0,
                    'active_items' => 0,
                    'newest_item_date' => $prev['newest_item_date'] ?? null,
                    'last_checked' => $nowIso,
                    'last_success' => $prev['last_success'] ?? null,
                    'consecutive_failures' => $failures,
                    'consecutive_empty' => $prev['consecutive_empty'] ?? 0,
                    'last_error' => $errMsg
                ];
                writeFeedLog('ERROR', sprintf('[%s] %s: %s (HTTP: %s, Ausfälle in Folge: %d, %dms) | %s', $configKey, $name, $errMsg, $fetch['http_code'] ?? '-', $failures, $durationMs, $primaryUrl));
                $results[$name] = $existingData[$name] ?? "❌ Fehler: " . $errMsg;
                continue;
            }

            libxml_use_internal_errors(true);
            $rss = @simplexml_load_string($fetch['content'], "SimpleXMLElement", LIBXML_NOCDATA | LIBXML_NONET);
            if ($rss === FALSE) {
                $xmlErrors = libxml_get_errors();
                $xmlDetail = !empty($xmlErrors) ? trim($xmlErrors[0]->message) : 'Unbekannter XML-Fehler';
                libxml_clear_errors();
                $failures = ($prev['consecutive_failures'] ?? 0) + 1;
                $stats['error']++;
                $errMsg = "Ungültiges XML ($xmlDetail)";
                $groupStatus[$name] = [
                    'url' => $primaryUrl,
                    'status' => 'error',
                    'http_code' => $fetch['http_code'] ?? null,
                    'method' => $fetch['method'] ?? null,
                    'duration_ms' => $durationMs,
                    'fetched_items' => 0,
                    'active_items' => 0,
                    'newest_item_date' => $prev['newest_item_date'] ?? null,
                    'last_checked' => $nowIso,
                    'last_success' => $prev['last_success'] ?? null,
                    'consecutive_failures' => $failures,
                    'consecutive_empty' => $prev['consecutive_empty'] ?? 0,
                    'last_error' => $errMsg
                ];
                writeFeedLog('ERROR', sprintf('[%s] %s: %s (Ausfälle in Folge: %d, %dms) | %s', $configKey, $name, $errMsg, $failures, $durationMs, $primaryUrl));
                $results[$name] = $existingData[$name] ?? "❌ Fehler: Ungültiges XML";
                continue;
            }

            $parsedFeed = parseFeedXml($rss);
            $channelLink = $parsedFeed['channel_link'];
            $rawNewItems = $parsedFeed['items'];

            // Prüfen, ob FetchRSS nur eine "Feed is deleted"-Platzhaltermeldung liefert
            $placeholderDetected = false;
            $newItems = [];
            foreach ($rawNewItems as $ni) {
                $niTitle = normalizeStringField($ni['title'] ?? '');
                $niLink = normalizeStringField($ni['link'] ?? '');
                if (isFetchRssPlaceholderItem($niTitle, $niLink)) {
                    $placeholderDetected = true;
                    continue;
                }
                $newItems[] = $ni;
            }

            if ($placeholderDetected && count($newItems) === 0) {
                $failures = ($prev['consecutive_failures'] ?? 0) + 1;
                $stats['error']++;
                $errMsg = 'FetchRSS-Feed wurde gelöscht ("Feed is deleted")';
                $groupStatus[$name] = [
                    'url' => $primaryUrl,
                    'status' => 'error',
                    'http_code' => $fetch['http_code'] ?? 200,
                    'method' => $fetch['method'] ?? null,
                    'duration_ms' => $durationMs,
                    'fetched_items' => 0,
                    'active_items' => 0,
                    'newest_item_date' => $prev['newest_item_date'] ?? null,
                    'last_checked' => $nowIso,
                    'last_success' => $prev['last_success'] ?? null,
                    'consecutive_failures' => $failures,
                    'consecutive_empty' => $prev['consecutive_empty'] ?? 0,
                    'last_error' => $errMsg
                ];
                writeFeedLog('ERROR', sprintf('[%s] %s: %s (Ausfälle in Folge: %d, %dms) | %s', $configKey, $name, $errMsg, $failures, $durationMs, $primaryUrl));
                $results[$name] = ['channel' => ['item' => []]];
                continue;
            }

            $oldItems = isset($existingData[$name]['channel']['item']) ? ((is_array($existingData[$name]['channel']['item']) && isset($existingData[$name]['channel']['item'][0])) ? $existingData[$name]['channel']['item'] : [$existingData[$name]['channel']['item']]) : [];

            // Index der bestehenden Items (bewahrt das ursprüngliche Datum bei undatierten Artikeln)
            $oldDatesByHash = [];
            foreach ($oldItems as $oldItem) {
                if (empty($oldItem)) continue;
                $oldLink = normalizeStringField($oldItem['link'] ?? '');
                $oldTitle = normalizeStringField($oldItem['title'] ?? '');
                $oldHash = md5(canonicalArticleKey($oldLink, $oldTitle));
                $oldDate = extractItemDate($oldItem);
                if ($oldDate !== '') {
                    $oldDatesByHash[$oldHash] = $oldDate;
                }
            }

            $uniqueMap = [];
            foreach (array_merge($newItems, $oldItems) as $item) {
                if (empty($item)) continue;

                // FetchRSS-Bugfix: Überspringt "Monster-Items" (> 300 Zeichen Titel)
                $rawTitle = normalizeStringField($item['title'] ?? '');
                if ($rawTitle === '' || mb_strlen($rawTitle, 'UTF-8') > 300) {
                    continue;
                }
                $cleaned = cleanArticleTitle($rawTitle);
                $titleText = $cleaned['title'];
                $isPaywall = $cleaned['paywall'] || !empty($item['paywall']);
                if ($titleText === '') {
                    continue;
                }

                $linkText = normalizeStringField($item['link'] ?? '');
                if (isFetchRssPlaceholderItem($rawTitle, $linkText)) {
                    continue;
                }
                if ($linkText !== '' && !preg_match('/^https?:\/\//i', $linkText) && $channelLink !== '') {
                    $linkText = rtrim($channelLink, '/') . '/' . ltrim($linkText, '/');
                }

                // Kanonischer Fingerprint über Artikel-ID / bereinigte URL (fängt nachträgliche Titel-/Slug-Änderungen ab)
                $hash = md5(canonicalArticleKey($linkText, $titleText));

                if (!isset($uniqueMap[$hash])) {
                    $pubDate = extractItemDate($item);
                    if ($pubDate === '') {
                        // Einmaliges Erstfund-Datum statt täglichem "now"-Reset
                        $pubDate = $oldDatesByHash[$hash] ?? date(DATE_RSS);
                    }
                    // Verschlanktes Item: nur vom Frontend genutzte Felder speichern
                    $entryData = [
                        'title' => $titleText,
                        'link' => $linkText,
                        'pubDate' => $pubDate
                    ];
                    if ($isPaywall) {
                        $entryData['paywall'] = true;
                    }
                    $uniqueMap[$hash] = $entryData;
                }
            }

            $uniqueList = array_values($uniqueMap);
            usort($uniqueList, function($a, $b) {
                $dateA = strtotime($a['pubDate']) ?: 0;
                $dateB = strtotime($b['pubDate']) ?: 0;
                return $dateB - $dateA;
            });

            $filteredList = array_filter($uniqueList, function($item) use ($cutoffTimestamp) {
                $ts = strtotime($item['pubDate']);
                return $ts !== false && $ts >= $cutoffTimestamp;
            });

            $finalItems = array_slice(array_values($filteredList), 0, $maxItems);
            $results[$name] = [
                'channel' => [
                    'item' => $finalItems
                ]
            ];

            $fetchedCount = count($newItems);
            $activeCount = count($finalItems);

            // Neuestes Artikeldatum aus den abgerufenen Items ermitteln
            $newestTs = null;
            foreach ($newItems as $it) {
                $d = extractItemDate($it);
                if ($d !== '') {
                    $ts = strtotime($d);
                    if ($ts !== false && ($newestTs === null || $ts > $newestTs)) {
                        $newestTs = $ts;
                    }
                }
            }
            $newestItemDate = $newestTs ? date('Y-m-d', $newestTs) : null;

            if ($fetchedCount === 0) {
                $emptyStreak = ($prev['consecutive_empty'] ?? 0) + 1;
                $stats['empty']++;
                $groupStatus[$name] = [
                    'url' => $primaryUrl,
                    'status' => 'empty',
                    'http_code' => $fetch['http_code'] ?? 200,
                    'method' => $fetch['method'] ?? null,
                    'duration_ms' => $durationMs,
                    'fetched_items' => 0,
                    'active_items' => $activeCount,
                    'newest_item_date' => null,
                    'last_checked' => $nowIso,
                    'last_success' => $prev['last_success'] ?? null,
                    'consecutive_failures' => 0,
                    'consecutive_empty' => $emptyStreak,
                    'last_error' => 'Feed liefert 0 Einträge'
                ];
                writeFeedLog('WARN', sprintf('[%s] %s: Feed erreichbar (HTTP %s), liefert aber 0 Einträge (leer in Folge: %d, %dms) | %s', $configKey, $name, $fetch['http_code'] ?? '200', $emptyStreak, $durationMs, $primaryUrl));
            } elseif ($activeCount === 0) {
                $stats['stale']++;
                $staleMsg = sprintf('0 aktuelle Artikel in den letzten %d Tagen (%d geliefert, neuester: %s)', $daysToShow, $fetchedCount, $newestItemDate ?? 'unbekannt');
                $groupStatus[$name] = [
                    'url' => $primaryUrl,
                    'status' => 'stale',
                    'http_code' => $fetch['http_code'] ?? 200,
                    'method' => $fetch['method'] ?? null,
                    'duration_ms' => $durationMs,
                    'fetched_items' => $fetchedCount,
                    'active_items' => 0,
                    'newest_item_date' => $newestItemDate,
                    'last_checked' => $nowIso,
                    'last_success' => $prev['last_success'] ?? null,
                    'consecutive_failures' => 0,
                    'consecutive_empty' => 0,
                    'last_error' => $staleMsg
                ];
                writeFeedLog('WARN', sprintf('[%s] %s: Veraltet — %s (%dms) | %s', $configKey, $name, $staleMsg, $durationMs, $primaryUrl));
            } else {
                $stats['ok']++;
                if (($prev['consecutive_failures'] ?? 0) > 0) {
                    writeFeedLog('INFO', sprintf('[%s] %s: Wiederhergestellt nach %d Fehlern (%d Einträge, %dms)', $configKey, $name, $prev['consecutive_failures'], $fetchedCount, $durationMs));
                }
                $groupStatus[$name] = [
                    'url' => $primaryUrl,
                    'status' => 'ok',
                    'http_code' => $fetch['http_code'] ?? 200,
                    'method' => $fetch['method'] ?? null,
                    'duration_ms' => $durationMs,
                    'fetched_items' => $fetchedCount,
                    'active_items' => $activeCount,
                    'newest_item_date' => $newestItemDate,
                    'last_checked' => $nowIso,
                    'last_success' => $nowIso,
                    'consecutive_failures' => 0,
                    'consecutive_empty' => 0,
                    'last_error' => null
                ];
            }

        } catch (Exception $e) {
            $failures = ($prev['consecutive_failures'] ?? 0) + 1;
            $stats['error']++;
            $groupStatus[$name] = [
                'url' => $primaryUrl,
                'status' => 'error',
                'http_code' => null,
                'method' => null,
                'duration_ms' => 0,
                'fetched_items' => 0,
                'active_items' => 0,
                'newest_item_date' => $prev['newest_item_date'] ?? null,
                'last_checked' => $nowIso,
                'last_success' => $prev['last_success'] ?? null,
                'consecutive_failures' => $failures,
                'consecutive_empty' => $prev['consecutive_empty'] ?? 0,
                'last_error' => $e->getMessage()
            ];
            writeFeedLog('ERROR', sprintf('[%s] %s: Exception: %s (Ausfälle in Folge: %d)', $configKey, $name, $e->getMessage(), $failures));
            $results[$name] = $existingData[$name] ?? "❌ Fehler: " . $e->getMessage();
        }
    }

    $statusReport['groups'][$configKey] = $groupStatus;
    $statusReport['summary'][$configKey] = $stats;

    $output = [
        'config' => [
            'name' => $currentConfig['name'],
            'days_to_show' => $daysToShow,
            'max_items' => $maxItems,
            'last_updated' => date('c'),
            'keywords' => $keywords,
            'exclude_keywords' => $excludeKeywords
        ],
        'feeds' => $results
    ];
    // Minifiziertes JSON für schnellen Frontend-Abruf
    writeJsonAtomic($filePath, $output, false);
}

// CLI-Option: --summary-md gibt einen Markdown-Statusreport für GitHub Actions ($GITHUB_STEP_SUMMARY) aus
if (in_array('--summary-md', $argv ?? [], true)) {
    $status = loadFeedStatus();
    if (empty($status['groups'])) {
        echo "Noch kein Status-Log vorhanden.\n";
        exit(0);
    }
    echo "## Feed-Status (`" . ($status['last_run'] ?? 'unbekannt') . "`)\n\n";
    echo "Dauer: **" . ($status['duration_sec'] ?? '?') . " s**\n\n";
    foreach ($status['groups'] as $group => $feeds) {
        $s = $status['summary'][$group] ?? [];
        echo sprintf(
            "### %s — OK: %d | Veraltet: %d | Leer: %d | Fehler: %d\n\n",
            strtoupper($group),
            $s['ok'] ?? 0,
            $s['stale'] ?? 0,
            $s['empty'] ?? 0,
            $s['error'] ?? 0
        );
        $issues = array_filter($feeds, function($info) {
            return ($info['status'] ?? '') !== 'ok';
        });
        if (empty($issues)) {
            echo "Alle Quellen funktionieren normal.\n\n";
            continue;
        }
        echo "| Status | Quelle | Details | Ausfälle in Folge |\n";
        echo "| :--- | :--- | :--- | :---: |\n";
        foreach ($issues as $name => $info) {
            $badge = strtoupper($info['status'] ?? 'unknown');
            $detail = str_replace('|', '\\|', $info['last_error'] ?? '-');
            $fails = (int)($info['consecutive_failures'] ?? 0);
            echo sprintf("| `%s` | %s | %s | %d |\n", $badge, $name, $detail, $fails);
            if ($fails >= 3) {
                $safeErr = preg_replace('/[\r\n]+/', ' ', (string)($info['last_error'] ?? '-'));
                fwrite(STDERR, sprintf("::warning title=Feed defekt (%s)::Quelle '%s' ist %dx in Folge fehlgeschlagen: %s\n", $group, $name, $fails, $safeErr));
            }
        }
        echo "\n";
    }
    exit(0);
}

// CLI-Option: --status zeigt aktuellen Status aller fehlerhaften/veralteten/leeren Quellen ohne neuen Abruf
if (in_array('--status', $argv ?? [], true)) {
    $status = loadFeedStatus();
    if (empty($status['groups'])) {
        echo "Noch kein Status-Log vorhanden (" . STATUS_FILE . "). Bitte Skript einmal ohne --status ausführen.\n";
        exit(0);
    }
    echo "Letzter Lauf: " . ($status['last_run'] ?? 'unbekannt') . "\n\n";
    foreach ($status['groups'] as $group => $feeds) {
        $s = $status['summary'][$group] ?? [];
        echo sprintf("=== %s (OK: %d, Veraltet: %d, Leer: %d, Fehler: %d) ===\n", strtoupper($group), $s['ok'] ?? 0, $s['stale'] ?? 0, $s['empty'] ?? 0, $s['error'] ?? 0);
        foreach ($feeds as $name => $info) {
            if ($info['status'] !== 'ok') {
                echo sprintf(
                    "  [%s] %s — %s (Ausfälle: %d, Leer: %d, Letzter Erfolg: %s)\n",
                    strtoupper($info['status']),
                    $name,
                    $info['last_error'] ?? '-',
                    $info['consecutive_failures'] ?? 0,
                    $info['consecutive_empty'] ?? 0,
                    $info['last_success'] ?? 'nie'
                );
            }
        }
        echo "\n";
    }
    exit(0);
}

// Lockfile gegen überlappende Cronjob-Läufe
ensureLogDir();
$lockHandle = @fopen(LOCK_FILE, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    writeFeedLog('WARN', 'Abruf übersprungen: Ein anderer Cronjob-Lauf ist noch aktiv.');
    echo "Ein anderer Feed-Abruf läuft bereits.\n";
    exit(0);
}

$runStartedAt = microtime(true);
$statusReport = loadFeedStatus();
$statusReport['groups'] = $statusReport['groups'] ?? [];
$statusReport['summary'] = $statusReport['summary'] ?? [];

writeFeedLog('INFO', '=== Start Feed-Abruf (CLI) ===');

try {
    $appConfig = loadAppConfig();
    $feedConfigs = $appConfig['groups'];
    $keywords = $appConfig['keywords'] ?? [];
    $excludeKeywords = $appConfig['exclude_keywords'] ?? [];

    createJsonFile('mitte', $jsonFilePathMitte, $feedConfigs, $keywords, $excludeKeywords, $statusReport);
    createJsonFile('berlin', $jsonFilePathBerlin, $feedConfigs, $keywords, $excludeKeywords, $statusReport);

    $totalDuration = round(microtime(true) - $runStartedAt, 2);
    $statusReport['last_run'] = date('c');
    $statusReport['duration_sec'] = $totalDuration;
    writeJsonAtomic(STATUS_FILE, $statusReport, true);

    $sMitte = $statusReport['summary']['mitte'];
    $sBerlin = $statusReport['summary']['berlin'];
    $summaryLine = sprintf(
        'berlin: %d/%d OK (%d Fehler, %d veraltet, %d leer) | mitte: %d/%d OK (%d Fehler, %d veraltet, %d leer) | Dauer: %.2fs',
        $sBerlin['ok'], $sBerlin['total'], $sBerlin['error'], $sBerlin['stale'], $sBerlin['empty'],
        $sMitte['ok'], $sMitte['total'], $sMitte['error'], $sMitte['stale'], $sMitte['empty'],
        $totalDuration
    );
    writeFeedLog('SUMMARY', $summaryLine);

    echo "Feeds erfolgreich via CLI akkumuliert.\n$summaryLine\n";
    foreach (['berlin', 'mitte'] as $grp) {
        foreach ($statusReport['groups'][$grp] as $name => $info) {
            if ($info['status'] !== 'ok') {
                echo sprintf("  [%s] [%s] %s: %s\n", strtoupper($info['status']), $grp, $name, $info['last_error'] ?? '-');
            }
        }
    }
} catch (Exception $e) {
    writeFeedLog('CRITICAL', 'Abbruch: ' . $e->getMessage());
    fwrite(STDERR, "Fehler: " . $e->getMessage() . "\n");
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(1);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

