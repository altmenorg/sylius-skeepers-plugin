<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Twig;

use Altmenorg\SyliusSkeepersPlugin\Widget\WidgetRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{{ skeepers_widget('product') }}`: one widget where the template wants it (placement "manual",
 * or any other); `{{ skeepers_widgets('head') }}`: every enabled widget of a placement.
 */
final class SkeepersExtension extends AbstractExtension
{
    public function __construct(private readonly WidgetRenderer $renderer)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('skeepers_widget', $this->renderer->render(...), ['is_safe' => ['html']]),
            new TwigFunction('skeepers_widgets', $this->renderer->renderPlacement(...), ['is_safe' => ['html']]),
        ];
    }
}
