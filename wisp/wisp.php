<?php

/**
MIT License

Copyright (c) 2018-2019 Stepan Fedotov <stepan@wisp.gg>

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
**/

if(!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use Illuminate\Database\Capsule\Manager as Capsule;

function wisp_GetHostname(array $params) {
    $hostname = $params['serverhostname'];
    if ($hostname === '') throw new Exception('Could not find the panel\'s hostname - did you configure server group for the product?');

    // For whatever reason, WHMCS converts some characters of the hostname to their literal meanings (- => dash, etc) in some cases
    foreach([
        'DOT' => '.',
        'DASH' => '-',
    ] as $from => $to) {
        $hostname = str_replace($from, $to, $hostname);
    }

    if(ip2long($hostname) !== false) $hostname = 'http://' . $hostname;
    else $hostname = ($params['serversecure'] ? 'https://' : 'http://') . $hostname;

    return rtrim($hostname, '/');
}

function wisp_API(array $params, $endpoint, array $data = [], $method = "GET", $dontLog = false) {
    $url = wisp_GetHostname($params) . '/api/application/' . $endpoint;

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    curl_setopt($curl, CURLOPT_USERAGENT, "WISP-WHMCS");
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_POSTREDIR, CURL_REDIR_POST_301);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);

    $headers = [
        "Authorization: Bearer " . $params['serverpassword'],
        "Accept: Application/vnd.wisp.v1+json",
    ];

    if($method === 'POST' || $method === 'PATCH') {
        $jsonData = json_encode($data);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
        array_push($headers, "Content-Type: application/json");
        array_push($headers, "Content-Length: " . strlen($jsonData));
    }

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($curl);
    $responseData = json_decode($response, true);
    $responseData['status_code'] = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    if($responseData['status_code'] === 0 && !$dontLog) logModuleCall("WISP-WHMCS", "CURL ERROR", curl_error($curl), "");

    curl_close($curl);

    if(!$dontLog) logModuleCall("WISP-WHMCS", $method . " - " . $url,
        isset($data) ? json_encode($data) : "",
        print_r($responseData, true));

    return $responseData;
}

function wisp_Error($func, $params, Exception $err) {
    logModuleCall("WISP-WHMCS", $func, $params, $err->getMessage(), $err->getTraceAsString());
}

function wisp_MetaData() {
    return [
        "DisplayName" => "WISP",
        "APIVersion" => "1.1",
        "RequiresServer" => true,
    ];
}

function wisp_ConfigOptions() {
    return [
        "cpu" => [
            "FriendlyName" => "CPU Limit (%)",
            "Description" => "Amount of CPU to assign to the created server.",
            "Type" => "text",
            "Size" => 10,
        ],
        "disk" => [
            "FriendlyName" => "Disk Space (MB)",
            "Description" => "Amount of Disk Space to assign to the created server.",
            "Type" => "text",
            "Size" => 10,
        ],
        "memory" => [
            "FriendlyName" => "Memory (MB)",
            "Description" => "Amount of Memory to assign to the created server.",
            "Type" => "text",
            "Size" => 10,
        ],
        "swap" => [
            "FriendlyName" => "Swap (MB)",
            "Description" => "Amount of Swap to assign to the created server.",
            "Type" => "text",
            "Size" => 10,
        ],
        "location_id" => [
            "FriendlyName" => "Location ID",
            "Description" => "ID of the Location to automatically deploy to.",
            "Type" => "text",
            "Size" => 10,
        ],
        "dedicated_ip" => [
            "FriendlyName" => "Dedicated IP",
            "Description" => "Assign dedicated ip to the server (optional)",
            "Type" => "yesno",
        ],
        "nest_id" => [
            "FriendlyName" => "Nest ID",
            "Description" => "ID of the Nest for the server to use.",
            "Type" => "text",
            "Size" => 10,
        ],
        "egg_id" => [
            "FriendlyName" => "Egg ID",
            "Description" => "ID of the Egg for the server to use.",
            "Type" => "text",
            "Size" => 10,
        ],
        "io" => [
            "FriendlyName" => "Block IO Weight",
            "Description" => "Block IO Adjustment number (10-1000)",
            "Type" => "text",
            "Size" => 10,
            "Default" => "500",
        ],
        "pack_id" => [
            "FriendlyName" => "Pack ID",
            "Description" => "ID of the Pack to install the server with (optional)",
            "Type" => "text",
            "Size" => 10,
        ],
        "port_range" => [
            "FriendlyName" => "Port Range",
            "Description" => "Port ranges seperated by comma to assign to the server (Example: 25565-25570,25580-25590) (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
        "startup" => [
            "FriendlyName" => "Startup",
            "Description" => "Custom startup command to assign to the created server (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
        "image" => [
            "FriendlyName" => "Image",
            "Description" => "Custom Docker image to assign to the created server (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
        "databases" => [
            "FriendlyName" => "Databases",
            "Description" => "Client will be able to create this amount of databases for their server (optional)",
            "Type" => "text",
            "Size" => 10,
        ],
	    "server_name" => [
            "FriendlyName" => "Server Name",
            "Description" => "The name of the server as shown on the panel (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
        "oom_disabled" => [
            "FriendlyName" => "Disable OOM Killer",
            "Description" => "Should the Out Of Memory Killer be disabled (optional)",
            "Type" => "yesno",
        ],
        "backup_megabytes_limit" => [
            "FriendlyName" => "Backup Size Limit",
            "Description" => "Amount in megabytes the server can use for backups (optional)",
            "Type" => "text",
            "Size" => 25,
        ],
    ];
}

function wisp_TestConnection(array $params) {
    $solutions = [
        0 => "Check module debug log for more detailed error.",
        401 => "Authorization header either missing or not provided.",
        403 => "Double check the password (which should be the Application Key).",
        404 => "Result not found.",
        422 => "Validation error.",
        500 => "Panel errored, check panel logs.",
    ];

    $err = "";
    try {
        $response = wisp_API($params, 'nodes');

        if($response['status_code'] !== 200) {
            $status_code = $response['status_code'];
            $err = "Invalid status_code received: " . $status_code . ". Possible solutions: "
                . (isset($solutions[$status_code]) ? $solutions[$status_code] : "None.");
        } else {
            if($response['meta']['pagination']['count'] === 0) {
                $err = "Authentication successful, but no nodes are available.";
            }
        }
    } catch(Exception $e) {
        wisp_Error(__FUNCTION__, $params, $e);
        $err = $e->getMessage();
    }

    return [
        "success" => empty($err),
        "error" => $err,
    ];
}

function wisp_GetOption(array $params, $id, $default = NULL) {
    $options = wisp_ConfigOptions();

    $friendlyName = $options[$id]['FriendlyName'];
    if(isset($params['configoptions'][$friendlyName]) && $params['configoptions'][$friendlyName] !== '') {
        return $params['configoptions'][$friendlyName];
    } else if(isset($params['configoptions'][$id]) && $params['configoptions'][$id] !== '') {
        return $params['configoptions'][$id];
    } else if(isset($params['customfields'][$friendlyName]) && $params['customfields'][$friendlyName] !== '') {
        return $params['customfields'][$friendlyName];
    } else if(isset($params['customfields'][$id]) && $params['customfields'][$id] !== '') {
        return $params['customfields'][$id];
    }

    $found = false;
    $i = 0;
    foreach(wisp_ConfigOptions() as $key => $value) {
        $i++;
        if($key === $id) {
            $found = true;
            break;
        }
    }

    if($found && isset($params['configoption' . $i]) && $params['configoption' . $i] !== '') {
        return $params['configoption' . $i];
    }

    return $default;
}

function wisp_CreateAccount(array $params) {
    try {
        $serverId = wisp_GetServerID($params);
        if(isset($serverId)) throw new Exception('Failed to create server because it is already created.');

        $userResult = wisp_API($params, 'users/external/' . $params['clientsdetails']['uuid']);
        if($userResult['status_code'] === 404) {
            $userResult = wisp_API($params, 'users?search=' . urlencode($params['clientsdetails']['email']));
            if($userResult['meta']['pagination']['total'] === 0) {
                $userResult = wisp_API($params, 'users', [
                    'email' => $params['clientsdetails']['email'],
                    'first_name' => $params['clientsdetails']['firstname'],
                    'last_name' => $params['clientsdetails']['lastname'],
                    'external_id' => $params['clientsdetails']['uuid'],
                ], 'POST');
            } else {
                foreach($userResult['data'] as $key => $value) {
                    if($value['attributes']['email'] === $params['clientsdetails']['email']) {
                        $userResult = array_merge($userResult, $value);
                        break;
                    }
                }
                $userResult = array_merge($userResult, $userResult['data'][0]);
            }
        }

        if($userResult['status_code'] === 200 || $userResult['status_code'] === 201) {
            $userId = $userResult['attributes']['id'];
        } else {
            throw new Exception('Failed to create user, received error code: ' . $userResult['status_code'] . '. Enable module debug log for more info.');
        }

        $nestId = wisp_GetOption($params, 'nest_id');
        $eggId = wisp_GetOption($params, 'egg_id');

        $eggData = wisp_API($params, 'nests/' . $nestId . '/eggs/' . $eggId . '?include=variables');
        if($eggData['status_code'] !== 200) throw new Exception('Failed to get egg data, received error code: ' . $eggData['status_code'] . '. Enable module debug log for more info.');

        $environment = [];
        foreach($eggData['attributes']['relationships']['variables']['data'] as $key => $val) {
            $attr = $val['attributes'];
            $var = $attr['env_variable'];
            $default = $attr['default_value'];
            $friendlyName = wisp_GetOption($params, $attr['name']);
            $envName = wisp_GetOption($params, $attr['env_variable']);

            if(isset($friendlyName)) $environment[$var] = $friendlyName;
            elseif(isset($envName)) $environment[$var] = $envName;
            else $environment[$var] = $default;
        }

        $name = wisp_GetOption($params, 'server_name', 'My Server');
        $memory = wisp_GetOption($params, 'memory');
        $swap = wisp_GetOption($params, 'swap');
        $io = wisp_GetOption($params, 'io');
        $cpu = wisp_GetOption($params, 'cpu');
        $disk = wisp_GetOption($params, 'disk');
        $pack_id = wisp_GetOption($params, 'pack_id');
        $location_id = wisp_GetOption($params, 'location_id');
        $dedicated_ip = wisp_GetOption($params, 'dedicated_ip') ? true : false;
        $port_range = wisp_GetOption($params, 'port_range');
        $port_range = isset($port_range) ? explode(',', $port_range) : [];
        $image = wisp_GetOption($params, 'image', $eggData['attributes']['docker_image']);
        $startup = wisp_GetOption($params, 'startup', $eggData['attributes']['startup']);
        $databases = wisp_GetOption($params, 'databases');
        $allocations = wisp_GetOption($params, 'allocations');
        $oom_disabled = wisp_GetOption($params, 'oom_disabled') ? true : false;
        $backup_megabytes_limit = wisp_GetOption($params, 'backup_megabytes_limit');
        $serverData = [
            'name' => $name,
            'user' => (int) $userId,
            'nest' => (int) $nestId,
            'egg' => (int) $eggId,
            'docker_image' => $image,
            'startup' => $startup,
            'oom_disabled' => $oom_disabled,
            'limits' => [
                'memory' => (int) $memory,
                'swap' => (int) $swap,
                'io' => (int) $io,
                'cpu' => (int) $cpu,
                'disk' => (int) $disk,
            ],
            'feature_limits' => [
                'databases' => $databases ? (int) $databases : null,
                'allocations' => (int) $allocations,
                'backup_megabytes_limit' => (int) $backup_megabytes_limit,
            ],
            'deploy' => [
                'locations' => [(int) $location_id],
                'dedicated_ip' => $dedicated_ip,
                'port_range' => $port_range,
            ],
            'environment' => $environment,
            'start_on_completion' => true,
            'external_id' => (string) $params['serviceid'],
        ];
        if(isset($pack_id)) $serverData['pack'] = (int) $pack_id;

        $server = wisp_API($params, 'servers', $serverData, 'POST');

        if($server['status_code'] === 400) throw new Exception('Couldn\'t find any nodes satisfying the request.');
        if($server['status_code'] !== 201) throw new Exception('Failed to create the server, received the error code: ' . $server['status_code'] . '. Enable module debug log for more info.');

        unset($params['password']);
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
            'username' => '',
            'password' => '',
        ]);
    } catch(Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

// Function to allow backwards compatibility with death-droid's module
function wisp_GetServerID(array $params, $raw = false) {
    $serverResult = wisp_API($params, 'servers/external/' . $params['serviceid'], [], 'GET', true);
    if($serverResult['status_code'] === 200) {
        if($raw) return $serverResult;
        else return $serverResult['attributes']['id'];
    } else if($serverResult['status_code'] === 500) {
        throw new Exception('Failed to get server, panel errored. Check panel logs for more info.');
    }

    if(Capsule::schema()->hasTable('tbl_pterodactylproduct')) {
        $oldData = Capsule::table('tbl_pterodactylproduct')
            ->select('user_id', 'server_id')
            ->where('service_id', '=', $params['serviceid'])
            ->first();

        if(isset($oldData) && isset($oldData->server_id)) {
            if($raw) {
                $serverResult = wisp_API($params, 'servers/' . $oldData->server_id);
                if($serverResult['status_code'] === 200) return $serverResult;
                else throw new Exception('Failed to get server, received the error code: ' . $serverResult['status_code'] . '. Enable module debug log for more info.');
            } else {
                return $oldData->server_id;
            }
        }
    }
}

function wisp_SuspendAccount(array $params) {
    try {
        $serverId = wisp_GetServerID($params);
        if(!isset($serverId)) throw new Exception('Failed to suspend server because it doesn\'t exist.');

        $suspendResult = wisp_API($params, 'servers/' . $serverId . '/suspend', [], 'POST');
        if($suspendResult['status_code'] !== 204) throw new Exception('Failed to suspend the server, received error code: ' . $suspendResult['status_code'] . '. Enable module debug log for more info.');
    } catch(Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function wisp_UnsuspendAccount(array $params) {
    try {
        $serverId = wisp_GetServerID($params);
        if(!isset($serverId)) throw new Exception('Failed to unsuspend server because it doesn\'t exist.');

        $suspendResult = wisp_API($params, 'servers/' . $serverId . '/unsuspend', [], 'POST');
        if($suspendResult['status_code'] !== 204) throw new Exception('Failed to unsuspend the server, received error code: ' . $suspendResult['status_code'] . '. Enable module debug log for more info.');
    } catch(Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function wisp_TerminateAccount(array $params) {
    try {
        $serverId = wisp_GetServerID($params);
        if(!isset($serverId)) throw new Exception('Failed to terminate server because it doesn\'t exist.');

        $deleteResult = wisp_API($params, 'servers/' . $serverId, [], 'DELETE');
        if($deleteResult['status_code'] !== 204) throw new Exception('Failed to terminate the server, received error code: ' . $deleteResult['status_code'] . '. Enable module debug log for more info.');
    } catch(Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function wisp_ChangePassword(array $params) {
    try {
        if($params['password'] === '') throw new Exception('The password cannot be empty.');

        $serverData = wisp_GetServerID($params, true);
        if(!isset($serverData)) throw new Exception('Failed to change password because linked server doesn\'t exist.');

        $userId = $serverData['attributes']['user'];
        $userResult = wisp_API($params, 'users/' . $userId);
        if($userResult['status_code'] !== 200) throw new Exception('Failed to retrieve user, received error code: ' . $userResult['status_code'] . '.');

        $updateResult = wisp_API($params, 'users/' . $serverData['attributes']['user'], [
            'email' => $userResult['attributes']['email'],
            'first_name' => $userResult['attributes']['first_name'],
            'last_name' => $userResult['attributes']['last_name'],

            'password' => $params['password'],
        ], 'PATCH');
        if($updateResult['status_code'] !== 200) throw new Exception('Failed to change password, received error code: ' . $updateResult['status_code'] . '.');

        unset($params['password']);
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
            'username' => '',
            'password' => '',
        ]);
    } catch(Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function wisp_ChangePackage(array $params) {
    try {
        $serverData = wisp_GetServerID($params, true);
        if($serverData['status_code'] === 404 || !isset($serverData['attributes']['id'])) throw new Exception('Failed to change package of server because it doesn\'t exist.');
        $serverId = $serverData['attributes']['id'];

        $memory = wisp_GetOption($params, 'memory');
        $swap = wisp_GetOption($params, 'swap');
        $io = wisp_GetOption($params, 'io');
        $cpu = wisp_GetOption($params, 'cpu');
        $disk = wisp_GetOption($params, 'disk');
        $databases = wisp_GetOption($params, 'databases');
        $allocations = wisp_GetOption($params, 'allocations');
        $oom_disabled = wisp_GetOption($params, 'oom_disabled') ? true : false;
        $backup_megabytes_limit = wisp_GetOption($params, 'backup_megabytes_limit');
        $updateData = [
            'allocation' => $serverData['attributes']['allocation'],
            'memory' => (int) $memory,
            'swap' => (int) $swap,
            'io' => (int) $io,
            'cpu' => (int) $cpu,
            'disk' => (int) $disk,
            'oom_disabled' => $oom_disabled,
            'feature_limits' => [
                'databases' => (int) $databases,
                'allocations' => (int) $allocations,
                'backup_megabytes_limit' => (int) $backup_megabytes_limit,
            ],
        ];

        $updateResult = wisp_API($params, 'servers/' . $serverId . '/build', $updateData, 'PATCH');
        if($updateResult['status_code'] !== 200) throw new Exception('Failed to update build of the server, received error code: ' . $updateResult['status_code'] . '. Enable module debug log for more info.');

        $nestId = wisp_GetOption($params, 'nest_id');
        $eggId = wisp_GetOption($params, 'egg_id');
        $pack_id = wisp_GetOption($params, 'pack_id');
        $eggData = wisp_API($params, 'nests/' . $nestId . '/eggs/' . $eggId . '?include=variables');
        if($eggData['status_code'] !== 200) throw new Exception('Failed to get egg data, received error code: ' . $eggData['status_code'] . '. Enable module debug log for more info.');

        $environment = [];
        foreach($eggData['attributes']['relationships']['variables']['data'] as $key => $val) {
            $attr = $val['attributes'];
            $var = $attr['env_variable'];
            $friendlyName = wisp_GetOption($params, $attr['name']);
            $envName = wisp_GetOption($params, $attr['env_variable']);

            if(isset($friendlyName)) $environment[$var] = $friendlyName;
            elseif(isset($envName)) $environment[$var] = $envName;
            elseif(isset($serverData['attributes']['container']['environment'][$var])) $environment[$var] = $serverData['attributes']['container']['environment'][$var];
            elseif(isset($attr['default_value'])) $environment[$var] = $attr['default_value'];
        }

        $image = wisp_GetOption($params, 'image', $serverData['attributes']['container']['image']);
        $startup = wisp_GetOption($params, 'startup', $serverData['attributes']['container']['startup_command']);
        $updateData = [
            'environment' => $environment,
            'startup' => $startup,
            'egg' => (int) $eggId,
            'pack' => (int) $pack_id,
            'image' => $image,
            'skip_scripts' => false,
        ];

        $updateResult = wisp_API($params, 'servers/' . $serverId . '/startup', $updateData, 'PATCH');
        if($updateResult['status_code'] !== 200) throw new Exception('Failed to update startup of the server, received error code: ' . $updateResult['status_code'] . '. Enable module debug log for more info.');
    } catch(Exception $err) {
        return $err->getMessage();
    }

    return 'success';
}

function wisp_LoginLink(array $params) {
    if($params['moduletype'] !== 'wisp') return;

    try {
        $serverId = wisp_GetServerID($params);
        if(!isset($serverId)) return;

        $hostname = wisp_GetHostname($params);
        echo '[<a href="'.$hostname.'/admin/servers/view/' . $serverId . '" target="_blank">Go to Service</a>]';
        echo '<p style="float: right">[<a href="https://github.com/wisp-gg/whmcs/issues" target="_blank">Report A Bug</a>]</p>';
    } catch(Exception $err) {
        // Ignore
    }
}

function wisp_ClientArea(array $params) {
    if($params['moduletype'] !== 'wisp') return;

    try {
        $serverData = wisp_GetServerID($params, true);
        if($serverData['status_code'] === 404 || !isset($serverData['attributes']['id'])) return;

        $hostname = wisp_GetHostname($params);

        return [
            'templatefile' => 'clientarea',
            'vars' => [
                'serviceurl' => $hostname . '/server/' . $serverData['attributes']['identifier'],
            ],
        ];
    } catch (Exception $err) {
        // Ignore
    }
}
