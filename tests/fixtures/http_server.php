<?php

declare(strict_types=1);

// One-shot HTTP server for tests: binds an ephemeral port, prints the
// port number to stdout, serves exactly one request with the status
// code given as the first argument, then exits.

$statusCode = (int) ($argv[1] ?? 200);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "cannot bind: {$errstr}\n");
    exit(1);
}

$address = (string) stream_socket_get_name($server, false);
$port = substr($address, (int) strrpos($address, ':') + 1);

fwrite(STDOUT, $port . PHP_EOL);
fflush(STDOUT);

$conn = stream_socket_accept($server, 5.0);

if ($conn === false) {
    exit(1);
}

// Drain the request head: everything up to the blank line.
while (($line = fgets($conn)) !== false && trim($line) !== '') {
}

$body = 'netpulse-test';
$response = "HTTP/1.1 {$statusCode} Test\r\n"
    . 'Content-Length: ' . strlen($body) . "\r\n"
    . "Connection: close\r\n\r\n"
    . $body;

fwrite($conn, $response);
fclose($conn);
fclose($server);
