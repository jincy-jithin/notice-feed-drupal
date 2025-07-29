<?php

namespace Drupal\gazette_notice_feed\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

class GazetteNoticeFeedApi
{
    /**
     * The HTTP client.
     */
    protected ClientInterface $httpClient;

    /**
     * GazetteNoticeFeedApi constructor.
     */
    public function __construct(ClientInterface $http_client)
    {
        $this->httpClient = $http_client;
    }

    /**
     * Fetches notices from The Gazette API.
     *
     * @param int $page
     *   The page number (1-based).
     * @param int $per_page
     *   Number of results per page.
     *
     * @return array|null
     *   The decoded JSON data or null on failure.
     */
    public function fetchNotices(int $page = 1, int $per_page = 10): ?array
    {
        $url = 'https://www.thegazette.co.uk/all-notices/notice/data.json';
        $options = [
            'query' => [
                'results-page' => $page,
            ],
            'timeout' => 5,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Drupal Gazette Module',
            ],
        ];

        try {
            $response = $this->httpClient->request('GET', $url, $options);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    \Drupal::logger('gazette_notice_feed')->error('Failed to decode JSON response: @error', [
                        '@error' => json_last_error_msg(),
                    ]);
                    return null;
                }

                return $data;
            }

            \Drupal::logger('gazette_notice_feed')->warning('API returned non-200 status code: @status', [
                '@status' => $response->getStatusCode(),
            ]);

        } catch (RequestException $e) {
            \Drupal::logger('gazette_notice_feed')->error('API request failed: @message', [
                '@message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            \Drupal::logger('gazette_notice_feed')->error('Unexpected error during API request: @message', [
                '@message' => $e->getMessage(),
            ]);
        }

        return null;
    }
}