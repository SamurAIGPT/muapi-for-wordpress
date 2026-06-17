=== MuAPI for WordPress ===
Contributors: muapi
Tags: ai, image generation, video generation, text to video, media library, fal, replicate, midjourney, veo, flux
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes muapi.ai the first native image + video generation provider in the WordPress ecosystem. Fully integrates with the WordPress 7.0 AI Client SDK and enriches the WordPress Media Library with premium media studio capabilities.

== Description ==

MuAPI for WordPress bridges your WordPress site with **muapi.ai** (https://api.muapi.ai/api/v1), a generative media API aggregator that supports state-of-the-art models for image and video generation. 

This plugin does two core things:
1. **Media Library Studio (WP 6.x & 7.x):** Adds a "Generate with MuAPI" interface to the Media Library where you can write prompts, choose advanced models (like Flux Schnell or Veo 3 Video), generate assets, and save them directly to your catalog.
2. **WordPress 7.0 AI SDK Integration:** Registers MuAPI as a native provider in the `wp-ai-client` SDK core. Once connected under Settings -> Connectors, any block, page builder, or plugin calling `AiClient::prompt()` can route requests automatically to MuAPI.

**External Service Disclosure:**
This plugin connects to muapi.ai (https://muapi.ai) as an external service to process image and video generation requests. Using this plugin requires an API key which can be obtained by creating an account at muapi.ai. No personal data is sent to the service other than the prompt text used to generate the media.

== Installation ==

1. Upload the `muapi-for-wordpress` directory to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Settings -> Connectors** in the WordPress admin dashboard.
4. Find **MuAPI** and enter your API Key.
5. Alternatively, start generating right away from **Media -> Generate with MuAPI**.

== Frequently Asked Questions ==

= Where do I get a MuAPI API Key? =
You can sign up and retrieve your credentials at https://muapi.ai/dashboard/api-keys

= Which models are supported? =
- **Image Generation:** flux-schnell, flux-dev, flux-kontext-pro, hidream-fast, midjourney, etc.
- **Video Generation:** veo3, veo3-fast, kling-master, runway, wan2.2, minimax-hailuo, etc.
- **Image Enhance:** upscale, bg-remove, face-swap, object-erase, colorize, etc.

== Changelog ==

= 1.0.0 =
* Initial release. Supports WordPress 7.0 Connectors API and adds the media generation workspace under Media library.
