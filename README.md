# MuAPI for WordPress

[![WordPress Version](https://img.shields.io/badge/WordPress-6.0%20%2B-blue.svg?style=for-the-badge&logo=wordpress)](https://wordpress.org)
[![PHP Version](https://img.shields.io/badge/PHP-8.0%20%2B-purple.svg?style=for-the-badge&logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg?style=for-the-badge)](https://www.gnu.org/licenses/gpl-2.0.html)

A premium generative AI plugin that makes **[muapi.ai](https://muapi.ai)** the native image, video, audio, and text generation provider in the WordPress ecosystem. Fully integrated with the **WordPress AI Client SDK (`wp-ai-client`)** and enriches the WordPress Media Library with a complete **Media Studio** workspace.

---

## ✨ Key Features

*   **🎨 Media Studio (WP 6.x & 7.x):** A glassmorphic admin dashboard under **Media → Generate with MuAPI** for instant generation of images, videos, audio/music, and text.
*   **🔌 Native AI Client SDK Connector:** Registers MuAPI as a core provider. Any theme, page builder, or block utilizing `AiClient::prompt()` can route requests automatically to MuAPI.
*   **⚡ Async Generation & Polling:** Performs background prediction polling on the MuAPI server to prevent PHP execution timeouts and seamlessly sideloads output media directly into the WordPress Media Library.
*   **🎛️ Dynamic Input Schemas:** Features a fully interactive JS form builder that dynamically generates options, ranges, media uploads, and parameter toggles for each model.

---

## 🚀 Supported Models

| Category | Highlights / Popular Models | Parameters supported |
| :--- | :--- | :--- |
| **🖼️ Image Generation** | Flux Schnell, Flux Dev, Midjourney, Grok 3 (xAI), DALL-E 3 (OpenAI) | Aspect Ratio, Speed, Variety, Stylization, Weirdness, Image Uploads |
| **🎥 Video Generation** | Veo 3.1, Seedance 2.0, Seedance 1.5 Pro, Happy Horse, Kling 3.0 Standard/Pro, LTX Studio 2.3, Pixverse V6, Wan 2.7, Sora 2 (OpenAI), Gemini Omni | Aspect Ratio, Custom Duration (Seconds), Resolution, Reference Image, Last Image |
| **🎵 Audio & Music** | Suno (Create Music, Remix, Extend, Sounds, Mashup, Add Vocals), Minimax (Voice Clone, Speech HD, Speech Turbo), mmAudio v2 | Custom Styles, Vocal Gender, instrumental only, Audio uploads for reference |
| **✍️ AI Text & Assistant** | Gemini 3 Flash / 3.5, Claude Sonnet 4.6, Claude Opus 4.8, GPT 5.5, Script Engine | Prompt, System Instructions, Reasoning Effort, Web Search, Niche / Platform |
| **🛠️ Image Enhancement** | Upscale, Background Removal, Face Swap, Object Erase | Target Face Index, Mask Image, Swap Image |

---

## 📦 Installation

1.  Clone or download this repository into your `/wp-content/plugins/muapi-for-wordpress` folder:
    ```bash
    git clone https://github.com/SamurAIGPT/muapi-for-wordpress.git
    ```
2.  Activate the plugin through the **Plugins** menu in your WordPress dashboard.
3.  Go to **Settings → Connectors** in the WordPress admin panel, find **MuAPI**, enter your API Key, and save.
4.  Start creating assets right away from **Media → Generate with MuAPI**!

### 🔑 Configuration via `wp-config.php` (Recommended for staging/production)

To avoid saving API credentials in the database, you can define your MuAPI key directly in `wp-config.php`:

```php
define('MUAPI_API_KEY', 'your-muapi-api-key-here');
```

Alternatively, `WP_MUAPI_API_KEY` is also supported. Defining either constant will take priority over database settings.

---

## 🔧 SDK Code Usage

Once connected, developers can use WordPress AI Client SDK to route prompts to MuAPI programmatically:

### Generate Text
```php
use WordPress\AiClient\AiClient;
use MuApiForWordPress\Provider\MuApiProvider;

$model = MuApiProvider::model('gemini-3-flash');
$result = AiClient::prompt("Write a short intro about AI in WordPress")
    ->usingModel($model)
    ->generateTextResult();

echo $result->getCandidates()[0]->getMessage()->getParts()[0]->getText();
```

### Generate Video
```php
use WordPress\AiClient\AiClient;
use MuApiForWordPress\Provider\MuApiProvider;

$model = MuApiProvider::model('veo3.1-text-to-video');
$result = AiClient::prompt("A cinematic shot of a sunset over mountains")
    ->usingModel($model)
    ->generateVideoResult();

$videoUrl = $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile()->getUrl();
```

---

## 🔒 Disclosure

This plugin connects to **[muapi.ai](https://muapi.ai)** as an external service to process image, video, audio, and text generation requests. An API key is required to use this service, which can be generated in your [MuAPI Dashboard](https://muapi.ai/dashboard/api-keys). No personal data is transmitted except the generation prompts and chosen options.

## 📄 License

This project is licensed under the GPLv2 License or later.
