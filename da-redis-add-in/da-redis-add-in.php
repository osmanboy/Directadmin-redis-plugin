<?php
/*
Plugin Name: DA Redis add-in
Description: Simple Redis setup for DirectAdmin, this plug-in installs Redis Object Cache incase it's needed, and configures all settings needed.
Author: Ericosman
Version: 0.3 BETA
 */

if (!defined('ABSPATH')) exit;

/*
 * Detect DirectAdmin user
 */
function da_redis_get_user() {

    $home = getenv('HOME');
    if ($home && is_dir($home)) {
        return basename($home);
    }

    if (!empty($_SERVER['HOME']) && is_dir($_SERVER['HOME'])) {
        return basename($_SERVER['HOME']);
    }

    return null;
}

/*
 * Get Redis socket path
 */
function da_redis_socket_path() {

    // Prefer wp-config
    if (defined('WP_REDIS_PATH') && WP_REDIS_PATH) {
        return WP_REDIS_PATH;
    }

    $user = da_redis_get_user();
    return $user ? "/home/{$user}/.redis/redis.sock" : null;
}

/*
 * Status checks
 */
function da_redis_php_loaded() {
    return extension_loaded('redis');
}

function da_redis_plugin_active() {
    include_once ABSPATH . 'wp-admin/includes/plugin.php';
    return is_plugin_active('redis-cache/redis-cache.php');
}

function da_redis_object_cache_enabled() {
    return file_exists(WP_CONTENT_DIR . '/object-cache.php');
}

function da_redis_can_connect() {

    if (!da_redis_php_loaded()) return false;

    try {
        $r = new Redis();

        // Try socket first
        $socket = da_redis_socket_path();
        if ($socket) {
            if (@$r->connect($socket)) {
                return true;
            }
        }

        // Fallback TCP
        if (@$r->connect('127.0.0.1', 6379)) {
            return true;
        }

    } catch (Exception $e) {
        return false;
    }

    return false;
}

/*
 * Runtime Redis config
 */
add_filter('redis_cache_parameters', function ($params) {

    $socket = da_redis_socket_path();

    if ($socket) {
        $params['scheme'] = 'unix';
        $params['path']   = $socket;
    } else {
        $params['host'] = '127.0.0.1';
        $params['port'] = 6379;
    }

    return $params;
});

/*
 * Install Redis plugin
 */
function da_redis_install_plugin() {

    if (!current_user_can('install_plugins')) return;

    include_once ABSPATH . 'wp-admin/includes/plugin.php';
    include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    $plugin = 'redis-cache/redis-cache.php';

    if (file_exists(WP_PLUGIN_DIR . '/' . $plugin)) {
        if (!is_plugin_active($plugin)) activate_plugin($plugin);
        return;
    }

    $api = plugins_api('plugin_information', [
        'slug' => 'redis-cache',
        'fields' => ['sections' => false]
    ]);

    if (is_wp_error($api)) return;

    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $upgrader->install($api->download_link);

    activate_plugin($plugin);
}

/*
 * Enable object cache
 */
function da_redis_enable_cache() {
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }

    if (function_exists('enable_redis_object_cache')) {
        do_action('enable_redis_object_cache');
    }
}

/*
 * Patch wp-config
 */
function da_redis_patch_config() {

    $config = ABSPATH . 'wp-config.php';
    if (!file_exists($config) || !is_writable($config)) return;

    $socket = da_redis_socket_path();
    if (!$socket) return;

    $content = file_get_contents($config);

    if (strpos($content, 'WP_REDIS_PATH') !== false) return;

    $insert  = "\n// DirectAdmin Redis\n";
    $insert .= "define('WP_REDIS_SCHEME', 'unix');\n";
    $insert .= "define('WP_REDIS_PATH', '{$socket}');\n";

    $content = str_replace(
        "/* That's all, stop editing! Happy publishing. */",
        $insert . "\n/* That's all, stop editing! Happy publishing. */",
        $content
    );

    file_put_contents($config, $content);
}

/*
 * Fix all
 */
function da_redis_fix() {
    da_redis_install_plugin();
    da_redis_patch_config();
    da_redis_enable_cache();
}

/*
 * UI helper
 */
function da_badge($ok, $yes = 'OK', $no = 'Error') {
    $color = $ok ? '#46b450' : '#dc3232';
    $text  = $ok ? $yes : $no;

    return "<span style='display:inline-block;padding:4px 8px;border-radius:6px;background:{$color};color:#fff;font-size:12px;'>{$text}</span>";
}

/*
 * Admin notice
 */
function da_redis_user_notice() {

    if (!current_user_can('manage_options')) return;

    if (!isset($_GET['page']) || $_GET['page'] !== 'da-redis') return;

    if (!da_redis_plugin_active()) return;

    if (!da_redis_php_loaded()) {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>DA Redis add-in:</strong> PHP Redis extension is not installed. Redis will not work.';
        echo '</p></div>';
        return;
    }

    if (!da_redis_get_user() && !defined('WP_REDIS_PATH')) {
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>DA Redis add-in:</strong> User detection failed and no Redis path defined in wp-config.php.';
        echo '</p></div>';
    }
}
add_action('admin_notices', 'da_redis_user_notice');

/*
 * Settings page
 */
add_action('admin_menu', function () {
    add_options_page(
        'DA Redis add-in',
        'DA Redis add-in',
        'manage_options',
        'da-redis',
        'da_redis_page'
    );
});

function da_redis_page() {

    if (isset($_POST['da_action']) && check_admin_referer('da_redis_nonce_action')) {

        if ($_POST['da_action'] === 'install') {
            da_redis_install_plugin();
            da_redis_enable_cache();
        }

        if ($_POST['da_action'] === 'fix') {
            da_redis_fix();
        }

        if ($_POST['da_action'] === 'flush') {
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        }
    }

    echo '<div class="wrap">';
    echo '<h1>DA Redis add-in</h1>';
    echo '<p style="color:#666;">DirectAdmin Redis integration & status</p>';

    if (defined('WP_REDIS_PATH')) {
        echo '<p style="color:#666;">Using Redis configuration from wp-config.php</p>';
    }

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px;max-width:900px;">';

    $items = [
        'PHP Redis extension' => da_badge(da_redis_php_loaded(), 'Loaded', 'Missing'),
        'Plugin' => da_badge(da_redis_plugin_active(), 'Active', 'Inactive'),
        'Object cache' => da_badge(da_redis_object_cache_enabled(), 'Enabled', 'Disabled'),
        'Connection' => da_badge(da_redis_can_connect(), 'OK', 'Failed'),
    ];

    foreach ($items as $label => $value) {
        echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:15px;">';
        echo '<strong>' . esc_html($label) . '</strong><br><br>';
        echo $value;
        echo '</div>';
    }

    echo '</div>';

    echo '<br><form method="post">';
    wp_nonce_field('da_redis_nonce_action');

    if (!da_redis_plugin_active()) {
        echo '<button class="button button-primary" name="da_action" value="install">Install Redis plugin</button> ';
    }

    echo '<button class="button" name="da_action" value="fix">Fix Redis</button> ';
    echo '<button class="button" name="da_action" value="flush">Flush cache</button>';

    echo '</form>';

    echo '<p style="margin-top:40px;color:#888;font-size:12px;">
    <strong>DA Redis add-in</strong> Powered by  — 
    <a href="' . esc_url('https://github.com/osmanboy/Directadmin-redis-plugin/') . '" target="_blank" rel="noopener noreferrer" style="color:#666;text-decoration:none;">GitHub</a> 
    | 
    <a href="' . esc_url('https://forum.directadmin.com/members/ericosman.57345/') . '" target="_blank" rel="noopener noreferrer" style="color:#666;text-decoration:none;">DirectAdmin Forum</a>
    <br>© ericosman
    </p>';

    echo '</div>';
}

/*
 * Activation
 */
register_activation_hook(__FILE__, function () {
    da_redis_fix();
});
