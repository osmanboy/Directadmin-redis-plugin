<?php
/**
 * Plugin Name: DA Redis add-in
 * Description: Simple Redis setup for DirectAdmin, this plug-in installs Redis Object Cache incase it's needed, and configures all settings needed.
 * Version: 0.1 BETA
 */

if (!defined('ABSPATH')) exit;

/**
 * Helpers
 */
function da_redis_get_user() {
    $home = getenv('HOME');
    if ($home) return basename($home);

    $parts = explode('/', ABSPATH);
    if (($i = array_search('home', $parts)) !== false) {
        return $parts[$i + 1] ?? null;
    }
    return null;
}

function da_redis_socket_path() {
    $user = da_redis_get_user();
    return $user ? "/home/{$user}/.redis/redis.sock" : null;
}

/**
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

function da_redis_socket_exists() {
    $socket = da_redis_socket_path();
    return $socket && file_exists($socket);
}

function da_redis_can_connect() {
    if (!da_redis_php_loaded()) return false;

    $socket = da_redis_socket_path();
    if (!$socket || !file_exists($socket)) return false;

    try {
        $r = new Redis();
        return @$r->connect($socket);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Runtime config
 */
add_filter('redis_cache_parameters', function ($params) {
    $socket = da_redis_socket_path();

    if ($socket) {
        $params['scheme'] = 'unix';
        $params['path']   = $socket;
    }

    return $params;
});

/**
 * Install plugin
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

/**
 * Enable object cache
 */
function da_redis_enable_cache() {
    if (function_exists('wp_cache_flush')) wp_cache_flush();

    if (function_exists('enable_redis_object_cache')) {
        do_action('enable_redis_object_cache');
    }
}

/**
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

/**
 * Fix all
 */
function da_redis_fix() {
    da_redis_install_plugin();
    da_redis_patch_config();
    da_redis_enable_cache();
}

/**
 * UI helpers
 */
function da_badge($ok, $yes = 'OK', $no = 'Fout') {
    $color = $ok ? '#46b450' : '#dc3232';
    $text  = $ok ? $yes : $no;

    return "<span style='display:inline-block;padding:4px 8px;border-radius:6px;background:{$color};color:#fff;font-size:12px;'>{$text}</span>";
}

/**
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

    if (isset($_POST['install'])) {
        da_redis_install_plugin();
        da_redis_enable_cache();
    }

    if (isset($_POST['fix'])) {
        da_redis_fix();
    }

    if (isset($_POST['flush'])) {
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    $user   = da_redis_get_user();
    $socket = da_redis_socket_path();

    echo '<div class="wrap">';
    echo '<h1>DA Redis add-in</h1>';
    echo '<p style="color:#666;">DirectAdmin Redis intergration & status</p>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px;max-width:900px;">';

    $items = [
        'PHP Redis extension' => da_badge(da_redis_php_loaded(), 'Loaded', 'Missing'),
        'Plugin' => da_badge(da_redis_plugin_active(), 'Active', 'Inactive'),
        'Object cache' => da_badge(da_redis_object_cache_enabled(), 'Enabled', 'Disabled'),
        'Socket' => da_badge(da_redis_socket_exists(), 'Found', 'Missing'),
        'Connection' => da_badge(da_redis_can_connect(), 'OK', 'Failed'),
    ];

    foreach ($items as $label => $value) {
        echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:15px;">';
        echo '<strong>' . esc_html($label) . '</strong><br><br>';
        echo $value;
        echo '</div>';
    }

    echo '</div>';

    echo '<br>';

    echo '<table class="widefat" style="max-width:900px">';
    echo '<tr><td style="width:200px;"><strong>User</strong></td><td>' . esc_html($user) . '</td></tr>';
    echo '<tr><td><strong>Socket path</strong></td><td><code>' . esc_html($socket) . '</code></td></tr>';
    echo '</table>';

    echo '<br><form method="post" style="max-width:900px;">';

    if (!da_redis_plugin_active()) {
        submit_button('Install Redis plugin', 'primary', 'install');
    }

    submit_button('Fix Redis', 'secondary', 'fix');
    submit_button('Flush cache', 'secondary', 'flush');

    echo '</form>';

    echo '<p style="margin-top:40px;color:#999;font-size:12px;">©ericosman</p>';

    echo '</div>';
}

/**
 * Activation
 */
register_activation_hook(__FILE__, function () {
    da_redis_fix();
});
