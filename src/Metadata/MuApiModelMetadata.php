<?php
/**
 * Model metadata directory for MuAPI.
 */

namespace MuApiForWordPress\Metadata;

use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class MuApiModelMetadata implements ModelMetadataDirectoryInterface {
    /**
     * @var array<string, ModelMetadata>
     */
    private array $models = [];

    /**
     * Constructor.
     */
    public function __construct() {
        $this->registerModels();
    }

    /**
     * Register all supported MuAPI models.
     */
    private function registerModels(): void {
        // Image generation models
        $imageModels = [
            'flux-schnell' => 'Flux Schnell',
            'flux-dev' => 'Flux Dev',
            'flux-kontext-dev' => 'Flux Kontext Dev',
            'flux-kontext-pro' => 'Flux Kontext Pro',
            'flux-kontext-max' => 'Flux Kontext Max',
            'hidream-fast' => 'HiDream Fast',
            'hidream-dev' => 'HiDream Dev',
            'hidream-full' => 'HiDream Full',
            'gpt4o' => 'GPT-4o (Image)',
            'seedream' => 'SeeDream',
            'reve' => 'Reve',
            'midjourney' => 'Midjourney',
            // Missing image models
            'flux-2-klein-9b' => 'Flux 2 Klein 9B (LoRA)',
            'gpt4o-text-to-image' => 'DALL-E 3 (OpenAI)',
            'grok-imagine-text-to-image-quality' => 'Grok 3 (xAI)',
            'kling-o3-image' => 'Kling O3 Image',
        ];

        foreach ($imageModels as $id => $name) {
            $this->models[$id] = new ModelMetadata(
                id: $id,
                name: $name,
                supportedCapabilities: [CapabilityEnum::imageGeneration()],
                supportedOptions: []
            );
        }

        // Video generation models
        $videoModels = [
            'veo3' => 'Veo 3',
            'veo3-fast' => 'Veo 3 Fast',
            'kling-master' => 'Kling Master',
            'wan2.1' => 'Wan 2.1',
            'wan2.2' => 'Wan 2.2',
            'seedance-pro' => 'Seedance Pro',
            'seedance-lite' => 'Seedance Lite',
            'hunyuan' => 'Hunyuan',
            'runway' => 'Runway',
            'pixverse' => 'Pixverse',
            'minimax-hailuo-02-std' => 'Minimax Hailuo 02 Std',
            'minimax-hailuo-02-pro' => 'Minimax Hailuo 02 Pro',
            'sd-2' => 'Seedance 2',
            'sd-2-text-to-video' => 'Seedance 2 Text to Video',
            'sd-2-image-to-video' => 'Seedance 2 Image to Video',
            'seedance-v1.5-pro-t2v' => 'Seedance 1.5 Pro Text to Video',
            'seedance-v1.5-pro-i2v' => 'Seedance 1.5 Pro Image to Video',
            'happy-horse-1-text-to-video-1080p' => 'Happy Horse Text to Video',
            'happy-horse-1-image-to-video-1080p' => 'Happy Horse Image to Video',
            'veo3.1-text-to-video' => 'Veo 3.1 Text to Video',
            'veo3.1-image-to-video' => 'Veo 3.1 Image to Video',
            'wan2.7' => 'Wan 2.7',
            'gemini-omni' => 'Gemini Omni Video',
            'openai-sora-2' => 'Sora 2',
            'kling-v3.0' => 'Kling 3.0 Standard',
            'pixverse-v6' => 'Pixverse V6',
            'ltx-2.3' => 'LTX Studio 2.3',
        ];

        foreach ($videoModels as $id => $name) {
            $this->models[$id] = new ModelMetadata(
                id: $id,
                name: $name,
                supportedCapabilities: [CapabilityEnum::videoGeneration()],
                supportedOptions: []
            );
        }

        // Text generation (LLM) models
        $textModels = [
            'gemini-3-flash' => 'Gemini 3 Flash',
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
            'claude-opus-4-6' => 'Claude Opus 4.6',
            'gpt-5-4' => 'GPT-5.4',
            'gpt-codex' => 'GPT Codex',
            'claude-opus-4-8' => 'Claude Opus 4.8',
            'gemini-3-5-flash' => 'Gemini 3.5 Flash',
            'gemini-3-5-flash-openai' => 'Gemini 3.5 Flash (OpenAI)',
            'claude-opus-4-7' => 'Claude Opus 4.7',
            'gemini-3-1-pro' => 'Gemini 3.1 Pro',
            'gemini-3-pro' => 'Gemini 3 Pro',
            'claude-opus-4-5' => 'Claude Opus 4.5',
            'claude-sonnet-4-5' => 'Claude Sonnet 4.5',
            'claude-haiku-4-5' => 'Claude Haiku 4.5',
            'gemini-2-5-pro' => 'Gemini 2.5 Pro',
            'gemini-2-5-flash' => 'Gemini 2.5 Flash',
            'gpt-5-2' => 'GPT 5.2',
            'gpt-5-5' => 'GPT 5.5',
            'claude-fable-5' => 'Claude Fable 5',
            'generate-social-video-script' => 'AI Script Engine',
        ];

        foreach ($textModels as $id => $name) {
            $this->models[$id] = new ModelMetadata(
                id: $id,
                name: $name,
                supportedCapabilities: [CapabilityEnum::textGeneration()],
                supportedOptions: []
            );
        }

        // Image enhance models (also mapped to image generation)
        $enhanceModels = [
            'upscale' => 'Upscale',
            'bg-remove' => 'Background Removal',
            'face-swap' => 'Face Swap',
            'colorize' => 'Colorize',
            'ghibli' => 'Ghibli Filter',
            'anime' => 'Anime Filter',
            'product-shot' => 'Product Shot',
            'object-erase' => 'Object Erase',
        ];

        foreach ($enhanceModels as $id => $name) {
            $this->models[$id] = new ModelMetadata(
                id: $id,
                name: $name,
                supportedCapabilities: [CapabilityEnum::imageGeneration()],
                supportedOptions: []
            );
        }

        // Audio models (mapped to speech generation & text-to-speech)
        $audioModels = [
            'suno-create' => 'Suno Create',
            'suno-remix' => 'Suno Remix',
            'suno-extend' => 'Suno Extend',
            // New Text to Audio models
            'mmaudio-v2-text-to-audio' => 'v2 Text to Audio',
            'suno-create-music' => 'Create Music',
            'suno-remix-music' => 'Remix Music',
            'suno-extend-music' => 'Extend Music',
            'minimax-voice-clone' => 'Voice Clone',
            'minimax-speech-2.6-hd' => 'Speech HD',
            'minimax-speech-2.6-turbo' => 'Speech Turbo',
            'audio-passthrough' => 'Text to Audio',
            'suno-generate-sounds' => 'Suno Sounds',
            'suno-add-vocals' => 'Add Vocals',
            'suno-generate-mashup' => 'Suno Mashup',
            'suno-add-instrumental' => 'Add Instrumental',
            'suno-voice-clone' => 'Custom Voice Cloning',
        ];

        foreach ($audioModels as $id => $name) {
            $this->models[$id] = new ModelMetadata(
                id: $id,
                name: $name,
                supportedCapabilities: [CapabilityEnum::speechGeneration(), CapabilityEnum::textToSpeechConversion()],
                supportedOptions: []
            );
        }
    }

    /**
     * Lists all available model metadata.
     *
     * @return array
     */
    public function listModelMetadata(): array {
        return array_values($this->models);
    }

    /**
     * Checks if model metadata exists.
     *
     * @param string $modelId
     * @return bool
     */
    public function hasModelMetadata(string $modelId): bool {
        return isset($this->models[$modelId]);
    }

    /**
     * Retrieves the metadata for a specific model.
     *
     * @param string $modelId
     * @return ModelMetadata
     * @throws \Exception
     */
    public function getModelMetadata(string $modelId): ModelMetadata {
        if (!$this->hasModelMetadata($modelId)) {
            throw new \Exception("Model metadata not found for model: {$modelId}");
        }
        return $this->models[$modelId];
    }
}
