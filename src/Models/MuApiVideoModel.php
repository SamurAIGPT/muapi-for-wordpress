<?php
/**
 * Video model for MuAPI.
 */

namespace MuApiForWordPress\Models;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Models\VideoGeneration\Contracts\VideoGenerationModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use MuApiForWordPress\Provider\MuApiProvider;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Files\DTO\File;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class MuApiVideoModel extends AbstractApiBasedModel implements VideoGenerationModelInterface {
    /**
     * Constructor.
     *
     * @param ModelMetadata $meta
     */
    public function __construct(ModelMetadata $meta) {
        // Resolve provider metadata or pass a default one.
        $providerMeta = new ProviderMetadata(
            id: 'muapi',
            name: 'MuAPI',
            type: \WordPress\AiClient\Providers\Enums\ProviderTypeEnum::cloud(),
            credentialsUrl: 'https://muapi.ai/dashboard/api-keys',
            authenticationMethod: \WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod::apiKey()
        );
        parent::__construct($meta, $providerMeta);
    }

    /**
     * Generates a video based on the prompt.
     *
     * @param array $prompt
     * @param array $options
     * @return GenerativeAiResult
     * @throws \Exception
     */
    public function generateVideoResult(array $prompt, array $options = []): GenerativeAiResult {
        // Extract prompt string
        $promptString = $this->getPromptString($prompt);

        // Submit request to MuAPI
        $modelId = $this->metadata()->getId();

        // Merge custom options from config
        $customOptions = $this->getConfig()->getCustomOptions();
        if (!empty($customOptions)) {
            $options = array_merge($customOptions, $options);
        }

        // Check if an image is provided in the options
        $imageUrl = $options['image_url'] ?? null;
        $hasImage = !empty($imageUrl);

        $endpointSlug = $this->getEndpointSlug($modelId, $hasImage);
        $endpoint = MuApiProvider::url('/api/v1/' . $endpointSlug);

        // Build data array
        $data = [];
        if (!empty($promptString)) {
            $data['prompt'] = $promptString;
        }

        // Merge options
        if (!empty($options)) {
            $data = array_merge($data, $options);
        }

        // Clean up empty image_url
        if (isset($data['image_url']) && empty($data['image_url'])) {
            unset($data['image_url']);
        }

        // Handle models that expect 'images_list' or 'image_urls' (as array) instead of 'image_url' (as string)
        if ($hasImage) {
            if (
                str_starts_with($endpointSlug, 'veo') || 
                str_starts_with($endpointSlug, 'sd-2') || 
                str_starts_with($endpointSlug, 'openai-sora-2') || 
                str_starts_with($endpointSlug, 'pixverse') ||
                str_starts_with($endpointSlug, 'happy-horse')
            ) {
                $data['images_list'] = [$imageUrl];
                unset($data['image_url']);
            } elseif (str_starts_with($endpointSlug, 'gemini-omni')) {
                $data['image_urls'] = [$imageUrl];
                unset($data['image_url']);
            }
        }

        $request = new Request(
            method: HttpMethodEnum::POST(),
            uri: $endpoint,
            headers: [
                'Content-Type' => 'application/json',
            ],
            data: $data
        );

        // Authenticate request
        $auth = $this->getRequestAuthentication();
        if ($auth) {
            $request = $auth->authenticateRequest($request);
        }

        // Send request
        $response = $this->getHttpTransporter()->send($request);
        $responseData = $response->getData();

        $requestId = $responseData['request_id'] ?? null;
        if (!$requestId) {
            throw new \Exception('Failed to submit prediction request to MuAPI: ' . json_encode($responseData));
        }

        // Poll for results
        $videoUrl = $this->pollPredictionResult($requestId);

        // Build Result DTO
        $file = new File($videoUrl, 'video/mp4');
        $messagePart = new MessagePart($file);
        $message = new Message(MessageRoleEnum::model(), [$messagePart]);
        $candidate = new Candidate($message, FinishReasonEnum::stop());
        $tokenUsage = new TokenUsage(0, 0, 0);

        return new GenerativeAiResult(
            id: $requestId,
            candidates: [$candidate],
            tokenUsage: $tokenUsage,
            providerMetadata: $this->providerMetadata(),
            modelMetadata: $this->metadata(),
            additionalData: ['provider' => 'muapi', 'model' => $modelId]
        );
    }

    /**
     * Extract a single prompt string from the message history array.
     *
     * @param array $prompt
     * @return string
     */
    private function getPromptString(array $prompt): string {
        $promptString = '';
        foreach ($prompt as $message) {
            if ($message instanceof Message) {
                foreach ($message->getParts() as $part) {
                    if (method_exists($part, 'getText')) {
                        $promptString .= $part->getText() . ' ';
                    } elseif (method_exists($part, 'getContent') && is_string($part->getContent())) {
                        $promptString .= $part->getContent() . ' ';
                    }
                }
            } elseif (is_array($message) && isset($message['parts'])) {
                foreach ($message['parts'] as $part) {
                    if (is_string($part)) {
                        $promptString .= $part . ' ';
                    } elseif (is_array($part) && isset($part['text'])) {
                        $promptString .= $part['text'] . ' ';
                    }
                }
            }
        }
        return trim($promptString);
    }

    /**
     * Poll the prediction result until completed or failed.
     *
     * @param string $requestId
     * @return string
     * @throws \Exception
     */
    private function pollPredictionResult(string $requestId): string {
        $maxAttempts = 30;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;

            $request = new Request(
                method: HttpMethodEnum::GET(),
                uri: MuApiProvider::url("/api/v1/predictions/{$requestId}/result")
            );

            $auth = $this->getRequestAuthentication();
            if ($auth) {
                $request = $auth->authenticateRequest($request);
            }

            $response = $this->getHttpTransporter()->send($request);
            $responseData = $response->getData();

            $status = $responseData['status'] ?? 'failed';

            if ($status === 'completed') {
                $outputs = $responseData['outputs'] ?? [];
                if (empty($outputs)) {
                    throw new \Exception('Prediction request completed but returned no outputs');
                }
                return $outputs[0];
            }

            if ($status === 'failed' || $status === 'cancelled') {
                throw new \Exception("Prediction request failed with status: {$status}");
            }

            sleep(2);
        }

        throw new \Exception('Prediction request timed out');
    }

    /**
     * Map model ID to API endpoint slug.
     *
     * @param string $modelId
     * @param bool   $hasImage
     * @return string
     */
    private function getEndpointSlug(string $modelId, bool $hasImage = false): string {
        if ($hasImage) {
            $mappings = [
                'veo3' => 'veo3-image-to-video',
                'veo3-fast' => 'veo3-fast-image-to-video',
                'kling-master' => 'kling-v2.1-master-i2v',
                'wan2.1' => 'wan2.1-image-to-video',
                'wan2.2' => 'wan2.2-image-to-video',
                'seedance-pro' => 'seedance-pro-i2v',
                'seedance-lite' => 'seedance-lite-i2v',
                'hunyuan' => 'hunyuan-image-to-video',
                'runway' => 'runway-image-to-video',
                'pixverse' => 'pixverse-v6-i2v',
                'minimax-hailuo-02-std' => 'minimax-hailuo-02-standard-i2v',
                'minimax-hailuo-02-pro' => 'minimax-hailuo-02-pro-i2v',
                // New video models (Image-to-Video)
                'sd-2' => 'sd-2-image-to-video',
                'wan2.7' => 'wan2.7-image-to-video',
                'gemini-omni' => 'gemini-omni-image-to-video',
                'openai-sora-2' => 'openai-sora-2-image-to-video',
                'kling-v3.0' => 'kling-v3.0-standard-image-to-video',
                'pixverse-v6' => 'pixverse-v6-i2v',
                'ltx-2.3' => 'ltx-2.3-image-to-video',
            ];
        } else {
            $mappings = [
                'veo3' => 'veo3-text-to-video',
                'veo3-fast' => 'veo3-fast-text-to-video',
                'kling-master' => 'kling-v2.1-master-t2v',
                'wan2.1' => 'wan2.1-text-to-video',
                'wan2.2' => 'wan2.2-text-to-video',
                'seedance-pro' => 'seedance-pro-t2v',
                'seedance-lite' => 'seedance-lite-t2v',
                'hunyuan' => 'hunyuan-text-to-video',
                'runway' => 'runway-text-to-video',
                'pixverse' => 'pixverse-v6-t2v',
                'minimax-hailuo-02-std' => 'minimax-hailuo-02-standard-t2v',
                'minimax-hailuo-02-pro' => 'minimax-hailuo-02-pro-t2v',
                // New video models (Text-to-Video)
                'sd-2' => 'sd-2-text-to-video',
                'wan2.7' => 'wan2.7-text-to-video',
                'gemini-omni' => 'gemini-omni-text-to-video',
                'openai-sora-2' => 'openai-sora-2-text-to-video',
                'kling-v3.0' => 'kling-v3.0-standard-text-to-video',
                'pixverse-v6' => 'pixverse-v6-t2v',
                'ltx-2.3' => 'ltx-2.3-text-to-video',
            ];
        }
        return $mappings[$modelId] ?? $modelId;
    }
}
