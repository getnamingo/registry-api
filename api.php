<?php

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Namingo\Rately\Rately;

$c = require_once 'config.php';
require_once 'helpers.php';
$logFilePath = '/var/log/namingo/api.log';
$log = setupLogger($logFilePath, 'API');

// Initialize the PDO connection pool
$pool = new Swoole\Database\PDOPool(
    (new Swoole\Database\PDOConfig())
        ->withDriver($c['db_type'])
        ->withHost($c['db_host'])
        ->withPort($c['db_port'])
        ->withDbName($c['db_database'])
        ->withUsername($c['db_username'])
        ->withPassword($c['db_password'])
        ->withCharset('utf8mb4')
);

$http = new Server("0.0.0.0", 8080);

// Uncomment and configure for HTTP2/SSL
// $http->set([
//     'open_http2_protocol' => true,
//     'ssl_cert_file' => '/path/to/ssl_cert.crt',
//     'ssl_key_file' => '/path/to/ssl_key.key',
// ]);

$rateLimiter = new Rately();

$http->on("start", function (Server $server) use ($log) {
    $log->info('API server is started');
});

$http->on("request", function (Request $request, Response $response) use ($c, $pool, $log, $rateLimiter) {
    $path = $request->server['request_uri'];
    $query = [];
    parse_str($request->server['query_string'] ?? '', $query);
    
    $remoteAddr = $request->server['remote_addr'];
    if (($c['rately'] == true) && ($rateLimiter->isRateLimited('api', $remoteAddr, $c['limit'], $c['period']))) {
        $log->error('rate limit exceeded for ' . $remoteAddr);
        $data = ['status' => 'error', 'message' => 'Rate limit exceeded. Please try again later.'];
        $response->status(400);
        $response->header("Content-Type", "application/json");
        $response->end(json_encode($data));
        return;
    }

    $pdo = $pool->get();

    switch ($path) {
        case '/availability':
            $domain = isset($query['domain']) ? trim($query['domain']) : null;
            if ($domain) {
                if (strlen($domain) > 68) {
                    $data = ['status' => 'error', 'message' => 'Domain name is too long.'];
                    $response->status(400);
                    $response->header("Content-Type", "application/json");
                    $response->end(json_encode($data));
                    return;
                }

                if (!mb_detect_encoding($domain, 'ASCII', true)) {
                    $convertedDomain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                    if ($convertedDomain === false) {
                        $data = ['status' => 'error', 'message' => 'Domain conversion to Punycode failed.'];
                        $response->status(400);
                    } else {
                        $domain = $convertedDomain;
                    }
                }

                if (!preg_match('/^(?:(xn--[a-zA-Z0-9-]{1,63}|[a-zA-Z0-9-]{1,63})\.){1,3}(xn--[a-zA-Z0-9-]{2,63}|[a-zA-Z]{2,63})$/', $domain)) {
                    $data = ['status' => 'error', 'message' => 'Domain name invalid format.'];
                    $response->status(400);
                    $response->header("Content-Type", "application/json");
                    $response->end(json_encode($data));
                    return;
                }

                // Extract TLD from the domain and prepend a dot
                $parts = explode('.', $domain);
                $tld = "." . end($parts);

                // Check if the TLD exists in the domain_tld table
                $stmtTLD = $pdo->prepare("SELECT COUNT(*) FROM domain_tld WHERE tld = :tld");
                $stmtTLD->bindParam(':tld', $tld, PDO::PARAM_STR);
                $stmtTLD->execute();
                $tldExists = $stmtTLD->fetchColumn();

                if (!$tldExists) {
                    $data = ['status' => 'error', 'message' => 'Invalid TLD. Please search only allowed TLDs.'];
                    $response->status(400);
                    $response->header("Content-Type", "application/json");
                    $response->end(json_encode($data));
                    return;
                }
                
                // Check if domain is reserved
                $stmtReserved = $pdo->prepare("SELECT id FROM reserved_domain_names WHERE name = ? LIMIT 1");
                $stmtReserved->execute([$parts[0]]);
                $domain_already_reserved = $stmtReserved->fetchColumn();

                if ($domain_already_reserved) {
                    $data = ['status' => 'error', 'message' => 'Domain name is reserved or restricted.'];
                    $response->status(400);
                    $response->header("Content-Type", "application/json");
                    $response->end(json_encode($data));
                    return;
                }

                // Fetch the IDN regex for the given TLD
                $stmtRegex = $pdo->prepare("SELECT idn_table FROM domain_tld WHERE tld = :tld");
                $stmtRegex->bindParam(':tld', $tld, PDO::PARAM_STR);
                $stmtRegex->execute();
                $idnRegex = $stmtRegex->fetchColumn();

                if (!$idnRegex) {
                    $data = ['status' => 'error', 'message' => 'Failed to fetch domain IDN table.'];
                    $response->status(400);
                    $response->header("Content-Type", "application/json");
                    $response->end(json_encode($data));
                    return;
                }

                // Check for invalid characters using fetched regex
                if (strpos(strtolower($parts[0]), 'xn--') === 0) {
                    $label = idn_to_utf8(strtolower($parts[0]), IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                } else {
                    $label = strtolower($parts[0]);
                }
                if (!preg_match($idnRegex, $label)) {
                    $data = ['status' => 'error', 'message' => 'Domain name invalid IDN characters.'];
                    $response->status(400);
                    $response->header("Content-Type", "application/json");
                    $response->end(json_encode($data));
                    return;
                }
                
                $query = "SELECT name FROM registry.domain WHERE name = :domain";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
                $stmt->execute();

                if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $data = ['status' => 'info', 'message' => 'Domain is not available', 'domain' => $domain];
                    $response->header("Content-Type", "application/json");
                    $response->end(json_encode($data));
                    return;
                } else {
                    $data = ['status' => 'success', 'message' => 'Domain is available', 'domain' => $domain];
                    $response->header("Content-Type", "application/json");
                    $response->end(json_encode($data));
                    return;
                }
            } else {
                $data = ['status' => 'error', 'message' => 'Domain parameter is missing.'];
                $response->status(400);
                $response->header("Content-Type", "application/json");
                $response->end(json_encode($data));
                return;
            }
        case '/droplist':
            $now = new DateTime();
            $ninetyDaysLater = (new DateTime())->modify('+90 days')->format('Y-m-d H:i:s');

            $sql = "SELECT name, crdate, exdate, delTime, resTime FROM domain WHERE exdate <= :ninetyDaysLater ORDER BY exdate ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['ninetyDaysLater' => $ninetyDaysLater]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Process results
            $data = array_map(function ($row) {
                $delTime = new DateTime($row['delTime']);
                $drops = $delTime->modify('+35 days')->format('Y-m-d H:i:s');

                return [
                    'domain' => $row['name'],
                    'created' => $row['crdate'],
                    'expires' => $row['exdate'],
                    'deletion' => $row['delTime'],
                    'restored' => $row['resTime'],
                    'drops' => $drops,
                ];
            }, $results);

            // Set response header
            $response->header("Content-Type", "application/json");
            $response->end(json_encode($data));
            return;
        default:
            $data = ['status' => 'error', 'message' => 'Not found.'];
            $response->status(404);
            $response->header("Content-Type", "application/json");
            $response->end(json_encode($data));
            return;
    }

    $pool->put($pdo);

});

$http->start();
