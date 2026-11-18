<?php

/**
 * Plugin Name: Comment Draft Replies, HTTP API caller (example)
 * Description: Example `cdreplies_model_caller` for sites without Composer. Calls an OpenAI-compatible chat completions endpoint through the WordPress HTTP API. Not loaded by default: copy it to wp-content/mu-plugins/ to use it.
 * Version: 0.1.0
 * License: MIT
 *
 * The plugin applies the kill switch, the daily cap, the rate limits and the
 * spend-meter check before this filter runs, counts every call, and
 * validates whatever array comes back (schema and reply checks). This
 * caller only moves bytes and maps HTTP failures to the three error codes
 * the plugin understands.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_filter('cdreplies_model_caller', 'cdreplies_example_http_caller', 10, 2);

/**
 * @param mixed $result null unless another caller already answered
 * @param array{purpose:string,system:string,user:string,schema:array<string,mixed>,max_output_tokens:int,deadline:int} $request
 * @return array<mixed>|WP_Error|null
 */
function cdreplies_example_http_caller($result, array $request)
{
    if ($result !== null) {
        return $result;
    }
    $settings = get_option('cdreplies_settings', []);
    $settings = is_array($settings) ? $settings : [];
    $key = defined('CDREPLIES_PROVIDER_KEY') ? (string) CDREPLIES_PROVIDER_KEY : (string) ($settings['api_key'] ?? '');
    $base = rtrim((string) ($settings['base_url'] ?? 'https://openrouter.ai/api/v1'), '/');
    $model = (string) ($settings['model'] ?? '');
    if ($key === '' || $model === '' || strpos($base, 'https://') !== 0) {
        return new WP_Error('cdreplies_not_configured', 'Set an https base URL, a key and a model.');
    }

    $response = wp_remote_post($base . '/chat/completions', [
        'timeout' => (int) $request['deadline'],
        'redirection' => 0,
        'headers' => [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode([
            'model' => $model,
            'max_tokens' => (int) $request['max_output_tokens'],
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $request['system'] . "\n\nAnswer with JSON matching this schema:\n" . wp_json_encode($request['schema'])],
                ['role' => 'user', 'content' => $request['user']],
            ],
        ]),
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('cdreplies_retryable', 'Transport error.');
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if (in_array($code, [401, 402, 403], true)) {
        return new WP_Error('cdreplies_account', 'The provider refused the key or the account is out of credit.');
    }
    if ($code === 408 || $code === 429 || $code >= 500) {
        return new WP_Error('cdreplies_retryable', 'Provider busy or failing.');
    }
    if ($code !== 200) {
        return new WP_Error('cdreplies_rejected', 'The provider rejected the request.');
    }

    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    $content = is_array($body) ? ($body['choices'][0]['message']['content'] ?? null) : null;
    $data = is_string($content) ? json_decode($content, true) : null;

    // Anything that is not an object fails the plugin's schema check and is retried once.
    return is_array($data) ? $data : ['action' => 'invalid'];
}
