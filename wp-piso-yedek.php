<?php
/**
 * Plugin Name:       Wp Piso Yedek
 * Plugin URI:        https://example.com/wp-piso-yedek
 * Description:       WordPress sitenizin dosya ve veritabanı yedeklerini güvenli ve yüksek performansla oluşturup yönetmenizi sağlar.
 * Version:           1.0.0
 * Author:            Baran Karaca
 * Author URI:        https://example.com
 * Text Domain:       wp-piso-yedek
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Wp_Piso_Yedek_Plugin')) {
    require_once plugin_dir_path(__FILE__) . 'includes/class-wp-piso-yedek-plugin.php';
}

/**
 * Başlatıcı.
 */
function wp_piso_yedek_run() {
    $plugin = new Wp_Piso_Yedek_Plugin();
    $plugin->run();
}

register_activation_hook(__FILE__, ['Wp_Piso_Yedek_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Wp_Piso_Yedek_Plugin', 'deactivate']);
wp_piso_yedek_run();
