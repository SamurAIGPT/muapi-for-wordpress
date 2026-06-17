<?php
/**
 * Request authentication for MuAPI.
 */

namespace MuApiForWordPress\Http;

use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class MuApiRequestAuthentication extends ApiKeyRequestAuthentication {
    /**
     * Authenticate request by adding the x-api-key header.
     *
     * @param Request $request The request to authenticate.
     * @return Request The authenticated request.
     */
    public function authenticateRequest(Request $request): Request {
        return $request->withHeader('x-api-key', $this->getApiKey());
    }
}
