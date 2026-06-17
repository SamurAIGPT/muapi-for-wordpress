<?php
/**
 * Media Library UX for MuAPI.
 * Adds a "Generate with MuAPI" submenu under Media menu.
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

use WordPress\AiClient\AiClient;
use MuApiForWordPress\Metadata\MuApiModelMetadata;
use MuApiForWordPress\Provider\MuApiProvider;

// Register admin menu page
add_action('admin_menu', function() {
    $hook = add_media_page(
        __('Generate with MuAPI', 'muapi-for-wordpress'),
        __('Generate with MuAPI', 'muapi-for-wordpress'),
        'upload_files',
        'muapi-generator',
        'muapi_render_generator_page'
    );
    add_action("admin_print_scripts-$hook", 'wp_enqueue_media');
});

// AJAX Handler for media generation
add_action('wp_ajax_muapi_generate_media', function() {
    // Check permissions
    if (!current_user_can('upload_files')) {
        wp_send_json_error(['message' => __('Insufficient permissions.', 'muapi-for-wordpress')]);
    }

    // Verify nonce
    check_ajax_referer('muapi_generator_nonce', 'nonce');

    $promptText = sanitize_text_field($_POST['prompt'] ?? '');
    $modelId = preg_replace('/[^a-zA-Z0-9_\.-]/', '', $_POST['model'] ?? '');

    if (empty($promptText) && !in_array($modelId, ['upscale', 'bg-remove', 'face-swap', 'object-erase'])) {
        wp_send_json_error(['message' => __('Prompt cannot be empty.', 'muapi-for-wordpress')]);
    }

    if (empty($modelId)) {
        wp_send_json_error(['message' => __('Please select a model.', 'muapi-for-wordpress')]);
    }

    if (!class_exists(AiClient::class)) {
        wp_send_json_error(['message' => __('WordPress AI Client SDK (wp-ai-client) is not active.', 'muapi-for-wordpress')]);
    }

    try {
        // Instantiate model metadata to identify capabilities
        $metadataDir = new MuApiModelMetadata();
        $modelMeta = $metadataDir->getModelMetadata($modelId);
        $capabilities = $modelMeta->getSupportedCapabilities();

        error_log('[MuAPI Debug] requested modelId: ' . $modelId);
        error_log('[MuAPI Debug] capabilities dump: ' . print_r($capabilities, true));

        $is_video = false;
        $is_text = false;
        $is_audio = false;
        foreach ($capabilities as $cap) {
            error_log('[MuAPI Debug] item class: ' . (is_object($cap) ? get_class($cap) : gettype($cap)));
            if ($cap instanceof \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum) {
                if ($cap->isVideoGeneration()) {
                    $is_video = true;
                    error_log('[MuAPI Debug] matched video capability check 1');
                    break;
                } elseif ($cap->isTextGeneration()) {
                    $is_text = true;
                    error_log('[MuAPI Debug] matched text capability check 1');
                    break;
                } elseif ($cap->isSpeechGeneration() || $cap->isTextToSpeechConversion() || $cap->isMusicGeneration()) {
                    $is_audio = true;
                    error_log('[MuAPI Debug] matched audio capability check 1');
                    break;
                }
            } elseif (is_string($cap)) {
                if (strcasecmp($cap, 'video_generation') === 0) {
                    $is_video = true;
                    error_log('[MuAPI Debug] matched video capability check 2');
                    break;
                } elseif (strcasecmp($cap, 'text_generation') === 0) {
                    $is_text = true;
                    error_log('[MuAPI Debug] matched text capability check 2');
                    break;
                } elseif (in_array(strtolower($cap), ['speech_generation', 'text_to_speech_conversion', 'music_generation'])) {
                    $is_audio = true;
                    error_log('[MuAPI Debug] matched audio capability check 2');
                    break;
                }
            } elseif (isset($cap->value)) {
                if (strcasecmp($cap->value, 'video_generation') === 0) {
                    $is_video = true;
                    error_log('[MuAPI Debug] matched video capability check 3');
                    break;
                } elseif (strcasecmp($cap->value, 'text_generation') === 0) {
                    $is_text = true;
                    error_log('[MuAPI Debug] matched text capability check 3');
                    break;
                } elseif (in_array(strtolower($cap->value), ['speech_generation', 'text_to_speech_conversion', 'music_generation'])) {
                    $is_audio = true;
                    error_log('[MuAPI Debug] matched audio capability check 3');
                    break;
                }
            }
        }

        error_log('[MuAPI Debug] final is_video value: ' . ($is_video ? 'true' : 'false'));
        error_log('[MuAPI Debug] final is_text value: ' . ($is_text ? 'true' : 'false'));
        error_log('[MuAPI Debug] final is_audio value: ' . ($is_audio ? 'true' : 'false'));

        // Decode and sanitize options
        $options_raw = $_POST['options'] ?? '{}';
        $options = json_decode(stripslashes($options_raw), true);
        if (!is_array($options)) {
            $options = [];
        }

        $sanitized_options = [];
        foreach ($options as $key => $value) {
            $sanitized_key = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
            if ($value === 'true' || $value === true) {
                $sanitized_options[$sanitized_key] = true;
            } elseif ($value === 'false' || $value === false) {
                $sanitized_options[$sanitized_key] = false;
            } elseif (is_numeric($value)) {
                $sanitized_options[$sanitized_key] = $value + 0;
            } elseif (is_string($value)) {
                $sanitized_options[$sanitized_key] = sanitize_text_field($value);
            } elseif (is_array($value)) {
                $sanitized_options[$sanitized_key] = array_map('sanitize_text_field', $value);
            }
        }

        // Initialize SDK PromptBuilder
        $modelInstance = MuApiProvider::model($modelId);
        $promptBuilder = AiClient::prompt($promptText)->usingModel($modelInstance);

        // Configure options
        $modelConfig = new \WordPress\AiClient\Providers\Models\DTO\ModelConfig();
        if (!empty($sanitized_options)) {
            $modelConfig->setCustomOptions($sanitized_options);
        }
        $promptBuilder->usingModelConfig($modelConfig);

        if ($is_text) {
            error_log('[MuAPI Debug] calling generateTextResult');
            $result = $promptBuilder->generateTextResult();
        } elseif ($is_video) {
            error_log('[MuAPI Debug] calling generateVideoResult');
            $result = $promptBuilder->generateVideoResult();
        } elseif ($is_audio) {
            error_log('[MuAPI Debug] calling generateSpeechResult');
            $result = $promptBuilder->generateSpeechResult();
        } else {
            error_log('[MuAPI Debug] calling generateImageResult');
            $result = $promptBuilder->generateImageResult();
        }

        $candidates = $result->getCandidates();
        if (empty($candidates)) {
            wp_send_json_error(['message' => __('No candidates returned from the generation request.', 'muapi-for-wordpress')]);
        }

        $message = $candidates[0]->getMessage();
        $parts = $message->getParts();
        if (empty($parts)) {
            wp_send_json_error(['message' => __('Empty message parts returned.', 'muapi-for-wordpress')]);
        }

        if ($is_text) {
            $textOutput = $parts[0]->getText();
            if (empty($textOutput)) {
                $textOutput = method_exists($parts[0], 'getContent') ? $parts[0]->getContent() : '';
            }
            wp_send_json_success([
                'text' => $textOutput,
                'type' => 'text'
            ]);
        }

        $fileObj = $parts[0]->getFile();
        if (!method_exists($fileObj, 'getUrl')) {
            wp_send_json_error(['message' => __('No media URL returned from provider model.', 'muapi-for-wordpress')]);
        }

        $mediaUrl = $fileObj->getUrl();

        // Download and import into Media Library
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($mediaUrl);
        if (is_wp_error($tmp)) {
            wp_send_json_error(['message' => sprintf(__('Failed to download media: %s', 'muapi-for-wordpress'), $tmp->get_error_message())]);
        }

        $file_array = [
            'name' => basename(parse_url($mediaUrl, PHP_URL_PATH)),
            'tmp_name' => $tmp
        ];

        // Ensure proper filename extension
        if ($is_video && !str_ends_with($file_array['name'], '.mp4')) {
            $file_array['name'] .= '.mp4';
        } elseif ($is_audio && !str_ends_with($file_array['name'], '.mp3')) {
            $file_array['name'] .= '.mp3';
        } elseif (!$is_video && !$is_audio && !str_ends_with($file_array['name'], '.png')) {
            $file_array['name'] .= '.png';
        }

        // Import
        $attachment_id = media_handle_sideload($file_array, 0, $promptText);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            wp_send_json_error(['message' => sprintf(__('Failed to import into media library: %s', 'muapi-for-wordpress'), $attachment_id->get_error_message())]);
        }

        $attachment_url = wp_get_attachment_url($attachment_id);
        $thumbnail_url = $is_video ? $attachment_url : wp_get_attachment_thumb_url($attachment_id);

        wp_send_json_success([
            'id' => $attachment_id,
            'url' => $attachment_url,
            'thumbnail' => $is_audio ? '' : ($thumbnail_url ?: $attachment_url),
            'type' => $is_video ? 'video' : ($is_audio ? 'audio' : 'image')
        ]);

    } catch (\Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

// Render Page Callback
function wp_ai_client_is_api_key_configured() {
    $api_key = get_option('connectors_ai_muapi_api_key');
    return !empty($api_key);
}

function muapi_render_generator_page() {
    $nonce = wp_create_nonce('muapi_generator_nonce');
    $is_configured = wp_ai_client_is_api_key_configured();
    $connectors_url = admin_url('options-general.php?page=connectors'); // Standard WordPress connectors settings page URL
    ?>
    <div class="wrap" style="max-width: 1200px; margin: 20px auto;">
        <!-- Google Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Outfit:wght@500;700;800&display=swap" rel="stylesheet">

        <!-- Modern CSS Aesthetics -->
        <style>
            :root {
                --slate-950: #020617;
                --slate-900: #0f172a;
                --slate-800: #1e293b;
                --slate-700: #334155;
                --slate-400: #94a3b8;
                --sky-400: #38bdf8;
                --sky-500: #0ea5e9;
                --violet-600: #7c3aed;
                --violet-500: #8b5cf6;
                --emerald-500: #10b981;
            }

            .muapi-dashboard {
                font-family: 'Inter', sans-serif;
                background-color: var(--slate-950);
                color: #ffffff;
                border-radius: 24px;
                padding: 40px;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
                overflow: hidden;
                position: relative;
            }

            .muapi-dashboard::before {
                content: '';
                position: absolute;
                top: -10%;
                right: -10%;
                width: 300px;
                height: 300px;
                background: radial-gradient(circle, rgba(56, 189, 248, 0.15) 0%, rgba(0,0,0,0) 70%);
                filter: blur(40px);
                pointer-events: none;
            }

            .muapi-dashboard::after {
                content: '';
                position: absolute;
                bottom: -10%;
                left: -10%;
                width: 300px;
                height: 300px;
                background: radial-gradient(circle, rgba(139, 92, 246, 0.15) 0%, rgba(0,0,0,0) 70%);
                filter: blur(40px);
                pointer-events: none;
            }

            .muapi-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 40px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                padding-bottom: 24px;
            }

            .muapi-title {
                font-family: 'Outfit', sans-serif;
                font-size: 36px;
                font-weight: 800;
                background: linear-gradient(135deg, var(--sky-400) 0%, var(--violet-500) 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                margin: 0;
            }

            .muapi-subtitle {
                font-size: 14px;
                color: var(--slate-400);
                margin-top: 4px;
            }

            .muapi-grid {
                display: grid;
                grid-template-columns: 1.2fr 1.8fr;
                gap: 40px;
            }

            @media(max-width: 900px) {
                .muapi-grid {
                    grid-template-columns: 1fr;
                }
            }

            /* Settings Sidebar */
            .muapi-settings {
                background: rgba(30, 41, 59, 0.5);
                backdrop-filter: blur(16px);
                border: 1px solid rgba(255, 255, 255, 0.05);
                border-radius: 20px;
                padding: 30px;
                display: flex;
                flex-direction: column;
                gap: 24px;
            }

            .muapi-field-group {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }

            .muapi-label {
                font-family: 'Outfit', sans-serif;
                font-size: 14px;
                font-weight: 600;
                color: var(--slate-400);
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }

            .muapi-textarea {
                background-color: var(--slate-900);
                border: 1px solid var(--slate-800);
                border-radius: 12px;
                color: #ffffff;
                padding: 16px;
                font-size: 15px;
                resize: vertical;
                min-height: 120px;
                transition: all 0.3s ease;
            }

            .muapi-textarea:focus {
                border-color: var(--sky-500);
                box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.2);
                outline: none;
            }

            .muapi-select {
                background-color: var(--slate-900);
                border: 1px solid var(--slate-800);
                border-radius: 12px;
                color: #ffffff;
                padding: 14px;
                font-size: 15px;
                transition: all 0.3s ease;
                height: auto;
            }

            .muapi-select:focus {
                border-color: var(--sky-500);
                outline: none;
            }

            .muapi-btn-generate {
                background: linear-gradient(135deg, var(--sky-500) 0%, var(--violet-600) 100%);
                color: #ffffff;
                font-family: 'Outfit', sans-serif;
                font-size: 18px;
                font-weight: 700;
                padding: 16px;
                border: none;
                border-radius: 14px;
                cursor: pointer;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: 0 4px 20px rgba(124, 58, 237, 0.3);
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
            }

            .muapi-btn-generate:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 30px rgba(124, 58, 237, 0.5);
                filter: brightness(1.1);
            }

            .muapi-btn-generate:active {
                transform: translateY(0);
            }

            .muapi-btn-generate:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none !important;
                box-shadow: none !important;
            }

            /* Preview Canvas */
            .muapi-preview-container {
                background: var(--slate-900);
                border: 2px dashed var(--slate-800);
                border-radius: 20px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                min-height: 480px;
                position: relative;
                overflow: hidden;
            }

            .muapi-preview-placeholder {
                text-align: center;
                color: var(--slate-400);
                padding: 40px;
            }

            .muapi-preview-icon {
                font-size: 48px;
                margin-bottom: 16px;
                display: inline-block;
                animation: float 4s ease-in-out infinite;
            }

            @keyframes float {
                0%, 100% { transform: translateY(0); }
                50% { transform: translateY(-10px); }
            }

            /* Custom Premium Loader */
            .muapi-loader-wrap {
                display: none;
                flex-direction: column;
                align-items: center;
                gap: 20px;
            }

            .muapi-loader-circle {
                width: 60px;
                height: 60px;
                border: 4px solid rgba(255, 255, 255, 0.05);
                border-top-color: var(--sky-500);
                border-bottom-color: var(--violet-500);
                border-radius: 50%;
                animation: spin 1.5s cubic-bezier(0.5, 0, 0.5, 1) infinite;
            }

            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }

            .muapi-loader-status {
                font-size: 14px;
                color: var(--sky-400);
                font-weight: 500;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                animation: pulse 1.5s infinite;
            }

            @keyframes pulse {
                0%, 100% { opacity: 0.6; }
                50% { opacity: 1; }
            }

            /* Display Media Result */
            .muapi-result-media {
                max-width: 100%;
                max-height: 440px;
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
                display: none;
            }

            .muapi-result-actions {
                display: none;
                gap: 16px;
                margin-top: 24px;
                z-index: 10;
            }

            .muapi-action-btn {
                background-color: var(--slate-800);
                border: 1px solid var(--slate-700);
                color: #ffffff;
                padding: 10px 20px;
                border-radius: 8px;
                text-decoration: none;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .muapi-action-btn:hover {
                background-color: var(--slate-700);
                border-color: var(--slate-400);
            }

            .muapi-action-btn-primary {
                background: var(--sky-500);
                border-color: var(--sky-400);
            }

            .muapi-action-btn-primary:hover {
                background: var(--sky-400);
            }

            /* Error banner */
            .muapi-error-banner {
                background-color: rgba(239, 68, 68, 0.1);
                border: 1px solid rgba(239, 68, 68, 0.2);
                color: #fca5a5;
                padding: 16px;
                border-radius: 12px;
                margin-bottom: 24px;
                display: none;
            }
        </style>

        <div class="muapi-dashboard">
            <div class="muapi-header">
                <div>
                    <h1 class="muapi-title">MuAPI Media Studio</h1>
                    <div class="muapi-subtitle"><?php _e('Natively powered by MuAPI aggregator and WordPress AI Client SDK.', 'muapi-for-wordpress'); ?></div>
                </div>
                <div>
                    <?php if (!$is_configured): ?>
                        <a href="<?php echo esc_url($connectors_url); ?>" class="muapi-action-btn muapi-action-btn-primary" style="background-color: var(--violet-600); border-color: var(--violet-500);">
                            <?php _e('⚠️ Configure API Key', 'muapi-for-wordpress'); ?>
                        </a>
                    <?php else: ?>
                        <span style="color: var(--emerald-500); font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 6px;">
                            <span style="width: 8px; height: 8px; border-radius: 50%; background-color: var(--emerald-500); display: inline-block;"></span>
                            <?php _e('Connected', 'muapi-for-wordpress'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Error Banner -->
            <div id="muapi-error" class="muapi-error-banner"></div>

            <div class="muapi-grid">
                <!-- Left: Control panel -->
                <div class="muapi-settings">
                    <div class="muapi-field-group">
                        <label class="muapi-label" for="muapi-prompt"><?php _e('Enter Prompt', 'muapi-for-wordpress'); ?></label>
                        <textarea id="muapi-prompt" class="muapi-textarea" placeholder="<?php _e('Describe the masterpiece you want to create...', 'muapi-for-wordpress'); ?>" required></textarea>
                    </div>

                    <div class="muapi-field-group">
                        <label class="muapi-label" for="muapi-model"><?php _e('Choose Model', 'muapi-for-wordpress'); ?></label>
                        <select id="muapi-model" class="muapi-select">
                            <optgroup label="<?php _e('Image Models', 'muapi-for-wordpress'); ?>">
                                <option value="flux-schnell" selected>Flux Schnell (Fast, High Quality)</option>
                                <option value="flux-dev">Flux Dev</option>
                                <option value="flux-kontext-pro">Flux Kontext Pro</option>
                                <option value="midjourney">Midjourney</option>
                                <option value="reve">Reve (Artistic)</option>
                                <option value="flux-2-klein-9b">Flux 2 Klein 9B (LoRA)</option>
                                <option value="gpt4o-text-to-image">DALL-E 3 (OpenAI)</option>
                                <option value="grok-imagine-text-to-image-quality">Grok 3 (xAI)</option>
                                <option value="kling-o3-image">Kling O3 Image</option>
                            </optgroup>
                            <optgroup label="<?php _e('Video Models', 'muapi-for-wordpress'); ?>">
                                <option value="veo3">Veo 3 (First Native Video)</option>
                                <option value="veo3-fast">Veo 3 Fast</option>
                                <option value="kling-master">Kling Master</option>
                                <option value="wan2.2">Wan 2.2</option>
                                <option value="runway">Runway</option>
                                <option value="sd-2">Seedance 2</option>
                                <option value="wan2.7">Wan 2.7 (Alibaba)</option>
                                <option value="gemini-omni">Gemini Omni Video (Google)</option>
                                <option value="openai-sora-2">Sora 2 (OpenAI)</option>
                                <option value="kling-v3.0">Kling 3.0 Standard</option>
                                <option value="pixverse-v6">Pixverse V6</option>
                                <option value="ltx-2.3">LTX Studio 2.3 (Lightricks)</option>
                                <option value="veo3.1-text-to-video">Veo 3.1 Text to Video</option>
                                <option value="veo3.1-image-to-video">Veo 3.1 Image to Video</option>
                                <option value="sd-2-text-to-video">Seedance 2.0 Text to Video</option>
                                <option value="sd-2-image-to-video">Seedance 2.0 Image to Video</option>
                                <option value="seedance-v1.5-pro-t2v">Seedance 1.5 Pro Text to Video</option>
                                <option value="seedance-v1.5-pro-i2v">Seedance 1.5 Pro Image to Video</option>
                                <option value="happy-horse-1-text-to-video-1080p">Happy Horse Text to Video</option>
                                <option value="happy-horse-1-image-to-video-1080p">Happy Horse Image to Video</option>
                            </optgroup>
                            <optgroup label="<?php _e('AI Assistant & Writing', 'muapi-for-wordpress'); ?>">
                                <option value="gemini-3-flash">Gemini 3 Flash</option>
                                <option value="gemini-3-5-flash">Gemini 3.5 Flash</option>
                                <option value="gemini-3-5-flash-openai">Gemini 3.5 Flash (OpenAI)</option>
                                <option value="gemini-3-1-pro">Gemini 3.1 Pro</option>
                                <option value="gemini-3-pro">Gemini 3 Pro</option>
                                <option value="gemini-2-5-pro">Gemini 2.5 Pro</option>
                                <option value="gemini-2-5-flash">Gemini 2.5 Flash</option>
                                <option value="claude-sonnet-4-6">Claude Sonnet 4.6</option>
                                <option value="claude-opus-4-6">Claude Opus 4.6</option>
                                <option value="claude-opus-4-8">Claude Opus 4.8</option>
                                <option value="claude-opus-4-7">Claude Opus 4.7</option>
                                <option value="claude-opus-4-5">Claude Opus 4.5</option>
                                <option value="claude-sonnet-4-5">Claude Sonnet 4.5</option>
                                <option value="claude-haiku-4-5">Claude Haiku 4.5</option>
                                <option value="claude-fable-5">Claude Fable 5</option>
                                <option value="gpt-5-4">GPT-5.4</option>
                                <option value="gpt-5-2">GPT 5.2</option>
                                <option value="gpt-5-5">GPT 5.5</option>
                                <option value="gpt-codex">GPT Codex</option>
                                <option value="generate-social-video-script">AI Script Engine</option>
                            </optgroup>
                            <optgroup label="<?php _e('Audio & Music Generation', 'muapi-for-wordpress'); ?>">
                                <option value="mmaudio-v2-text-to-audio">v2 Text to Audio</option>
                                <option value="suno-create-music">Create Music (Suno)</option>
                                <option value="suno-remix-music">Remix Music (Suno)</option>
                                <option value="suno-extend-music">Extend Music (Suno)</option>
                                <option value="minimax-voice-clone">Voice Clone (Minimax)</option>
                                <option value="minimax-speech-2.6-hd">Speech HD (Minimax)</option>
                                <option value="minimax-speech-2.6-turbo">Speech Turbo (Minimax)</option>
                                <option value="suno-generate-sounds">Suno Sounds</option>
                                <option value="suno-add-vocals">Add Vocals (Suno)</option>
                                <option value="suno-generate-mashup">Suno Mashup</option>
                                <option value="suno-add-instrumental">Add Instrumental (Suno)</option>
                                <option value="suno-voice-clone">Custom Voice Cloning (Suno)</option>
                                <option value="audio-passthrough">Text to Audio Passthrough</option>
                            </optgroup>
                            <optgroup label="<?php _e('Enhance & Edit', 'muapi-for-wordpress'); ?>">
                                <option value="upscale">Upscale (Super Resolution)</option>
                                <option value="bg-remove">Background Removal</option>
                                <option value="face-swap">Face Swap</option>
                                <option value="object-erase">Object Erase</option>
                            </optgroup>
                        </select>
                    </div>

                    <!-- Dynamic Model Parameters Container -->
                    <div id="muapi-dynamic-options" style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 24px;"></div>

                    <button id="muapi-submit" class="muapi-btn-generate" <?php disabled(!$is_configured); ?>>
                        <span class="muapi-btn-icon">⚡</span>
                        <span class="muapi-btn-text"><?php _e('Generate Magic', 'muapi-for-wordpress'); ?></span>
                    </button>
                </div>

                <!-- Right: Preview Display -->
                <div class="muapi-preview-container" id="muapi-preview-area">
                    <!-- Default Placeholder -->
                    <div class="muapi-preview-placeholder" id="muapi-placeholder">
                        <span class="muapi-preview-icon">✨</span>
                        <h3><?php _e('Your Masterpiece Awaits', 'muapi-for-wordpress'); ?></h3>
                        <p style="font-size: 13px; color: var(--slate-400); margin: 8px 0 0 0; max-width: 320px;">
                            <?php _e('Choose settings on the left and click Generate Magic to trigger model prediction.', 'muapi-for-wordpress'); ?>
                        </p>
                    </div>

                    <!-- Loader -->
                    <div class="muapi-loader-wrap" id="muapi-loader">
                        <div class="muapi-loader-circle"></div>
                        <div class="muapi-loader-status" id="muapi-status"><?php _e('Submitting to MuAPI...', 'muapi-for-wordpress'); ?></div>
                    </div>

                    <!-- Image Result -->
                    <img id="muapi-img-result" class="muapi-result-media" src="" alt="" />

                    <!-- Video Result -->
                    <video id="muapi-video-result" class="muapi-result-media" controls src=""></video>

                    <!-- Audio Result -->
                    <audio id="muapi-audio-result" class="muapi-result-media" controls src="" style="width: 80%; margin: 20px auto;"></audio>

                    <!-- Text Result -->
                    <div id="muapi-text-result" class="muapi-result-media" style="display: none; width: 100%; max-height: 440px; overflow-y: auto; padding: 24px; background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; box-sizing: border-box; text-align: left; font-family: 'Inter', sans-serif; font-size: 15px; line-height: 1.6; white-space: pre-wrap; color: #e2e8f0;"></div>

                    <!-- Actions -->
                    <div class="muapi-result-actions" id="muapi-actions">
                        <a href="" id="muapi-action-library" class="muapi-action-btn muapi-action-btn-primary" target="_blank">
                            <?php _e('View in Library', 'muapi-for-wordpress'); ?>
                        </a>
                        <button id="muapi-action-reset" class="muapi-action-btn">
                            <?php _e('Create New', 'muapi-for-wordpress'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JS Client Engine -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const dynamicOptionsContainer = document.getElementById('muapi-dynamic-options');
            const modelSelect = document.getElementById('muapi-model');
            const promptInput = document.getElementById('muapi-prompt');
            const submitBtn = document.getElementById('muapi-submit');
            const placeholder = document.getElementById('muapi-placeholder');
            const loader = document.getElementById('muapi-loader');
            const statusText = document.getElementById('muapi-status');
            const imgResult = document.getElementById('muapi-img-result');
            const videoResult = document.getElementById('muapi-video-result');
            const audioResult = document.getElementById('muapi-audio-result');
            const actions = document.getElementById('muapi-actions');
            const libraryBtn = document.getElementById('muapi-action-library');
            const resetBtn = document.getElementById('muapi-action-reset');
            const errorBanner = document.getElementById('muapi-error');
            const textResult = document.getElementById('muapi-text-result');

            const standardLlmFields = [
                { name: 'image_url', label: 'Input Image (Multimodal, Optional)', type: 'media', required: false },
                { name: 'system_prompt', label: 'System Instruction (Optional)', type: 'textarea', default: '', placeholder: 'You are a helpful assistant...' }
            ];

            // Form schemas for dropdown models
            const modelSchemas = {
                'gemini-3-flash': standardLlmFields,
                'claude-sonnet-4-6': standardLlmFields,
                'claude-opus-4-6': standardLlmFields,
                'claude-opus-4-8': standardLlmFields,
                'gemini-3-5-flash': standardLlmFields,
                'gemini-3-5-flash-openai': standardLlmFields,
                'claude-opus-4-7': standardLlmFields,
                'gemini-3-1-pro': standardLlmFields,
                'gemini-3-pro': standardLlmFields,
                'claude-opus-4-5': standardLlmFields,
                'claude-sonnet-4-5': standardLlmFields,
                'claude-haiku-4-5': standardLlmFields,
                'gemini-2-5-pro': standardLlmFields,
                'gemini-2-5-flash': standardLlmFields,
                'claude-fable-5': standardLlmFields,
                'gpt-5-4': [
                    { name: 'image_url', label: 'Input Image (Multimodal, Optional)', type: 'media', required: false }
                ],
                'gpt-codex': [
                    { name: 'model', label: 'Model Variant', type: 'select', default: 'gpt-5-codex', choices: ['gpt-5-codex', 'gpt-5-1-codex', 'gpt-5-2-codex', 'gpt-5-3-codex', 'gpt-5-4-codex'] },
                    { name: 'image_url', label: 'Input Image (Multimodal, Optional)', type: 'media', required: false }
                ],
                'gpt-5-2': [
                    { name: 'image_url', label: 'Input Image (Multimodal, Optional)', type: 'media', required: false },
                    { name: 'system_prompt', label: 'System Instruction (Optional)', type: 'textarea', default: '', placeholder: 'You are a helpful assistant...' },
                    { name: 'web_search_switch', label: 'Web Search', type: 'select', default: 'false', choices: ['false', 'true'] },
                    { name: 'reasoning_effort', label: 'Reasoning Effort', type: 'select', default: 'high', choices: ['low', 'medium', 'high'] }
                ],
                'gpt-5-5': [
                    { name: 'image_url', label: 'Input Image (Multimodal, Optional)', type: 'media', required: false },
                    { name: 'system_prompt', label: 'System Instruction (Optional)', type: 'textarea', default: '', placeholder: 'You are a helpful assistant...' },
                    { name: 'web_search_switch', label: 'Web Search', type: 'select', default: 'false', choices: ['false', 'true'] },
                    { name: 'reasoning_effort', label: 'Reasoning Effort', type: 'select', default: 'low', choices: ['low', 'medium', 'high'] }
                ],
                'generate-social-video-script': [
                    { name: 'niche', label: 'Content Niche', type: 'text', default: 'Tech & Coding', placeholder: 'e.g. Cooking, Finance' },
                    { name: 'platform', label: 'Platform', type: 'select', default: 'tiktok', choices: ['tiktok', 'instagram', 'youtube', 'twitter', 'facebook'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'select', default: 30, choices: [15, 30, 60] },
                    { name: 'reference_hook', label: 'Reference Hook (Optional)', type: 'text', default: '', placeholder: 'Viral hook starting text...' }
                ],
                'flux-schnell': [
                    { name: 'width', label: 'Width', type: 'number', default: 1024, step: 64, min: 128, max: 2048 },
                    { name: 'height', label: 'Height', type: 'number', default: 1024, step: 64, min: 128, max: 2048 },
                    { name: 'num_images', label: 'Number of Images', type: 'number', default: 1, min: 1, max: 4 }
                ],
                'flux-dev': [
                    { name: 'width', label: 'Width', type: 'number', default: 1024, step: 64, min: 128, max: 2048 },
                    { name: 'height', label: 'Height', type: 'number', default: 1024, step: 64, min: 128, max: 2048 },
                    { name: 'num_images', label: 'Number of Images', type: 'number', default: 1, min: 1, max: 4 }
                ],
                'flux-kontext-pro': [
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '1:1', choices: ['1:1', '16:9', '9:16', '4:3', '3:4', '21:9', '16:21'] }
                ],
                'midjourney': [
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '1:1', choices: ['1:1', '16:9', '9:16', '3:4', '4:3', '1:2', '2:1', '2:3', '3:2', '5:6', '6:5'] },
                    { name: 'speed', label: 'Speed', type: 'select', default: 'relaxed', choices: ['relaxed', 'fast', 'turbo'] },
                    { name: 'variety', label: 'Variety (Diversity)', type: 'range', default: 5, min: 0, max: 100, step: 5 },
                    { name: 'stylization', label: 'Stylization (Art Intensity)', type: 'range', default: 100, min: 0, max: 1000, step: 10 },
                    { name: 'weirdness', label: 'Weirdness (Creativity)', type: 'range', default: 0, min: 0, max: 3000, step: 50 }
                ],
                'reve': [
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '1:1', choices: ['1:1', '16:9', '9:16', '4:3', '3:4', '21:9', '9:21'] }
                ],
                'veo3': [
                    { name: 'image_url', label: 'Input Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16'] }
                ],
                'veo3-fast': [
                    { name: 'image_url', label: 'Input Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16'] }
                ],
                'kling-master': [
                    { name: 'image_url', label: 'Input Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16', '1:1'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'select', default: 5, choices: [5, 10] }
                ],
                'wan2.2': [
                    { name: 'image_url', label: 'Input Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16'] },
                    { name: 'resolution', label: 'Resolution', type: 'select', default: '480p', choices: ['480p', '720p'] },
                    { name: 'quality', label: 'Quality', type: 'select', default: 'medium', choices: ['medium', 'high'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'range', default: 5, min: 5, max: 8, step: 3 }
                ],
                'runway': [
                    { name: 'image_url', label: 'Input Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16', '1:1', '4:3', '3:4'] },
                    { name: 'resolution', label: 'Resolution', type: 'select', default: '720p', choices: ['720p', '1080p'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'select', default: 5, choices: [5, 8] }
                ],
                'upscale': [
                    { name: 'image_url', label: 'Input Image', type: 'media', required: true }
                ],
                'bg-remove': [
                    { name: 'image_url', label: 'Input Image', type: 'media', required: true }
                ],
                'face-swap': [
                    { name: 'image_url', label: 'Target Image (Face to replace)', type: 'media', required: true },
                    { name: 'swap_url', label: 'Swap Image (Face to use)', type: 'media', required: true },
                    { name: 'target_index', label: 'Target Index', type: 'number', default: 0, min: 0, max: 10 }
                ],
                'object-erase': [
                    { name: 'image_url', label: 'Input Image', type: 'media', required: true },
                    { name: 'mask_image_url', label: 'Mask Image', type: 'media', required: true }
                ],
                'flux-2-klein-9b': [
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '1:1', choices: ['1:1', '16:9', '9:16', '4:3', '3:4'] }
                ],
                'gpt4o-text-to-image': [
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '1:1', choices: ['1:1', '16:9', '9:16'] },
                    { name: 'num_images', label: 'Number of Images', type: 'number', default: 1, min: 1, max: 4 }
                ],
                'grok-imagine-text-to-image-quality': [
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '1:1', choices: ['1:1', '16:9', '9:16', '4:3', '3:4', '21:9', '9:21'] }
                ],
                'kling-o3-image': [
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16', '1:1', '4:3', '3:4'] },
                    { name: 'resolution', label: 'Resolution', type: 'select', default: '1K', choices: ['1K', '2K'] },
                    { name: 'num_images', label: 'Number of Images', type: 'number', default: 1, min: 1, max: 4 }
                ],
                'sd-2': [
                    { name: 'image_url', label: 'Input Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16', '1:1'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'number', default: 5, min: 4, max: 15 }
                ],
                'wan2.7': [
                    { name: 'image_url', label: 'Input Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16', '1:1', '4:3', '3:4'] },
                    { name: 'resolution', label: 'Resolution', type: 'select', default: '720p', choices: ['720p', '1080p'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'range', default: 5, min: 5, max: 10 }
                ],
                'gemini-omni': [
                    { name: 'image_url', label: 'Input Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16', '1:1'] },
                    { name: 'resolution', label: 'Resolution', type: 'select', default: '1080p', choices: ['720p', '1080p'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'select', default: 8, choices: [8] }
                ],
                'openai-sora-2': [
                    { name: 'image_url', label: 'Input Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16', '1:1'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'range', default: 8, min: 5, max: 30 }
                ],
                'kling-v3.0': [
                    { name: 'image_url', label: 'Input Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'select', default: 5, choices: [5] },
                    { name: 'generate_audio', label: 'Generate Audio', type: 'select', default: 'true', choices: ['true', 'false'] }
                ],
                'pixverse-v6': [
                    { name: 'image_url', label: 'Input Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16'] },
                    { name: 'resolution', label: 'Resolution', type: 'select', default: '720p', choices: ['720p'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'number', default: 5, min: 1, max: 15 }
                ],
                'ltx-2.3': [
                    { name: 'image_url', label: 'Input Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16'] },
                    { name: 'resolution', label: 'Resolution', type: 'select', default: '720p', choices: ['720p'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'number', default: 5, min: 5, max: 20 }
                ],
                'mmaudio-v2-text-to-audio': [
                    { name: 'duration', label: 'Duration (Seconds)', type: 'number', default: 8, min: 1, max: 30 }
                ],
                'suno-create-music': [
                    { name: 'style', label: 'Music Style (Optional)', type: 'text', default: '', placeholder: 'e.g., pop, energetic rock, lo-fi' },
                    { name: 'model', label: 'Model Version', type: 'select', default: 'V5', choices: ['V3_5', 'V4', 'V4_5', 'V4_5PLUS', 'V4_5ALL', 'V5', 'V5_5'] },
                    { name: 'custom_mode', label: 'Custom Mode', type: 'select', default: 'true', choices: ['true', 'false'] },
                    { name: 'title', label: 'Track Title (Optional)', type: 'text', default: '', placeholder: 'Song title...' },
                    { name: 'persona_id', label: 'Persona ID (Optional)', type: 'text', default: '', placeholder: 'Custom persona voice/style ID' },
                    { name: 'persona_model', label: 'Persona Model Type', type: 'select', default: 'voice_persona', choices: ['style_persona', 'voice_persona'] },
                    { name: 'instrumental', label: 'Instrumental Only', type: 'select', default: 'true', choices: ['true', 'false'] },
                    { name: 'vocal_gender', label: 'Vocal Gender Preference', type: 'select', default: 'male', choices: ['male', 'female'] },
                    { name: 'style_weight', label: 'Style Weight (0.0 to 1.0)', type: 'range', default: 0.65, min: 0, max: 1, step: 0.05 },
                    { name: 'weirdness_constraint', label: 'Weirdness Constraint (0.0 to 1.0)', type: 'range', default: 0.65, min: 0, max: 1, step: 0.05 },
                    { name: 'audio_weight', label: 'Audio Weight (0.0 to 1.0)', type: 'range', default: 0.65, min: 0, max: 1, step: 0.05 }
                ],
                'suno-remix-music': [
                    { name: 'audio_url', label: 'Input Reference Audio', type: 'audio', required: true },
                    { name: 'style', label: 'Music Style (Optional)', type: 'text', default: '', placeholder: 'e.g., electronic remix' },
                    { name: 'model', label: 'Model Version', type: 'select', default: 'V5', choices: ['V3_5', 'V4', 'V4_5', 'V4_5PLUS', 'V4_5ALL', 'V5', 'V5_5'] },
                    { name: 'custom_mode', label: 'Custom Mode', type: 'select', default: 'true', choices: ['true', 'false'] },
                    { name: 'title', label: 'Track Title (Optional)', type: 'text', default: '', placeholder: 'Song title...' },
                    { name: 'instrumental', label: 'Instrumental Only', type: 'select', default: 'true', choices: ['true', 'false'] },
                    { name: 'vocal_gender', label: 'Vocal Gender Preference', type: 'select', default: 'male', choices: ['male', 'female'] }
                ],
                'suno-extend-music': [
                    { name: 'audio_url', label: 'Input Reference Audio', type: 'audio', required: true },
                    { name: 'continue_at', label: 'Extend At (Seconds)', type: 'number', default: 1, min: 1 },
                    { name: 'style', label: 'Music Style (Optional)', type: 'text', default: '', placeholder: 'e.g., same style' },
                    { name: 'model', label: 'Model Version', type: 'select', default: 'V5', choices: ['V3_5', 'V4', 'V4_5', 'V4_5PLUS', 'V4_5ALL', 'V5', 'V5_5'] },
                    { name: 'custom_mode', label: 'Custom Mode', type: 'select', default: 'true', choices: ['true', 'false'] },
                    { name: 'title', label: 'Track Title (Optional)', type: 'text', default: '', placeholder: 'Song title...' },
                    { name: 'instrumental', label: 'Instrumental Only', type: 'select', default: 'true', choices: ['true', 'false'] },
                    { name: 'vocal_gender', label: 'Vocal Gender Preference', type: 'select', default: 'male', choices: ['male', 'female'] }
                ],
                'minimax-voice-clone': [
                    { name: 'audio_url', label: '10s Reference Audio Sample', type: 'audio', required: true },
                    { name: 'custom_voice_id', label: 'Custom Voice ID', type: 'text', required: true, placeholder: 'Letters & numbers, starting with a letter. Min 8 chars.' },
                    { name: 'model', label: 'Preview TTS Model', type: 'select', default: 'speech-02-hd', choices: ['speech-02-hd', 'speech-02-turbo', 'speech-2.5-hd-preview', 'speech-2.5-turbo-preview', 'speech-2.6-hd', 'speech-2.6-turbo'] },
                    { name: 'need_noise_reduction', label: 'Noise Reduction', type: 'select', default: 'false', choices: ['false', 'true'] },
                    { name: 'need_volume_normalization', label: 'Volume Normalization', type: 'select', default: 'false', choices: ['false', 'true'] }
                ],
                'minimax-speech-2.6-hd': [
                    { name: 'voice_id', label: 'Voice ID (Friendly_Person, Wise_Woman, or custom ID)', type: 'text', default: 'Friendly_Person' },
                    { name: 'speed', label: 'Speed (0.5 to 2.0)', type: 'number', default: 1, min: 0.5, max: 2, step: 0.1 },
                    { name: 'volume', label: 'Volume (0.1 to 10.0)', type: 'number', default: 1, min: 0.1, max: 10, step: 0.1 },
                    { name: 'pitch', label: 'Pitch (-12 to 12)', type: 'number', default: 0, min: -12, max: 12, step: 1 },
                    { name: 'emotion', label: 'Emotion', type: 'select', default: 'happy', choices: ['happy', 'sad', 'angry', 'fearful', 'disgusted', 'surprised', 'neutral'] },
                    { name: 'english_normalization', label: 'English Normalization', type: 'select', default: 'false', choices: ['false', 'true'] },
                    { name: 'channel', label: 'Audio Channels', type: 'select', default: 1, choices: [1, 2] },
                    { name: 'format', label: 'Audio Format', type: 'select', default: 'mp3', choices: ['mp3', 'wav', 'pcm', 'flac'] },
                    { name: 'language_boost', label: 'Language Boost', type: 'select', default: 'auto', choices: ['auto', 'English', 'Chinese', 'Spanish', 'French', 'German', 'Italian', 'Japanese', 'Korean', 'Portuguese', 'Russian'] }
                ],
                'minimax-speech-2.6-turbo': [
                    { name: 'voice_id', label: 'Voice ID (Friendly_Person, Wise_Woman, or custom ID)', type: 'text', default: 'Friendly_Person' },
                    { name: 'speed', label: 'Speed (0.5 to 2.0)', type: 'number', default: 1, min: 0.5, max: 2, step: 0.1 },
                    { name: 'volume', label: 'Volume (0.1 to 10.0)', type: 'number', default: 1, min: 0.1, max: 10, step: 0.1 },
                    { name: 'pitch', label: 'Pitch (-12 to 12)', type: 'number', default: 0, min: -12, max: 12, step: 1 },
                    { name: 'emotion', label: 'Emotion', type: 'select', default: 'surprised', choices: ['happy', 'sad', 'angry', 'fearful', 'disgusted', 'surprised', 'neutral'] },
                    { name: 'english_normalization', label: 'English Normalization', type: 'select', default: 'false', choices: ['false', 'true'] },
                    { name: 'channel', label: 'Audio Channels', type: 'select', default: 1, choices: [1, 2] },
                    { name: 'format', label: 'Audio Format', type: 'select', default: 'mp3', choices: ['mp3', 'wav', 'pcm', 'flac'] },
                    { name: 'language_boost', label: 'Language Boost', type: 'select', default: 'auto', choices: ['auto', 'English', 'Chinese', 'Spanish', 'French', 'German', 'Italian', 'Japanese', 'Korean', 'Portuguese', 'Russian'] }
                ],
                'audio-passthrough': [
                    { name: 'audio_url', label: 'Input Reference Audio', type: 'audio', required: true },
                    { name: 'make_input', label: 'Convert Format', type: 'select', default: 'true', choices: ['true', 'false'] }
                ],
                'suno-generate-sounds': [
                    { name: 'model', label: 'Model Version', type: 'select', default: 'V5', choices: ['V5'] },
                    { name: 'sound_loop', label: 'Loop Sound', type: 'select', default: 'false', choices: ['false', 'true'] },
                    { name: 'sound_tempo', label: 'Tempo', type: 'number', default: 1 },
                    { name: 'sound_key', label: 'Musical Key', type: 'select', default: 'Any', choices: ['Any', 'Cm', 'C#m', 'Dm', 'D#m', 'Em', 'Fm', 'F#m', 'Gm', 'G#m', 'Am', 'A#m', 'Bm', 'C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'] },
                    { name: 'grab_lyrics', label: 'Fetch Lyrics', type: 'select', default: 'false', choices: ['false', 'true'] }
                ],
                'suno-add-vocals': [
                    { name: 'audio_url', label: 'Input Instrumental Track', type: 'audio', required: true },
                    { name: 'style', label: 'Vocal Style Hints', type: 'text', default: '', placeholder: 'e.g., melodic, soprano' },
                    { name: 'model', label: 'Model Version', type: 'select', default: 'V5', choices: ['V4', 'V4_5', 'V4_5PLUS', 'V5'] },
                    { name: 'vocal_gender', label: 'Vocal Gender Preference', type: 'select', default: 'male', choices: ['male', 'female'] },
                    { name: 'title', label: 'Track Title', type: 'text', default: 'New Vocal Track' }
                ],
                'suno-generate-mashup': [
                    { name: 'audios_list', label: 'Input Audio List (Comma separated URLs)', type: 'text', required: true, placeholder: 'https://url1.mp3, https://url2.mp3' },
                    { name: 'style', label: 'Mashup Style', type: 'text', default: '', placeholder: 'e.g., hybrid mix' },
                    { name: 'instrumental', label: 'Instrumental Only', type: 'select', default: 'true', choices: ['true', 'false'] },
                    { name: 'model', label: 'Model Version', type: 'select', default: 'V5', choices: ['V4', 'V4_5', 'V4_5PLUS', 'V5'] },
                    { name: 'vocal_gender', label: 'Vocal Gender Preference', type: 'select', default: 'male', choices: ['male', 'female'] },
                    { name: 'title', label: 'Track Title', type: 'text', default: 'New Mashup' }
                ],
                'suno-add-instrumental': [
                    { name: 'audio_url', label: 'Input Vocal Track', type: 'audio', required: true },
                    { name: 'tags', label: 'Instrumental Style Tags', type: 'text', default: '', placeholder: 'e.g., acoustic guitar' },
                    { name: 'model', label: 'Model Version', type: 'select', default: 'V5', choices: ['V4', 'V4_5', 'V4_5PLUS', 'V5'] },
                    { name: 'vocal_gender', label: 'Vocal Gender Preference', type: 'select', default: 'male', choices: ['male', 'female'] },
                    { name: 'title', label: 'Track Title', type: 'text', default: 'Instrumental Song' }
                ],
                'suno-voice-clone': [
                    { name: 'audio_url', label: '10s Clean Vocal Recording', type: 'audio', required: true },
                    { name: 'voice_name', label: 'Voice Name', type: 'text', required: true, placeholder: 'My cloned voice label' },
                    { name: 'description', label: 'Voice Description (Optional)', type: 'text', default: '', placeholder: 'e.g., warm voice' },
                    { name: 'style', label: 'Style Hints (Optional)', type: 'text', default: '', placeholder: 'e.g., singing, narration' },
                    { name: 'language', label: 'Spoken Language', type: 'select', default: 'en', choices: ['en', 'zh', 'es', 'fr', 'pt', 'de', 'ja', 'ko', 'hi', 'ru'] },
                    { name: 'vocal_start_s', label: 'Vocal Start Time (Seconds)', type: 'number', default: 0 },
                    { name: 'vocal_end_s', label: 'Vocal End Time (Seconds)', type: 'number', default: 10 }
                ],
                'veo3.1-text-to-video': [
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'select', default: 8, choices: [8] },
                    { name: 'resolution', label: 'Resolution', type: 'select', default: '720p', choices: ['720p', '1080p', '4k'] }
                ],
                'veo3.1-image-to-video': [
                    { name: 'image_url', label: 'Input Image', type: 'media', required: true },
                    { name: 'last_image', label: 'Last Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'select', default: 8, choices: [8] },
                    { name: 'resolution', label: 'Resolution', type: 'select', default: '720p', choices: ['720p', '1080p', '4k'] }
                ],
                'sd-2-text-to-video': [
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['21:9', '16:9', '4:3', '1:1', '3:4', '9:16'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'number', default: 5, min: 4, max: 15 }
                ],
                'sd-2-image-to-video': [
                    { name: 'image_url', label: 'Input Image', type: 'media', required: true },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['21:9', '16:9', '4:3', '1:1', '3:4', '9:16'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'number', default: 5, min: 4, max: 15 }
                ],
                'seedance-v1.5-pro-t2v': [
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16', '1:1', '3:4', '4:3', '21:9'] },
                    { name: 'resolution', label: 'Resolution', type: 'select', default: '720p', choices: ['480p', '720p', '1080p'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'number', default: 5, min: 4, max: 12 },
                    { name: 'generate_audio', label: 'Generate Audio', type: 'select', default: 'true', choices: ['true', 'false'] },
                    { name: 'camera_fixed', label: 'Fixed Camera', type: 'select', default: 'false', choices: ['false', 'true'] }
                ],
                'seedance-v1.5-pro-i2v': [
                    { name: 'image_url', label: 'Input Image', type: 'media', required: true },
                    { name: 'last_image', label: 'Last Image (Optional)', type: 'media', required: false },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16', '1:1', '3:4', '4:3', '21:9'] },
                    { name: 'resolution', label: 'Resolution', type: 'select', default: '720p', choices: ['480p', '720p', '1080p'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'number', default: 5, min: 4, max: 12 },
                    { name: 'generate_audio', label: 'Generate Audio', type: 'select', default: 'true', choices: ['true', 'false'] },
                    { name: 'camera_fixed', label: 'Fixed Camera', type: 'select', default: 'false', choices: ['false', 'true'] }
                ],
                'happy-horse-1-text-to-video-1080p': [
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16', '1:1', '4:3', '3:4'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'number', default: 5, min: 3, max: 15 }
                ],
                'happy-horse-1-image-to-video-1080p': [
                    { name: 'image_url', label: 'Input Image', type: 'media', required: true },
                    { name: 'aspect_ratio', label: 'Aspect Ratio', type: 'select', default: '16:9', choices: ['16:9', '9:16', '1:1', '4:3', '3:4'] },
                    { name: 'duration', label: 'Duration (Seconds)', type: 'number', default: 5, min: 3, max: 15 }
                ]
            };

            function renderDynamicOptions(modelId) {
                dynamicOptionsContainer.innerHTML = '';
                const fields = modelSchemas[modelId];
                if (!fields) return;

                fields.forEach(field => {
                    const fieldGroup = document.createElement('div');
                    fieldGroup.className = 'muapi-field-group';

                    const label = document.createElement('label');
                    label.className = 'muapi-label';
                    label.innerText = field.label;
                    fieldGroup.appendChild(label);

                    let input;
                    if (field.type === 'select') {
                        input = document.createElement('select');
                        input.className = 'muapi-select';
                        input.name = field.name;
                        field.choices.forEach(choice => {
                            const opt = document.createElement('option');
                            opt.value = choice;
                            opt.innerText = choice;
                            if (choice == field.default) opt.selected = true;
                            input.appendChild(opt);
                        });
                        fieldGroup.appendChild(input);
                    } else if (field.type === 'range') {
                        const rangeVal = document.createElement('span');
                        rangeVal.style.fontSize = '12px';
                        rangeVal.style.color = 'var(--sky-400)';
                        rangeVal.style.float = 'right';
                        rangeVal.innerText = field.default;
                        label.appendChild(rangeVal);

                        input = document.createElement('input');
                        input.type = 'range';
                        input.name = field.name;
                        input.min = field.min;
                        input.max = field.max;
                        input.step = field.step || 1;
                        input.value = field.default;
                        input.style.width = '100%';
                        input.addEventListener('input', () => rangeVal.innerText = input.value);
                        fieldGroup.appendChild(input);
                    } else if (field.type === 'number') {
                        input = document.createElement('input');
                        input.type = 'number';
                        input.className = 'muapi-select'; // Reuse style for simplicity
                        input.name = field.name;
                        input.value = field.default;
                        if (field.min !== undefined) input.min = field.min;
                        if (field.max !== undefined) input.max = field.max;
                        if (field.step !== undefined) input.step = field.step;
                        fieldGroup.appendChild(input);
                    } else if (field.type === 'media') {
                        const container = document.createElement('div');
                        container.style.display = 'flex';
                        container.style.gap = '10px';
                        container.style.alignItems = 'center';

                        input = document.createElement('input');
                        input.type = 'text';
                        input.name = field.name;
                        input.className = 'muapi-textarea';
                        input.style.minHeight = 'auto';
                        input.style.padding = '10px';
                        input.style.flex = '1';
                        input.placeholder = 'https://...';
                        if (field.required) input.required = true;

                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'muapi-action-btn muapi-action-btn-primary';
                        btn.innerText = 'Upload';
                        btn.style.padding = '10px 15px';

                        const preview = document.createElement('img');
                        preview.style.display = 'none';
                        preview.style.width = '40px';
                        preview.style.height = '40px';
                        preview.style.borderRadius = '6px';
                        preview.style.objectFit = 'cover';
                        preview.style.border = '1px solid var(--slate-700)';

                        input.addEventListener('change', () => {
                            if (input.value) {
                                preview.src = input.value;
                                preview.style.display = 'block';
                            } else {
                                preview.style.display = 'none';
                            }
                        });

                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            const frame = wp.media({
                                title: 'Select Media',
                                multiple: false,
                                library: { type: 'image' }
                            });
                            frame.on('select', function() {
                                const attachment = frame.state().get('selection').first().toJSON();
                                input.value = attachment.url;
                                preview.src = attachment.url;
                                preview.style.display = 'block';
                            });
                            frame.open();
                        });

                        container.appendChild(preview);
                        container.appendChild(input);
                        container.appendChild(btn);
                        fieldGroup.appendChild(container);
                    } else if (field.type === 'textarea') {
                        input = document.createElement('textarea');
                        input.className = 'muapi-textarea';
                        input.name = field.name;
                        input.value = field.default || '';
                        if (field.placeholder) input.placeholder = field.placeholder;
                        fieldGroup.appendChild(input);
                    } else if (field.type === 'audio') {
                        const container = document.createElement('div');
                        container.style.display = 'flex';
                        container.style.gap = '10px';
                        container.style.alignItems = 'center';

                        input = document.createElement('input');
                        input.type = 'text';
                        input.name = field.name;
                        input.className = 'muapi-textarea';
                        input.style.minHeight = 'auto';
                        input.style.padding = '10px';
                        input.style.flex = '1';
                        input.placeholder = 'https://...';
                        if (field.required) input.required = true;

                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'muapi-action-btn muapi-action-btn-primary';
                        btn.innerText = 'Upload';
                        btn.style.padding = '10px 15px';

                        const audioPreview = document.createElement('audio');
                        audioPreview.style.display = 'none';
                        audioPreview.style.width = '200px';
                        audioPreview.controls = true;

                        input.addEventListener('change', () => {
                            if (input.value) {
                                audioPreview.src = input.value;
                                audioPreview.style.display = 'block';
                            } else {
                                audioPreview.style.display = 'none';
                            }
                        });

                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            const frame = wp.media({
                                title: 'Select Audio',
                                multiple: false,
                                library: { type: 'audio' }
                            });
                            frame.on('select', function() {
                                const attachment = frame.state().get('selection').first().toJSON();
                                input.value = attachment.url;
                                audioPreview.src = attachment.url;
                                audioPreview.style.display = 'block';
                            });
                            frame.open();
                        });

                        container.appendChild(audioPreview);
                        container.appendChild(input);
                        container.appendChild(btn);
                        fieldGroup.appendChild(container);
                    } else if (field.type === 'text') {
                        input = document.createElement('input');
                        input.type = 'text';
                        input.className = 'muapi-select';
                        input.name = field.name;
                        input.value = field.default || '';
                        if (field.placeholder) input.placeholder = field.placeholder;
                        fieldGroup.appendChild(input);
                    }

                    dynamicOptionsContainer.appendChild(fieldGroup);
                });
            }

            // Trigger dynamic form builder on initialization and selection change
            modelSelect.addEventListener('change', () => renderDynamicOptions(modelSelect.value));
            renderDynamicOptions(modelSelect.value);

            const statusUpdates = [
                '<?php _e('Submitting to MuAPI...', 'muapi-for-wordpress'); ?>',
                '<?php _e('Prediction queued...', 'muapi-for-wordpress'); ?>',
                '<?php _e('Processing model outputs...', 'muapi-for-wordpress'); ?>',
                '<?php _e('Downloading media assets...', 'muapi-for-wordpress'); ?>',
                '<?php _e('Sideloading to Media Library...', 'muapi-for-wordpress'); ?>',
                '<?php _e('Finalizing catalog items...', 'muapi-for-wordpress'); ?>'
            ];

            let statusInterval = null;

            function updateLoaderProgress() {
                let idx = 0;
                statusText.innerText = statusUpdates[0];
                statusInterval = setInterval(() => {
                    if (idx < statusUpdates.length - 1) {
                        idx++;
                        statusText.innerText = statusUpdates[idx];
                    }
                }, 4000);
            }

            function resetInterface() {
                clearInterval(statusInterval);
                placeholder.style.display = 'flex';
                loader.style.display = 'none';
                imgResult.style.display = 'none';
                videoResult.style.display = 'none';
                audioResult.style.display = 'none';
                audioResult.src = '';
                textResult.style.display = 'none';
                textResult.innerText = '';
                actions.style.display = 'none';
                errorBanner.style.display = 'none';
                submitBtn.disabled = false;
                promptInput.value = '';
                // Reset file fields
                const inputs = dynamicOptionsContainer.querySelectorAll('input, select');
                inputs.forEach(input => {
                    if (input.type === 'text' || input.type === 'number') input.value = '';
                    const preview = input.parentElement.querySelector('img');
                    if (preview) preview.style.display = 'none';
                    const audioPreview = input.parentElement.querySelector('audio');
                    if (audioPreview) {
                        audioPreview.src = '';
                        audioPreview.style.display = 'none';
                    }
                });
            }

            submitBtn.addEventListener('click', function() {
                const prompt = promptInput.value.trim();
                const model = modelSelect.value;

                if (!prompt && !['upscale', 'bg-remove', 'face-swap', 'object-erase'].includes(model)) {
                    alert('<?php echo esc_js(__('Please enter a prompt.', 'muapi-for-wordpress')); ?>');
                    return;
                }

                // Verify required options fields
                let valid = true;
                const options = {};
                const inputs = dynamicOptionsContainer.querySelectorAll('input, select');
                inputs.forEach(input => {
                    if (input.required && !input.value) {
                        alert('Please fill in the required field: ' + (input.parentElement.previousSibling ? input.parentElement.previousSibling.innerText : ''));
                        valid = false;
                    }
                    if (input.name) {
                        if (input.type === 'number') {
                            options[input.name] = Number(input.value);
                        } else if (input.type === 'range') {
                            options[input.name] = Number(input.value);
                        } else {
                            options[input.name] = input.value;
                        }
                    }
                });

                if (!valid) return;

                // Hide layout items
                placeholder.style.display = 'none';
                imgResult.style.display = 'none';
                videoResult.style.display = 'none';
                audioResult.style.display = 'none';
                audioResult.src = '';
                textResult.style.display = 'none';
                textResult.innerText = '';
                actions.style.display = 'none';
                errorBanner.style.display = 'none';

                // Show loader
                loader.style.display = 'flex';
                submitBtn.disabled = true;
                updateLoaderProgress();

                // Build Form Data
                const formData = new FormData();
                formData.append('action', 'muapi_generate_media');
                formData.append('nonce', '<?php echo esc_js($nonce); ?>');
                formData.append('prompt', prompt);
                formData.append('model', model);
                formData.append('options', JSON.stringify(options));

                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(res => {
                    clearInterval(statusInterval);
                    loader.style.display = 'none';
                    submitBtn.disabled = false;

                    if (res.success) {
                        const media = res.data;
                        if (media.type === 'text') {
                            textResult.innerText = media.text;
                            textResult.style.display = 'block';
                            libraryBtn.style.display = 'none';
                        } else {
                            libraryBtn.style.display = 'inline-block';
                            if (media.type === 'video') {
                                videoResult.src = media.url;
                                videoResult.style.display = 'block';
                            } else if (media.type === 'audio') {
                                audioResult.src = media.url;
                                audioResult.style.display = 'block';
                            } else {
                                imgResult.src = media.url;
                                imgResult.style.display = 'block';
                            }
                            // Set attachment edit URL
                            libraryBtn.href = 'post.php?post=' + media.id + '&action=edit';
                        }
                        actions.style.display = 'flex';
                    } else {
                        placeholder.style.display = 'flex';
                        errorBanner.innerText = res.data.message || '<?php echo esc_js(__('An error occurred during generation.', 'muapi-for-wordpress')); ?>';
                        errorBanner.style.display = 'block';
                    }
                })
                .catch(err => {
                    clearInterval(statusInterval);
                    loader.style.display = 'none';
                    submitBtn.disabled = false;
                    placeholder.style.display = 'flex';
                    errorBanner.innerText = '<?php echo esc_js(__('Failed to execute media request.', 'muapi-for-wordpress')); ?>';
                    errorBanner.style.display = 'block';
                    console.error(err);
                });
            });

            resetBtn.addEventListener('click', resetInterface);
        });
    </script>
    <?php
}
