<?php
/**
 * Provider class for MuAPI.
 */

namespace MuApiForWordPress\Provider;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

use MuApiForWordPress\Metadata\MuApiModelMetadata;
use MuApiForWordPress\Models\MuApiImageModel;
use MuApiForWordPress\Models\MuApiVideoModel;
use MuApiForWordPress\Models\MuApiTextModel;
use MuApiForWordPress\Models\MuApiAudioModel;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class MuApiProvider extends AbstractApiProvider {
    /**
     * Base URL of your AI service API.
     *
     * @return string
     */
    protected static function baseUrl(): string {
        return 'https://api.muapi.ai';
    }

    /**
     * Creates provider metadata.
     *
     * @return ProviderMetadata
     */
    protected static function createProviderMetadata(): ProviderMetadata {
        return new ProviderMetadata(
            id: 'muapi',
            name: 'MuAPI',
            type: ProviderTypeEnum::cloud(),
            credentialsUrl: 'https://muapi.ai/dashboard/api-keys',
            authenticationMethod: RequestAuthenticationMethod::apiKey()
        );
    }

    /**
     * Creates model metadata directory.
     *
     * @return ModelMetadataDirectoryInterface
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
        return new MuApiModelMetadata();
    }

    /**
     * Creates provider availability checker.
     *
     * @return ProviderAvailabilityInterface
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface {
        $api_key = '';
        if (defined('MUAPI_API_KEY')) {
            $api_key = MUAPI_API_KEY;
        } elseif (defined('WP_MUAPI_API_KEY')) {
            $api_key = WP_MUAPI_API_KEY;
        } else {
            $api_key = get_option('connectors_ai_muapi_api_key');
        }
        return new MuApiProviderAvailability($api_key ?: '');
    }

    /**
     * Creates a model instance.
     *
     * @param ModelMetadata $meta
     * @param ProviderMetadata $provider_metadata
     * @return ModelInterface
     */
    protected static function createModel(ModelMetadata $meta, ProviderMetadata $provider_metadata): ModelInterface {
        $capabilities = $meta->getSupportedCapabilities();
        if (in_array(CapabilityEnum::videoGeneration(), $capabilities)) {
            return new MuApiVideoModel($meta);
        }
        if (in_array(CapabilityEnum::textGeneration(), $capabilities)) {
            return new MuApiTextModel($meta);
        }
        if (
            in_array(CapabilityEnum::speechGeneration(), $capabilities) || 
            in_array(CapabilityEnum::textToSpeechConversion(), $capabilities)
        ) {
            return new MuApiAudioModel($meta);
        }
        return new MuApiImageModel($meta);
    }
}
