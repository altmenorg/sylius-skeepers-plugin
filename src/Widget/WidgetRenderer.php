<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Widget;

/**
 * Markup of the configured Skeepers widgets. A disabled or unknown widget renders as nothing.
 */
final class WidgetRenderer
{
    /**
     * @param array<string, array{enabled: bool, src: ?string, defer: bool, async: bool, charset: ?string, code: ?string, placement: string}> $widgets
     */
    public function __construct(private readonly array $widgets = [])
    {
    }

    public function render(string $name): string
    {
        $widget = $this->widgets[$name] ?? null;
        if ($widget === null || !$widget['enabled']) {
            return '';
        }
        if ($widget['code'] !== null && $widget['code'] !== '') {
            return $widget['code'];
        }
        $attributes = '';
        if ($widget['defer']) {
            $attributes .= ' defer';
        }
        if ($widget['async']) {
            $attributes .= ' async';
        }
        if ($widget['charset'] !== null && $widget['charset'] !== '') {
            $attributes .= ' charset="' . htmlspecialchars($widget['charset'], \ENT_QUOTES) . '"';
        }

        return '<script' . $attributes . ' src="' . htmlspecialchars((string) $widget['src'], \ENT_QUOTES) . '"></script>';
    }

    /** All the enabled widgets of a placement ("head", "body_end"), in configuration order. */
    public function renderPlacement(string $placement): string
    {
        $out = '';
        foreach ($this->widgets as $name => $widget) {
            if ($widget['placement'] === $placement) {
                $out .= $this->render((string) $name);
            }
        }

        return $out;
    }
}
