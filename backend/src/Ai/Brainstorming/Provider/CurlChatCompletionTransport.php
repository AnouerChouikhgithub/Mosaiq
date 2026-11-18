<?php

declare(strict_types=1);

namespace App\Ai\Brainstorming\Provider;

use App\Ai\Exception\AiProviderFailureException;
use App\Ai\Exception\AiProviderTimeoutException;
use App\Ai\Exception\AiProviderUnavailableException;

/**
 * ext-curl transport, so the feature adds no Composer dependency (the contract
 * allows either the Symfony HTTP client or a hand-written client).
 *
 * Only transport facts are mapped here. Status interpretation, retries and
 * response-shape validation belong to the provider and the validator.
 */
final class CurlChatCompletionTransport implements ChatCompletionTransportInterface
{
    public function post(string $url, array $headers, string $body, float $timeoutSeconds): array
    {
        // Fail closed instead of a fatal error on a deployment without ext-curl.
        if (!function_exists('curl_init')) {
            throw new AiProviderUnavailableException('The ext-curl extension is required for AI generation.');
        }

        $handle = curl_init();
        if (false === $handle) {
            throw new AiProviderFailureException('cURL could not be initialised.');
        }

        $retryAfter = null;
        $requestHeaders = [];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name.': '.$value;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round(min(5.0, $timeoutSeconds) * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeoutSeconds * 1000)),
            // A provider quota response may carry the wait time; only the header
            // value is read, never the response body at this layer.
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $header) use (&$retryAfter): int {
                if (0 === stripos($header, 'retry-after:')) {
                    $value = trim(substr($header, strlen('retry-after:')));
                    if (1 === preg_match('/^\d{1,4}$/', $value)) {
                        $retryAfter = (int) $value;
                    }
                }

                return strlen($header);
            },
        ]);

        try {
            $responseBody = curl_exec($handle);
            $errorNumber = curl_errno($handle);
            $errorMessage = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }

        if (false === $responseBody || 0 !== $errorNumber) {
            if (CURLE_OPERATION_TIMEDOUT === $errorNumber) {
                throw new AiProviderTimeoutException(sprintf('cURL timed out after %.1f s.', $timeoutSeconds));
            }

            throw new AiProviderFailureException(sprintf('cURL transport error %d: %s', $errorNumber, $errorMessage));
        }

        return ['status' => $status, 'body' => (string) $responseBody, 'retryAfter' => $retryAfter];
    }
}
