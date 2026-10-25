<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\EventListener;

use Altmenorg\SyliusSkeepersPlugin\Widget\WidgetRenderer;
use Sylius\Bundle\CoreBundle\SectionResolver\SectionProviderInterface;
use Sylius\Bundle\ShopBundle\SectionResolver\ShopSection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Adds the widgets placed in "head" before `</head>` and those placed in "body_end" before the
 * last `</body>` of the shop HTML pages: whatever the theme, nothing to edit in the templates.
 * Admin, API, AJAX and non-HTML answers are left alone.
 */
final class WidgetInjectionListener
{
    public function __construct(
        private readonly WidgetRenderer $renderer,
        private readonly ?SectionProviderInterface $sections = null,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->isXmlHttpRequest()) {
            return;
        }
        if ($this->sections !== null && !$this->sections->getSection() instanceof ShopSection) {
            return;
        }
        $response = $event->getResponse();
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse
            || !str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return;
        }
        $content = $response->getContent();
        if (!\is_string($content) || $content === '') {
            return;
        }

        $head = $this->renderer->renderPlacement('head');
        if ($head !== '' && ($pos = stripos($content, '</head>')) !== false) {
            $content = substr_replace($content, $head, $pos, 0);
        }
        $body = $this->renderer->renderPlacement('body_end');
        if ($body !== '' && ($pos = strripos($content, '</body>')) !== false) {
            $content = substr_replace($content, $body, $pos, 0);
        }
        $response->setContent($content);
    }
}
