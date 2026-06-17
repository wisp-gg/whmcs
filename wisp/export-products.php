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

/**
 * Relay the token + application API key(s) to the panel's verify endpoint for each configured WISP server.
 */
function wisp_export_verify_token(string $token): bool
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
            CURLOPT_POSTFIELDS => http_build_query(['token' => $token]),
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
    $token = isset($_POST['token']) ? (string) $_POST['token'] : '';
    if ($token === '') {
        wisp_export_respond(401, ['error' => 'Missing token.']);
    }

    if (! wisp_export_verify_token($token)) {
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
        ];
    }

    wisp_export_respond(200, ['products' => $rows]);
} catch (\Throwable $e) {
    wisp_export_respond(500, ['error' => 'Export failed: ' . $e->getMessage()]);
}
