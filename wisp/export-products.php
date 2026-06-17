<?php

/**
 * WISP products export endpoint for the Server Templates importer.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

require_once __DIR__ . '/../../../init.php';

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
 * Verify the panel-signed JWT locally with the embedded public key.
 */
function wisp_export_verify_jwt(string $jwt): bool
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return false;
    }
    [$headerB64, $payloadB64, $signatureB64] = $parts;

    $header = json_decode(wisp_export_b64url_decode($headerB64), true);
    if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
        return false;
    }

    $signature = wisp_export_b64url_decode($signatureB64);
    if ($signature === '') {
        return false;
    }

    if (openssl_verify($headerB64 . '.' . $payloadB64, $signature, WISP_IMPORT_PUBLIC_KEY, OPENSSL_ALGO_SHA256) !== 1) {
        return false;
    }

    $payload = json_decode(wisp_export_b64url_decode($payloadB64), true);
    if (! is_array($payload)) {
        return false;
    }

    // Require an expiry and reject stale tokens (+ small leeway for clock skew).
    return isset($payload['exp']) && time() <= ((int) $payload['exp']) + 30;
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

try {
    $jwt = isset($_POST['jwt']) ? (string) $_POST['jwt'] : '';
    if ($jwt === '') {
        wisp_export_respond(401, ['error' => 'Missing token.']);
    }

    // Verify the panel's signature locally first.
    if (! wisp_export_verify_jwt($jwt)) {
        wisp_export_respond(403, ['error' => 'This import request was not signed by a recognised WISP panel.']);
    }

    if (! wisp_export_relay_jwt($jwt)) {
        wisp_export_respond(403, ['error' => 'The panel did not recognise this import request, or there are insufficient permissions on the application API key.']);
    }

    $optionKeys = array_keys(wisp_ConfigOptions());

    $templatePos = array_search('server_template_id', $optionKeys, true);
    if ($templatePos === false) {
        wisp_export_respond(500, ['error' => 'WISP server module is outdated: it is missing the server_template_id option.']);
    }
    $templateColumn = 'configoption' . ($templatePos + 1);

    $products = Capsule::table('tblproducts')
        ->where('servertype', 'wisp')
        ->where('hidden', 0)
        ->where($templateColumn, '!=', '')
        ->get();

    // Collect the live (active/suspended) service ids per product in a single
    // query. The panel resolves these to its own servers via external_id and
    // offers to attach them to the product's server template.
    $servicesByProduct = [];
    if ($products->isNotEmpty()) {
        $services = Capsule::table('tblhosting')
            ->whereIn('packageid', $products->pluck('id')->all())
            ->whereIn('domainstatus', ['Active', 'Suspended'])
            ->get(['id', 'packageid']);
        foreach ($services as $service) {
            $servicesByProduct[(int) $service->packageid][] = (string) $service->id;
        }
    }

    $rows = [];
    foreach ($products as $product) {
        $options = [];
        foreach ($optionKeys as $i => $key) {
            $column = 'configoption' . ($i + 1);
            $options[$key] = isset($product->{$column}) ? $product->{$column} : '';
        }

        $rows[] = [
            'pid' => (int) $product->id,
            'name' => $product->name,
            'options' => $options,
            'services' => $servicesByProduct[(int) $product->id] ?? [],
        ];
    }

    wisp_export_respond(200, ['products' => $rows]);
} catch (\Throwable $e) {
    wisp_export_respond(500, ['error' => 'Export failed: ' . $e->getMessage()]);
}
