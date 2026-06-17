<?php

/**
 * WISP products export endpoint for the Server Templates importer.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

require_once __DIR__ . '/../../../init.php';

function wisp_export_respond(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/**
 * Validate the supplied API credential by letting WHMCS authenticate it against
 * its own API (and the GetProducts permission) via a loopback call to api.php.
 *
 * @return array{ok: bool, code?: int, error?: string}
 */
function wisp_export_validate(string $identifier, string $secret): array
{
    $systemUrl = (string) Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
    if ($systemUrl === '') {
        return ['ok' => false, 'code' => 500, 'error' => 'WHMCS SystemURL is not configured.'];
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => rtrim($systemUrl, '/') . '/includes/api.php',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'identifier' => $identifier,
            'secret' => $secret,
            'action' => 'GetProducts',
            'module' => 'wisp',
            'responsetype' => 'json',
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        return ['ok' => false, 'code' => 502, 'error' => 'Could not reach the WHMCS API to validate the credentials: ' . $curlError];
    }

    $data = json_decode($response, true);
    if (is_array($data) && ($data['result'] ?? '') === 'success') {
        return ['ok' => true];
    }

    $message = is_array($data) ? ($data['message'] ?? 'Authentication failed.') : 'Unexpected WHMCS API response.';

    return ['ok' => false, 'code' => 403, 'error' => 'WHMCS rejected the API credentials (check the identifier/secret and that the role allows GetProducts): ' . $message];
}

try {
    $identifier = isset($_POST['identifier']) ? (string) $_POST['identifier'] : '';
    $secret = isset($_POST['secret']) ? (string) $_POST['secret'] : '';
    if ($identifier === '' || $secret === '') {
        wisp_export_respond(401, ['error' => 'Missing API credentials.']);
    }

    $validation = wisp_export_validate($identifier, $secret);
    if (! $validation['ok']) {
        wisp_export_respond($validation['code'], ['error' => $validation['error']]);
    }

    // wisp_ConfigOptions() defines the positional configoptionN order.
    if (! function_exists('wisp_ConfigOptions')) {
        require_once __DIR__ . '/wisp.php';
    }

    $optionKeys = array_keys(wisp_ConfigOptions());

    $templatePos = array_search('server_template_id', $optionKeys, true);
    if ($templatePos === false) {
        wisp_export_respond(500, ['error' => 'WISP server module is outdated: it is missing the server_template_id option.']);
    }
    $templateColumn = 'configoption' . ($templatePos + 1);

    $products = Capsule::table('tblproducts')
        ->where('servertype', 'wisp')
        ->whereNotIn('hidden', ['on', '1'])
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
