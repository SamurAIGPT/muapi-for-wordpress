<?php
/**
 * Text model for MuAPI.
 */

namespace MuApiForWordPress\Models;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
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

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class MuApiTextModel extends AbstractApiBasedModel implements TextGenerationModelInterface {
    /**
     * Constructor.
     *
     * @param ModelMetadata $meta
     */
    public function __construct(ModelMetadata $meta) {
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
     * Generates text based on the prompt.
     *
     * @param array $prompt
     * @param array $options
     * @return GenerativeAiResult
     * @throws \Exception
     */
    public function generateTextResult(array $prompt, array $options = []): GenerativeAiResult {
        $promptString = $this->getPromptString($prompt);
        $modelId = $this->metadata()->getId();
        $endpoint = MuApiProvider::url('/api/v1/' . $modelId);

        $data = [];
        if ($modelId === 'generate-social-video-script') {
            $data['title'] = $promptString;
        } else {
            if (!empty($promptString)) {
                $data['prompt'] = $promptString;
            }
        }

        $customOptions = $this->getConfig()->getCustomOptions();
        if (!empty($customOptions)) {
            $data = array_merge($data, $customOptions);
        }

        if (!empty($options)) {
            $data = array_merge($data, $options);
        }

        // Clean up empty fields
        if (isset($data['image_url']) && empty($data['image_url'])) {
            unset($data['image_url']);
        }
        if (isset($data['system_prompt']) && empty($data['system_prompt'])) {
            unset($data['system_prompt']);
        }

        $request = new Request(
            method: HttpMethodEnum::POST(),
            uri: $endpoint,
            headers: [
                'Content-Type' => 'application/json',
            ],
            data: $data
        );

        $auth = $this->getRequestAuthentication();
        if ($auth) {
            $request = $auth->authenticateRequest($request);
        }

        $response = $this->getHttpTransporter()->send($request);
        $responseData = $response->getData();

        $requestId = $responseData['request_id'] ?? null;
        if (!$requestId) {
            throw new \Exception('Failed to submit text generation request to MuAPI: ' . json_encode($responseData));
        }

        $text = $this->pollPredictionResult($requestId);

        // Build Result DTO
        $messagePart = new MessagePart($text);
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
}
