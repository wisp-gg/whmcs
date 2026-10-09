<?php

/**
 * Shared panel round-trip auth for the standalone endpoints the WISP panel calls
 * (export-products.php, update-products.php). Defines functions only.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

if (! function_exists('wisp_ConfigOptions')) {
    require_once __DIR__ . '/wisp.php';
}

function wisp_export_respond(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

const WISP_IMPORT_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA3KjzpbfYtUs/1LPUUG/H
R4MP12K1OoXxMMrEgjxLDE8UsmVXXtB26dhfQ6fUuJLQOJQzCp5UQ/zplhR3l7o3
RN3JoGVON9FHhDo0eqD/p3VY/WqG3673ztLniEQRPvOPD9Jl/mhZ86CMDaAY/a6N
DzW4p3Ejp4met8MmtWrylWjhcqEhBejGEtaYye+2/+56nhJFXeVEsy5lDJt83sKd
71bKD0Mlh5tRJCUI8lqIiwz5JrUbpKrfCHoWC60f6/VmhBnNG5qhJdhwjTFH1rNE
RwV9Q9MSoZlB7qJV4hIW344DllMoRphT4ZZmhvHFD/YbSAC5f+tfL3VR6+nKfkjR
SQIDAQAB
-----END PUBLIC KEY-----
PEM;

function wisp_export_b64url_decode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }

    return (string) base64_decode(strtr($data, '-_', '+/'), true);
}

/**
 * Verify the panel-signed JWT locally with the embedded public key and return its
 * claims, or null when the signature, algorithm or expiry is not acceptable.
 */
function wisp_export_verify_jwt(string $jwt): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    [$headerB64, $payloadB64, $signatureB64] = $parts;

    $header = json_decode(wisp_export_b64url_decode($headerB64), true);
    if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
        return null;
    }

    $signature = wisp_export_b64url_decode($signatureB64);
    if ($signature === '') {
        return null;
    }

    if (openssl_verify($headerB64 . '.' . $payloadB64, $signature, WISP_IMPORT_PUBLIC_KEY, OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }

    $payload = json_decode(wisp_export_b64url_decode($payloadB64), true);
    if (! is_array($payload)) {
        return null;
    }

    // Require an expiry and reject stale tokens (+ small leeway for clock skew).
    if (! isset($payload['exp']) || time() > ((int) $payload['exp']) + 30) {
        return null;
    }

    return $payload;
}

/**
 * Relay the panel-signed JWT, authenticated with the stored application API key, to
 * the panel's verify endpoint for each configured WISP server.
 */
function wisp_export_relay_jwt(string $jwt): bool
{
    $servers = Capsule::table('tblservers')
        ->where('type', 'wisp')
        ->where('disabled', '!=', 1)
        ->get(['hostname', 'secure', 'password']);

    foreach ($servers as $server) {
        if (empty($server->hostname) || empty($server->password)) {
            continue;
        }

        $apiKey = decrypt($server->password);
        if ($apiKey === '') {
            continue;
        }

        try {
            $panelUrl = wisp_GetHostname([
                'serverhostname' => $server->hostname,
                'serversecure' => ($server->secure === 'on'),
            ]);
        } catch (\Throwable $e) {
            continue;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $panelUrl . '/api/application/server-templates/whmcs-import/verify',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['jwt' => $jwt]),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($curl);
        curl_close($curl);

        if ($response === false) {
            continue;
        }

        $data = json_decode($response, true);
        if (is_array($data) && ($data['valid'] ?? false) === true) {
            return true;
        }
    }

    return false;
}

/**
 * Option keys the panel's "Export to WHMCS" may clear so a product inherits the
 * value from its package. Anything else (the template identifier itself, the
 * server name, location and deploy-only settings) is never written.
 */
const WISP_EXPORT_CLEARABLE_KEYS = [
    'cpu', 'disk', 'memory', 'swap', 'io', 'egg_id', 'port_range', 'startup', 'image',
    'databases', 'oom_disabled', 'force_outgoing_ip', 'backup_megabytes_limit',
    'additional_ports', 'backup_count_limit', 'tags', 'cpu_period', 'cpu_priority', 'cpu_burst',
];

/**
 * The clearable keys this module version actually has a product column for,
 * mapped to that column (configoptionN, by the module's own option order).
 *
 * @return array<string, string>
 */
function wisp_export_clearable_columns(): array
{
    $columns = [];
    foreach (array_keys(wisp_ConfigOptions()) as $i => $key) {
        if (in_array($key, WISP_EXPORT_CLEARABLE_KEYS, true)) {
            $columns[$key] = 'configoption' . ($i + 1);
        }
    }

    return $columns;
}

function wisp_export_template_column(): ?string
{
    $position = array_search('server_template_id', array_keys(wisp_ConfigOptions()), true);

    return $position === false ? null : 'configoption' . ($position + 1);
}
