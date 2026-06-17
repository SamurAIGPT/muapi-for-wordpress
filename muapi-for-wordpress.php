<?php
/**
 * Plugin Name: MuAPI for WordPress
 * Plugin URI: https://muapi.ai/
 * Description: Integrates MuAPI.ai image and video generation natively into the WordPress Media Library and AI Client SDK.
 * Version: 1.0.0
 * Author: MuAPI
 * Author URI: https://muapi.ai/
 * License: GPL-2.0-or-later
 * Text Domain: muapi-for-wordpress
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Load autoloader.
require_once __DIR__ . '/src/autoload.php';

use WordPress\AiClient\AiClient;
use MuApiForWordPress\Provider\MuApiProvider;
use MuApiForWordPress\Http\MuApiRequestAuthentication;

// Step 1: Register provider EARLY — must be priority 5 or lower.
add_action('init', function() {
    if (class_exists(AiClient::class)) {
        AiClient::defaultRegistry()->registerProvider(MuApiProvider::class);
    }
}, 5);

// Step 2: Store API key (shown in Settings → Connectors).
add_action('admin_init', function() {
    register_setting('connectors', 'connectors_ai_muapi_api_key', [
        'type'              => 'string',
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
    ]);
});

// Step 3: Wire authentication.
add_action('init', function() {
    if (class_exists(AiClient::class)) {
        $api_key = '';
        if (defined('MUAPI_API_KEY')) {
            $api_key = MUAPI_API_KEY;
        } elseif (defined('WP_MUAPI_API_KEY')) {
            $api_key = WP_MUAPI_API_KEY;
        } else {
            $api_key = get_option('connectors_ai_muapi_api_key');
        }

        if (!empty($api_key)) {
            AiClient::defaultRegistry()->setProviderRequestAuthentication(
                'muapi',
                new MuApiRequestAuthentication($api_key)
            );
        }
    }
}, 30);

// Step 4: Register and Enqueue JS connector UI.
add_action('init', function() {
    if (function_exists('wp_register_script_module')) {
        wp_register_script_module(
            'muapi/connectors',
            plugins_url('build/connectors.js', __FILE__),
            ['@wordpress/connectors']
        );
    }
});

add_action('options-connectors-wp-admin_init', function() {
    if (function_exists('wp_enqueue_script_module')) {
        wp_enqueue_script_module('muapi/connectors');
    }
});

add_action('connectors-wp-admin_init', function() {
    if (function_exists('wp_enqueue_script_module')) {
        wp_enqueue_script_module('muapi/connectors');
    }
});

// Load Media Library UX (wp-banana style).
require_once __DIR__ . '/includes/media-library.php';
