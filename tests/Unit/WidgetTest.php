<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Tests\Unit;

use Altmenorg\SyliusSkeepersPlugin\EventListener\WidgetInjectionListener;
use Altmenorg\SyliusSkeepersPlugin\Widget\WidgetRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class WidgetTest extends TestCase
{
    private const SRC = 'https://widgets.rr.skeepers.io/generated/site/widget.js';

    private static function widget(array $values): array
    {
        return $values + ['enabled' => true, 'src' => null, 'defer' => true, 'async' => false, 'charset' => 'utf-8', 'code' => null, 'placement' => 'head'];
    }

    private static function renderer(): WidgetRenderer
    {
        return new WidgetRenderer([
            'floating' => self::widget(['src' => self::SRC]),
            'product' => self::widget(['code' => '<div class="skeepers_product__stars"></div>', 'placement' => 'manual']),
            'footer' => self::widget(['src' => 'https://example.com/b.js', 'defer' => false, 'async' => true, 'charset' => null, 'placement' => 'body_end']),
            'off' => self::widget(['src' => 'https://example.com/off.js', 'enabled' => false]),
        ]);
    }

    public function testMarkup(): void
    {
        $r = self::renderer();
        // the tag Skeepers gives, attribute for attribute
        self::assertSame('<script defer charset="utf-8" src="' . self::SRC . '"></script>', $r->render('floating'));
        self::assertSame('<div class="skeepers_product__stars"></div>', $r->render('product'));
        self::assertSame('<script async src="https://example.com/b.js"></script>', $r->render('footer'));
        self::assertSame('', $r->render('off'));
        self::assertSame('', $r->render('unknown'));
        self::assertSame($r->render('floating'), $r->renderPlacement('head'), 'disabled widgets left out');
        self::assertSame('', (new WidgetRenderer())->renderPlacement('head'));
    }

    private static function event(Response $response, bool $main = true): ResponseEvent
    {
        $kernel = new class() implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        return new ResponseEvent($kernel, new Request(), $main ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST, $response);
    }

    public function testInjectedOnceInHeadAndBody(): void
    {
        $listener = new WidgetInjectionListener(self::renderer());
        $event = self::event(new Response('<html><head><title>t</title></head><body><p>x</p></body></html>'));
        $listener($event);

        self::assertSame(
            '<html><head><title>t</title><script defer charset="utf-8" src="' . self::SRC . '"></script></head><body><p>x</p><script async src="https://example.com/b.js"></script></body></html>',
            $event->getResponse()->getContent(),
        );
    }

    public function testOtherAnswersLeftAlone(): void
    {
        $listener = new WidgetInjectionListener(self::renderer());
        foreach ([self::event(new JsonResponse(['a' => '</head>'])), self::event(new Response('<head></head>'), false)] as $event) {
            $before = $event->getResponse()->getContent();
            $listener($event);
            self::assertSame($before, $event->getResponse()->getContent());
        }
    }
}
