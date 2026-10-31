<?php
/**
 * Plugin Name: My Map Plugin
 * Description: Interaktive Leaflet-Karte mit Marker-Kategorien und Nutzervorschlägen.
 * Version:     1.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Deutscher Tretroller Verband e.V.
 * Text Domain: my-map-plugin
 * Update URI:  https://github.com/BenediktKaltenbach/map_plugin
 */

defined('ABSPATH') || exit;

define('MMP_PATH', plugin_dir_path(__FILE__));
define('MMP_URL',  plugin_dir_url(__FILE__));
define('MMP_VERSION', '1.1.0');

require_once MMP_PATH . 'includes/class-cpt.php';
require_once MMP_PATH . 'includes/class-sanitizer.php';
require_once MMP_PATH . 'includes/class-rest-api.php';
require_once MMP_PATH . 'admin/class-admin.php';
require_once MMP_PATH . 'public/class-map-shortcode.php';

// Plugin aktivieren: Flush Rewrite Rules
register_activation_hook(__FILE__, function () {
    MMP_CPT::register();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

// Komponenten initialisieren
add_action('init', ['MMP_CPT', 'register']);
add_action('rest_api_init', ['MMP_RestAPI', 'register_routes']);
add_action('admin_menu', ['MMP_Admin', 'add_menu']);
add_action('admin_enqueue_scripts', ['MMP_Admin', 'enqueue_assets']);
MMP_MapShortcode::init();

// Updates über GitHub-Releases (Asset my-map-plugin.zip, enthält vendor/).
// Fehlt vendor/ (z. B. Installation aus dem Quellcode), läuft die Karte trotzdem, nur ohne Update-Hinweis.
if (is_readable(MMP_PATH . 'vendor/autoload.php')) {
    require_once MMP_PATH . 'vendor/autoload.php';
    $mmp_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/BenediktKaltenbach/map_plugin/',
        __FILE__,
        'my-map-plugin'
    );
    $mmp_updater->getVcsApi()->enableReleaseAssets('/my-map-plugin\.zip/');
}
