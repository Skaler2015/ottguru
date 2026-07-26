<?php
declare(strict_types=1);

/**
 * हर कॉल पर retry। यह वही सबक़ है जो चरण 0 के पहले रन में मिला था —
 * बिना retry 30 में से 8 titles चुपचाप "गायब" दिख गए थे।
 *
 * लौटाता है: ['ok'=>bool,'status'=>int,'data'=>array|null,'error'=>?string,'attempts'=>int]
 * ok=false का मतलब है "पता नहीं चला" — कभी "डेटा नहीं है" नहीं।
 */
function http_get_json(string $url, array $headers, array $httpCfg): array
{
    $timeout = (int) ($httpCfg['timeout'] ?? 25);
    $tries   = 1 + max(0, (int) ($httpCfg['retries'] ?? 2));
    $sleepMs = (int) ($httpCfg['retry_sleep'] ?? 1200);

    $last = ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'शुरू नहीं हुआ', 'attempts' => 0];

    for ($attempt = 1; $attempt <= $tries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_USERAGENT      => 'ottguru-sync/1.0 (+https://ottguru.in)',
            CURLOPT_ENCODING       => 'gzip',
        ]);
        $body   = curl_exec($ch);
        $curlEr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $last['status']   = $status;
        $last['attempts'] = $attempt;

        if ($body === false) {
            $last['ok']    = false;
            $last['error'] = 'नेटवर्क: ' . ($curlEr !== '' ? $curlEr : 'अज्ञात');
        } else {
            $data = json_decode((string) $body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $last['ok']    = false;
                $last['error'] = 'जवाब JSON नहीं था (HTTP ' . $status . ')';
            } elseif ($status >= 200 && $status < 300) {
                return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => null, 'attempts' => $attempt];
            } else {
                $msg = $data['status_message'] ?? ($data['message'] ?? ('HTTP ' . $status));
                $last['ok']    = false;
                $last['error'] = is_string($msg) ? $msg : ('HTTP ' . $status);
                $last['data']  = $data;
            }
        }

        // 4xx (429 छोड़कर) दोबारा कोशिश करने लायक़ नहीं — key गलत है या title नहीं है
        $worthRetry = ($status === 0 || $status === 429 || $status >= 500);
        if (!$worthRetry || $attempt === $tries) {
            break;
        }
        usleep($sleepMs * 1000 * $attempt);   // हर बार थोड़ा ज़्यादा रुकिए
    }

    return $last;
}
