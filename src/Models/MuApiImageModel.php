<?php
/**
 * Image model for MuAPI.
 */

namespace MuApiForWordPress\Models;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
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

class MuApiImageModel extends AbstractApiBasedModel implements ImageGenerationModelInterface {
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
     * Generates an image based on the prompt.
     *
     * @param array $prompt
     * @param array $options
     * @return GenerativeAiResult
     * @throws \Exception
     */
    public function generateImageResult(array $prompt, array $options = []): GenerativeAiResult {
        // Extract prompt string
        $promptString = $this->getPromptString($prompt);

        // Submit request to MuAPI
        $modelId = $this->metadata()->getId();
        $endpointSlug = $this->getEndpointSlug($modelId);
        $endpoint = MuApiProvider::url('/api/v1/' . $endpointSlug);

        // Build data array
        $data = [];
        if (!empty($promptString)) {
            $data['prompt'] = $promptString;
        }

        // Merge custom options from config
        $customOptions = $this->getConfig()->getCustomOptions();
        if (!empty($customOptions)) {
            $data = array_merge($data, $customOptions);
        }

        // Merge options if any are passed explicitly
        if (!empty($options)) {
            $data = array_merge($data, $options);
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
        $imageUrl = $this->pollPredictionResult($requestId);

        // Build Result DTO
        $file = new File($imageUrl, 'image/png');
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
     * @return string
     */
    private function getEndpointSlug(string $modelId): string {
        $mappings = [
            'flux-dev' => 'flux-dev-image',
            'flux-schnell' => 'flux-schnell-image',
            'flux-kontext-dev' => 'flux-kontext-dev-t2i',
            'flux-kontext-pro' => 'flux-kontext-pro-t2i',
            'flux-kontext-max' => 'flux-kontext-max-t2i',
            'hidream-fast' => 'hidream_i1_fast_image',
            'hidream-dev' => 'hidream_i1_dev_image',
            'hidream-full' => 'hidream_i1_full_image',
            'wan2.1' => 'wan2.1-text-to-image',
            'reve' => 'reve-text-to-image',
            'gpt4o' => 'gpt4o-text-to-image',
            'midjourney' => 'midjourney-v7-text-to-image',
            'seedream' => 'seedream-text-to-image',
            'upscale' => 'ai-image-upscale',
            'bg-remove' => 'ai-background-remover',
            'face-swap' => 'ai-image-face-swap',
            'colorize' => 'ai-color-photo',
            'ghibli' => 'ai-ghibli-style',
            'anime' => 'ai-anime-generator',
            'product-shot' => 'ai-product-shot',
            'object-erase' => 'ai-object-eraser',
            // New image models
            'flux-2-klein-9b' => 'flux-2-klein-9b-text-to-image-lora',
            'gpt4o-text-to-image' => 'gpt4o-text-to-image',
            'grok-imagine-text-to-image-quality' => 'grok-imagine-text-to-image-quality',
            'kling-o3-image' => 'kling-o3-image',
        ];
        return $mappings[$modelId] ?? $modelId;
    }
}
