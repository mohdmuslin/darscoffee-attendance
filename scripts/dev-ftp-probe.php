<?php

/*
 * Diagnostic: which FTPS modes does the host actually offer?
 *
 * A deploy failed with `ERR_SSL_TLSV1_ALERT_DECODE_ERROR` on the DATA socket. The control
 * connection plainly worked (it authenticated and listed files), so the TLS problem is on the
 * data channel — the classic symptom of a server requiring TLS SESSION REUSE, which Node's
 * FTP client does not do.
 *
 * This checks what the server supports WITHOUT logging in: no credentials, so it is safe to
 * run and reveals nothing. Delete afterwards.
 *
 *   php scripts/dev-ftp-probe.php
 */

$host = 'ftp.mwstay.com';

/** Open a socket, returning [stream, error]. */
function connect(string $host, int $port, float $timeout = 8.0): array
{
    $errno = 0;
    $errstr = '';
    $start = microtime(true);

    $stream = @stream_socket_client(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
    );

    $ms = (int) round((microtime(true) - $start) * 1000);

    return [$stream, $errno, $errstr, $ms];
}

echo "Probing {$host}".PHP_EOL;
echo str_repeat('-', 60).PHP_EOL;

// ---- Port 21: is AUTH TLS accepted? ---------------------------------------

echo PHP_EOL.'[1] Port 21 — explicit FTPS (AUTH TLS)'.PHP_EOL;

[$stream, $errno, $errstr, $ms] = connect($host, 21);

if ($stream === false) {
    echo "    UNREACHABLE ({$ms}ms): {$errstr} ({$errno})".PHP_EOL;
} else {
    echo "    connected in {$ms}ms".PHP_EOL;

    $banner = fgets($stream, 512);
    echo '    banner: '.trim((string) $banner).PHP_EOL;

    fwrite($stream, "AUTH TLS\r\n");
    $reply = fgets($stream, 512);
    echo '    AUTH TLS -> '.trim((string) $reply).PHP_EOL;

    $code = substr(trim((string) $reply), 0, 3);
    echo $code === '234'
        ? '    => explicit FTPS IS offered'.PHP_EOL
        : '    => explicit FTPS NOT offered (or refused)'.PHP_EOL;

    // Which FEAT extensions does it advertise? FEAT needs no login on most servers.
    fwrite($stream, "FEAT\r\n");
    $feat = '';
    while (($line = fgets($stream, 512)) !== false) {
        $feat .= $line;
        if (str_starts_with(trim($line), '211 ')) {
            break;
        }
    }
    echo '    FEAT: '.preg_replace('/\s+/', ' ', trim($feat)).PHP_EOL;

    fclose($stream);
}

// ---- Port 990: implicit FTPS? ---------------------------------------------

echo PHP_EOL.'[2] Port 990 — implicit FTPS'.PHP_EOL;

[$stream, $errno, $errstr, $ms] = connect($host, 990, 5.0);

if ($stream === false) {
    echo "    CLOSED/UNREACHABLE ({$ms}ms): {$errstr} ({$errno})".PHP_EOL;
    echo '    => implicit FTPS (ftps-legacy) is NOT available'.PHP_EOL;
} else {
    echo "    port OPEN ({$ms}ms) — attempting TLS handshake".PHP_EOL;

    stream_set_blocking($stream, true);

    $ok = @stream_socket_enable_crypto(
        $stream,
        true,
        STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
    );

    echo $ok === true
        ? '    => implicit FTPS handshake OK'.PHP_EOL
        : '    => port open but TLS handshake failed'.PHP_EOL;

    fclose($stream);
}

// ---- Port 21: plain FTP reachable? ----------------------------------------

echo PHP_EOL.'[3] Port 21 — plain FTP (no encryption)'.PHP_EOL;
echo '    the banner above proves a control connection works;'.PHP_EOL;
echo '    plain FTP needs no AUTH TLS, so it would succeed where FTPS fails.'.PHP_EOL;
