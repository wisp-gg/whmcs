
## WHMCS
WHMCS Module for the [WISP Panel](https://wisp.gg/).

## Configuration support
Please use the [WISP Discord](https://wisp.gg/discord) for configuration related support instead of GitHub issues.

## Installation
[Video Tutorial](https://www.youtube.com/watch?v=wURpRD9vfj4)

1. Download/Git clone this repository.
2. Move the ``wisp/`` folder into ``<path to whmcs>/modules/servers/``.
3. Create API Credentials with these permissions: ![Image](https://github.com/wisp-gg/whmcs/blob/master/.github/assets/permissions.png?raw=true)
4. In WHMCS navigate to Setup > Products/Services > Servers
5. Create new server, fill the name with anything you want, hostname as the url to the panel. For example: ``my-panel.panel.gg``
6. Change Server Type to WISP, leave username empty, fill the password field with your generated API Key.
7. Tick the "Secure" option if your panel is using SSL.
8. Confirm that everything works by clicking the Test Connection button -> Save Changes.
9. Go back to the Servers screen and press Create New Group, name it anything you want and choose the created server and press the Add button, Save Changes.
10. Navigate to Setup > Products/Services > Products/Services
11. Create your desired product (and product group if you haven't already) with the type of Other and product name of anything -> Continue.
12. Click the Module Settings tab, choose for Module Name WISP and for the Server Group the group you created in step 8.
13. Fill all non-optional fields, and you are good to go!

## Credits
[Dane](https://github.com/DaneEveritt) and [everyone else](https://github.com/Pterodactyl/Panel/graphs/contributors) involved in development of the Pterodactyl Panel.  
[death-droid](https://github.com/death-droid) for the original WHMCS module.  
[snags141](https://github.com/snags141/) for additional allocation support.

# FAQ

## Migrating from pterodactyl's module
This module is backwards compatible and requires no changing other than switching to this module.

## Overwriting values through configurable options
Overwriting values can be done through either Configurable Options or Custom Fields.

Their name should be exactly what you want to overwrite.  
dedicated_ip => Will overwrite dedicated_ip if its ticked or not.  
Valid options: ``server_name, memory, swap, io, cpu, disk, nest_id, egg_id, pack_id, location_id, dedicated_ip, port_range, image, startup, databases, allocations, oom_disabled, username, backup_megabytes_limit, backup_count_limit``

This also works for any name of environment variable:  
Player Slots => Will overwrite the environment variable named "Player Slots" to its value.

Useful trick: You can use the | separator to change the display name of the variable like this:  
dedicated_ip|Dedicated IP => Will be displayed as "Dedicated IP" but will work correctly.

[Sample configuration for configurable memory](https://owo.whats-th.is/85JwhVX.png)

## Couldn't find any nodes satisfying the request.
This can be caused from any of the following: Wrong location, not enough disk space/CPU/RAM, or no allocations matching the provided criteria.

## The username/password field is empty, how does the user get credentials?
The customer gets an email from the panel to setup their account (incl. password) if they didn't have an account before. Otherwise they should be able to use their existing credentials.

## My game requires multiple ports allocated.
Configure the "**Additional Ports**" option in the module settings.
It expects a valid JSON string with port allocations that can use either offset-based assignment or custom port ranges.

**Basic Format:**
`{"1":"PARAMETER_NAME", "2":"NONE"}` - Assigns ports as offsets from the main game port

**Advanced Format (Custom Ranges):**
`{"1":"PARAMETER_NAME:3000-3100", "2":"SPECIFIC_PORT:27015"}` - Assigns from custom ranges or specific ports

**Example:** If you enter `{"1":"RCON_PORT", "2":"NONE", "4":"QUERY_PORT:27015"}` and the main game port is `25565`, you'll get:
* Main Port: `25565`
* RCON_PORT: `25566` (Main Port + 1)
* Additional Port: `25567` (Main Port + 2, unassigned)
* QUERY_PORT: `27015` (specific port, if available)

**Important Notes:**
* This option overrides anything specified under "port_range" - use one or the other, not both
* Custom ranges allow more flexible port assignment beyond simple offsets
* When multiple allocations use the same range, each gets a unique port automatically

**Additional Port Failure Mode** determines what happens if ports aren't available:
* **"Continue"** - Creates server with available ports only, you manually assign missing ones later
* **"Stop"** - Stops server creation and raises an error if any required ports are unavailable

For detailed examples and advanced usage, see the **# Additional Port Allocations** section below.

# Additional Port Allocations

## How to assign additional allocations to server parameters

The Additional Ports feature allows you to assign specific ports to server environment variables and supports both offset-based allocation and custom port ranges.

*These are just example allocations. You can use any port you wish, simply ensure you have them assigned to your WISP node.*

### Basic Format
```json
{"offset":"PARAMETER_NAME"}
```

### Advanced Format with Custom Ranges
```json
{"offset":"PARAMETER_NAME:PORT_RANGE"}
```

## Examples

| Game | Required Ports | Additional Ports Example | Ports Assigned |
|------|----------------|-------------------------|----------------|
| **Rust** | Game port and RCON port | `{"1":"RCON_PORT"}` | Game Port: 1000, RCON_PORT: 1001 |
| **Arma 3** | Game port, Steam Query (+1), Steam Port (+2), BattleEye (+4) | `{"1":"NONE", "2":"NONE", "4":"NONE"}` | Game Port: `1000`, Additional Ports: `1001, 1002, 1004` |
| **Unturned** | Game port, Game port +1 and Game port +2 | `{"1":"NONE", "2":"NONE"}` | Game Port: `1000`, Additional Ports: `1001, 1002` |
| **Project Zomboid** | Game Port and Steam port | `{"1":"STEAM_PORT"}` | Game Port: `1000`, Steam Port: `1001` |

## Advanced Custom Range Examples

| Use Case | Additional Ports Example                                  | Description                                         |
|----------|-----------------------------------------------------------|-----------------------------------------------------|
| **Dedicated RCON Range** | `{"1":"RCON_PORT:3000-3100"}`                             | Assigns RCON_PORT from a range of `3000-3100`         |
| **Specific Query Port** | `{"1":"QUERY_PORT:27015"}`                                | Assigns QUERY_PORT specifically to port `27015`       |
| **Multiple Custom Ranges** | `{"1":"RCON_PORT:3000-3100", "2":"ADMIN_PORT:4000-4100"}` | RCON from `3000-3100`, ADMIN from `4000-4100`           |
| **Mixed Allocation** | `{"1":"RCON_PORT", "2":"QUERY_PORT:6900", "3":"NONE"}`    | RCON at game+1, QUERY at `6900`, additional at `game+3` |

## Format Explanations

### Basic Offset Format
- `{"1":"RCON_PORT"}` - Assigns main `game port + 1` to environment variable `RCON_PORT`
- `{"1":"NONE"}` - Allocates main `game port + 1` but doesn't assign to any environment variable

### Custom Range Format
- `{"1":"RCON_PORT:3000-3100"}` - Assigns first available port from range `3000-3100` to `RCON_PORT`
- `{"1":"QUERY_PORT:27015"}` - Assigns specific port `27015` to `QUERY_PORT` (if available)

### Multiple Ranges
When using the same range multiple times, the system automatically assigns different ports:
```json
{"1":"PORT_A:5000-5100", "2":"PORT_B:5000-5100"}
```
- `PORT_A` gets `5000`, `PORT_B` gets `5001` (or next available in range)

## Important Notes

1. **"NONE" Parameter**: Use `"NONE"` when you need to allocate a port but don't want it assigned to any environment variable
2. **Range Conflicts**: If multiple allocations use the same range, each gets a unique port from that range
3. **Fallback Behavior**: If custom ranges are unavailable, the system respects your `"Additional Port Failure Mode"` setting
4. **JSON Format**: Always use valid JSON format with double quotes around keys and values

## Troubleshooting

- **Server creation fails**: Check that your JSON format is valid and ranges have available ports
- **Duplicate ports assigned**: Ensure you're using the updated module version that prevents duplicates
- **Range not working**: Verify the port range exists and is available on your WISP node

## How to enable module debug log
1. In WHMCS 7 or below navigate to Utilities > Logs > Module Log. For WHMCS 8.x navigate to System Logs > Module Log in the left sidebar.
2. Click the Enable Debug Logging button.
3. Do the action that failed again and you will have required logs to debug the issue. All 404 errors can be ignored.
4. Remember to Disable Debug Logging if you are using this in production, as it's not recommended to have it enabled.
