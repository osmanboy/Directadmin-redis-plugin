# DA Redis add-in

Simple Redis setup for DirectAdmin environments.

## Features

- Automatically installs and activates Redis Object Cache
- Detects DirectAdmin user and configures Redis socket
- Supports custom home directories (/home, /home2, /partition2, etc.)
- Uses wp-config.php as source of truth when available
- Fallback to TCP (127.0.0.1:6379)
- One-click fix and cache flush
- Lightweight and clean UI

## Requirements

- WordPress
- Redis server running
- PHP Redis extension (phpredis)

## Installation

1. Upload the plugin to `/wp-content/plugins/`
2. Activate the plugin
3. Go to **Settings → DA Redis add-in**
4. Click "Fix Redis" if needed

## Notes

- Designed for DirectAdmin environments
- Does not modify server configuration
- Uses existing Redis setup

## Support

If you find this plugin useful:

☕ Buy me a coffee

## Links

- GitHub: https://github.com/osmanboy/Directadmin-redis-plugin/
- DirectAdmin Forum: https://forum.directadmin.com/members/ericosman.57345/

## License

GPL v2 or later
