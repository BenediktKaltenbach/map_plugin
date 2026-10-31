<?php
/**
 * Plugin Name: My Map Plugin
 * Description: Interaktive Leaflet-Karte mit Marker-Kategorien und Nutzervorschlägen.
 * Version:     1.0.0
 * Author:      Du
 * Text Domain: my-map-plugin
 */

defined('ABSPATH') || exit;

define('MMP_PATH', plugin_dir_path(__FILE__));
define('MMP_URL',  plugin_dir_url(__FILE__));
define('MMP_VERSION', '1.0.0');

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
