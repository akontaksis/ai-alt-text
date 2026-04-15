<?php
/**
 * Plugin Name: AI Alt Text Generator
 * Description: Παράγει alt text για εικόνες χρησιμοποιώντας OpenAI (GPT-4o mini ή GPT-4o)
 * Version: 1.3.0
 * Author: Athanasios Kontaksis
 * Author URI: https://github.com/akontaksis
 * Plugin URI: https://github.com/akontaksis/ai-alt-text
 * License: GPL-2.0-or-later
 * Text Domain: ai-alt-text
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AATG_VERSION',    '1.3.0' );
define( 'AATG_OPTION_KEY', 'aatg_settings' );
define( 'AATG_PLUGIN_FILE', __FILE__ );

// ─── Autoload files ───────────────────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'includes/security.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/openai.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ajax.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/admin.php';

// ─── Activation ───────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
    if ( ! get_option( AATG_OPTION_KEY ) ) {
        add_option( AATG_OPTION_KEY, [
            'api_key'    => '',
            'language'   => 'el',
            'model'      => 'gpt-4o-mini',
            'overwrite'  => 0,
            'batch_size' => 5,
        ] );
    }
} );

// ─── Deactivation (δεν σβήνει δεδομένα) ──────────────────────────────────────
register_deactivation_hook( __FILE__, function () {
    // Intentionally empty — data preserved on deactivation
} );
