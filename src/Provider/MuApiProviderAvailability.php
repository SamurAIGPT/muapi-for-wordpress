<?php
/**
 * Provider availability logic for MuAPI.
 */

namespace MuApiForWordPress\Provider;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class MuApiProviderAvailability implements ProviderAvailabilityInterface, WithRequestAuthenticationInterface {
    use WithRequestAuthenticationTrait {
        getRequestAuthentication as getRequestAuthenticationOriginal;
    }

    /**
     * @var string
     */
    private string $apiKey;

    /**
     * Constructor.
     *
     * @param string $apiKey
     */
    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }

    /**
     * Checks if the provider is configured and available.
     *
     * @return bool
     */
    public function isConfigured(): bool {
        try {
            $auth = $this->getRequestAuthenticationOriginal();
            if ($auth && method_exists($auth, 'getApiKey')) {
                return !empty($auth->getApiKey());
            }
        } catch (\Exception $e) {
            // Silent fallback to default option key.
        }

        return !empty($this->apiKey);
    }
}
