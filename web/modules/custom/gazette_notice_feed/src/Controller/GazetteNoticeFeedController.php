<?php

namespace Drupal\gazette_notice_feed\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\gazette_notice_feed\Service\GazetteNoticeFeedApi;
use Drupal\Core\Render\Markup;
use Drupal\Core\Pager\PagerManagerInterface;

class GazetteNoticeFeedController extends ControllerBase
{
    /**
     * The API service.
     */
    protected GazetteNoticeFeedApi $apiService;

    /**
     * The request stack.
     */
    protected RequestStack $requestStack;

    /**
     * The pager manager.
     */
    protected PagerManagerInterface $pagerManager;

    public function __construct(
        GazetteNoticeFeedApi $api_service,
        RequestStack $request_stack,
        PagerManagerInterface $pager_manager
    ) {
        $this->apiService = $api_service;
        $this->requestStack = $request_stack;
        $this->pagerManager = $pager_manager;
    }

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('gazette_notice_feed.api'),
            $container->get('request_stack'),
            $container->get('pager.manager')
        );
    }

    public function list(): array
    {
        $page = (int) $this->requestStack->getCurrentRequest()->query->get('page', 0) + 1;
        $per_page = 10;
        $data = $this->apiService->fetchNotices($page, $per_page);
        $items = [];

        if (!empty($data['entry'])) {
            foreach ($data['entry'] as $notice) {
                $title = $this->cleanTitle($notice['title'] ?? '');
                $url = $notice['link'][1]['@href'] ?? '#';
                $date = $this->formatDate($notice['published'] ?? '');
                $content = $notice['content'] ?? '';

                $items[] = [
                    '#type' => 'container',
                    '#attributes' => ['class' => ['notice-item']],
                    'title' => [
                        '#type' => 'html_tag',
                        '#tag' => 'h2',
                        '#value' => sprintf(
                            '<a href="%s" target="_blank" rel="noopener">%s</a>',
                            htmlspecialchars($url),
                            htmlspecialchars($title)
                        ),
                        '#allowed_tags' => ['a', 'h2'],
                    ],
                    'date' => [
                        '#type' => 'html_tag',
                        '#tag' => 'time',
                        '#value' => $date,
                    ],
                    'content' => [
                        '#markup' => Markup::create($content),
                    ],
                ];
            }
        }

        if (empty($items)) {
            $items[] = [
                '#markup' => $this->t('No notices found or unable to fetch data from The Gazette API.'),
            ];
        }

        // Setup pager.
        $total = (int) ($data['f:total'] ?? 0);
        $this->pagerManager->createPager($total, $per_page);

        return [
            'list' => [
                '#theme' => 'item_list',
                '#items' => $items,
                '#attributes' => ['class' => ['gazette-notice-list']],
            ],
            'pager' => [
                '#type' => 'pager',
            ],
        ];
    }

    /**
     * Cleans the title by removing control characters and normalizing whitespace.
     */
    private function cleanTitle(string $title): string
    {
        if (empty($title)) {
            return 'Untitled';
        }

        return trim(preg_replace('/[\x00-\x1F\x7F]/', '', preg_replace('/\s+/', ' ', $title)));
    }

    /**
     * Formats the date in the required format.
     */
    private function formatDate(string $date): string
    {
        if (empty($date)) {
            return '';
        }

        return date('j F Y', strtotime($date));
    }
}