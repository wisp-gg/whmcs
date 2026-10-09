<?php

/**
 * WISP products export endpoint for the Server Templates importer.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

require_once __DIR__ . '/../../../init.php';

require_once __DIR__ . '/panel-auth.php';

function wisp_export_friendly_key_map(): array
{
    $map = ['allocations' => 'allocations'];

    foreach (wisp_ConfigOptions() as $key => $def) {
        $map[strtolower(trim($key))] = $key;

        if (isset($def['FriendlyName'])) {
            $map[strtolower(trim($def['FriendlyName']))] = $key;
        }
    }

    return $map;
}

function wisp_export_resolve_configurable(array $optionNames, array $friendlyToKey): array
{
    $fields = [];
    $variables = [];

    foreach ($optionNames as $name) {
        $identifier = trim((string) $name);
        if (strpos($identifier, '|') !== false) {
            $identifier = trim(explode('|', $identifier, 2)[0]);
        }

        if ($identifier === '') {
            continue;
        }

        $key = $friendlyToKey[strtolower($identifier)] ?? null;
        if ($key !== null) {
            $fields[] = $key;
        } else {
            $variables[] = $identifier;
        }
    }

    return [
        'fields' => array_values(array_unique($fields)),
        'variables' => array_values(array_unique($variables)),
    ];
}

try {
    $jwt = isset($_POST['jwt']) ? (string) $_POST['jwt'] : '';
    if ($jwt === '') {
        wisp_export_respond(401, ['error' => 'Missing token.']);
    }

    // Verify the panel's signature locally first.
    if (wisp_export_verify_jwt($jwt) === null) {
        wisp_export_respond(403, ['error' => 'This import request was not signed by a recognised WISP panel.']);
    }

    if (! wisp_export_relay_jwt($jwt)) {
        wisp_export_respond(403, ['error' => 'The panel did not recognise this import request, or there are insufficient permissions on the application API key.']);
    }

    $optionKeys = array_keys(wisp_ConfigOptions());

    $templateColumn = wisp_export_template_column();
    if ($templateColumn === null) {
        wisp_export_respond(500, ['error' => 'WISP server module is outdated: it is missing the server_template_id option.']);
    }

    $products = Capsule::table('tblproducts')
        ->where('servertype', 'wisp')
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

    $configOptionsByProduct = [];
    if ($products->isNotEmpty()) {
        $configOptions = Capsule::table('tblproductconfiglinks as pcl')
            ->join('tblproductconfigoptions as pco', 'pco.gid', '=', 'pcl.gid')
            ->whereIn('pcl.pid', $products->pluck('id')->all())
            ->get(['pcl.pid as pid', 'pco.optionname as optionname']);
        foreach ($configOptions as $configOption) {
            $configOptionsByProduct[(int) $configOption->pid][] = (string) $configOption->optionname;
        }
    }

    $friendlyToKey = wisp_export_friendly_key_map();

    $rows = [];
    foreach ($products as $product) {
        $options = [];
        foreach ($optionKeys as $i => $key) {
            $column = 'configoption' . ($i + 1);
            $options[$key] = isset($product->{$column}) ? $product->{$column} : '';
        }

        $configurable = wisp_export_resolve_configurable(
            $configOptionsByProduct[(int) $product->id] ?? [],
            $friendlyToKey,
        );

        $rows[] = [
            'pid' => (int) $product->id,
            'name' => $product->name,
            'options' => $options,
            'configurable_fields' => $configurable['fields'],
            'configurable_variables' => $configurable['variables'],
            'services' => $servicesByProduct[(int) $product->id] ?? [],
        ];
    }

    wisp_export_respond(200, [
        'products' => $rows,
        // Lets the panel tell a module that can take "Export to WHMCS" writes from
        // an older one that only serves the import.
        'capabilities' => ['update-products'],
        'writable_keys' => array_keys(wisp_export_clearable_columns()),
    ]);
} catch (\Throwable $e) {
    wisp_export_respond(500, ['error' => 'Export failed: ' . $e->getMessage()]);
}
