## WHMCS
WHMCS Module for the [WISP Panel](https://wisp.gg/).

## Configuration support
Please use the [WISP Discord](https://wisp.gg/discord) for configuration related support instead of GitHub issues.

## Installation
[Video Tutorial](https://www.youtube.com/watch?v=wURpRD9vfj4)  

1. Download/Git clone this repository.  
2. Move the ``wisp/`` folder into ``<path to whmcs>/modules/servers/``.
3. Create API Credentials with these permissions: ![Image](https://owo.whats-th.is/fa1eee.png)
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

# FAQ

## Migrating from pterodactyl's module
This module is backwards compatible and requires no changing other than switching to this module.

## Overwriting values through configurable options
Overwriting values can be done through either Configurable Options or Custom Fields.  

Their name should be exactly what you want to overwrite.  
dedicated_ip => Will overwrite dedicated_ip if its ticked or not.  
Valid options: ``server_name, memory, swap, io, cpu, disk, nest_id, egg_id, pack_id, location_id, dedicated_ip, port_range, image, startup, databases, allocations, oom_disabled, force_outgoing_ip, username, backup_megabytes_limit``

This also works for any name of environment variable:  
Player Slots => Will overwrite the environment variable named "Player Slots" to its value.  

Useful trick: You can use the | seperator to change the display name of the variable like this:  
dedicated_ip|Dedicated IP => Will be displayed as "Dedicated IP" but will work correctly.  

[Sample configuration for configurable memory](https://owo.whats-th.is/85JwhVX.png)

## Couldn't find any nodes satisfying the request.
This can be caused from any of the following: Wrong location, not enough disk space/CPU/RAM, or no allocations matching the provided criteria.

## The username/password field is empty, how does the user get credentials?
The customer gets an email from the panel to setup their account (incl. password) if they didn't have an account before. Otherwise they should be able to use their existing credentials.

## My game requires multiple ports allocated.
Currently, this isn't possible with this module but is planned.

## How to enable module debug log
1. In WHMCS 7 or below navigate to Utilities > Logs > Module Log. For WHMCS 8.x navigate to System Logs > Module Log in the left sidebar.
2. Click the Enable Debug Logging button.
3. Do the action that failed again and you will have required logs to debug the issue. All 404 errors can be ignored.
4. Remember to Disable Debug Logging if you are using this in production, as it's not recommended to have it enabled.
