# Sylius Skeepers Plugin

[Skeepers](https://skeepers.io) (Verified Reviews, Avis Vérifiés) for Sylius 2:

- **review requests**: orders are sent to the purchase-event API, Skeepers then emails each
  customer a request for a brand review and product reviews;
- **product reviews**: the published product reviews are imported into Sylius reviews, with
  average rating and review count;
- **widgets**: the scripts given by the Skeepers portal, added to the shop pages from the
  configuration.

Built for Sylius 2 (tested on Sylius 2.3, Symfony 7.4, PHP 8.3, MariaDB). MIT licence.

> **Every purchase event sent makes Skeepers email a real customer.** Sending is off until you
> switch it on (`purchase_events.enabled: true`), and `--dry-run` shows what would be sent.

## Installation

1. Require the package. It is not on Packagist: declare the GitHub repository.

   ```json
   {
       "repositories": [
           { "type": "vcs", "url": "https://github.com/altmenorg/sylius-skeepers-plugin" }
       ],
       "require": {
           "altmenorg/sylius-skeepers-plugin": "^0.1"
       }
   }
   ```

2. Register the bundle in `config/bundles.php`:

   ```php
   Altmenorg\SyliusSkeepersPlugin\AltmenorgSyliusSkeepersPlugin::class => ['all' => true],
   ```

3. For the review import, add the two review columns to your `ProductReview` entity:

   ```php
   use Altmenorg\SyliusSkeepersPlugin\Model\SkeepersProductReviewInterface;
   use Altmenorg\SyliusSkeepersPlugin\Model\SkeepersProductReviewTrait;

   #[ORM\Entity]
   #[ORM\Table(name: 'sylius_product_review')]
   class ProductReview extends BaseProductReview implements SkeepersProductReviewInterface
   {
       use SkeepersProductReviewTrait;
   }
   ```

4. Run the migrations (`bin/console doctrine:migrations:migrate`). The plugin's migration
   creates the queue table `altmenorg_skeepers_purchase_event` and adds `external_id` (unique)
   and `author_name` to `sylius_product_review` only when they are missing.

5. Clear the cache.

## Configuration

```yaml
# config/packages/altmenorg_sylius_skeepers.yaml
altmenorg_sylius_skeepers:
    # API account: Skeepers portal, Collect reviews > Review request sending > API
    client_id: '%env(SKEEPERS_CLIENT_ID)%'
    client_secret: '%env(SKEEPERS_CLIENT_SECRET)%'
    # token_url: https://auth.skeepers.io/realms/skeepers/protocol/openid-connect/token
    # api_url: https://api.skeepers.io

    purchase_events:
        enabled: false                  # safety switch, see above
        website_id: '%env(SKEEPERS_WEBSITE_ID)%'
        trigger: order_fulfilled        # manual | order_fulfilled | shipment_shipped
        excluded_product_codes: [GIFT_WRAP]
        excluded_item_flag: ~           # property path on the order item, e.g. giftWrap
        brand_path: ~                   # property path on the product, e.g. brand.name
        default_brand: ~
        ean_path: ~                     # property path on the variant, e.g. ean13
        language: ~                     # null: from the order locale (fr_FR gives fr)
        max_order_age_days: 90          # older orders are skipped, not sent
        product_route: sylius_shop_product_show
        image_base_url: ~               # e.g. https://shop.example.com/media/image

    product_reviews:
        locale: en_US                   # locale of the slugs matched against product_page_url
        bot_customer: { email: verified-reviews@example.invalid, first_name: Verified, last_name: Reviews }
        raw_dir: ~                      # keep the raw answers (JSON lines) there

    widgets:
        floating:
            src: https://widgets.rr.skeepers.io/generated/<website>/<widget>.js
            placement: head             # head | body_end | manual
        product_stars:
            code: '<div class="skeepers_product__stars" data-product-id="…"></div>'
            placement: manual
```

## Review requests (purchase events)

### What is sent

One purchase event per order, to
`POST /purchase-event/websites/{website_id}/purchase_events/bulk_sync_insert`, 50 per request
([API documentation](https://apidocs.skeepers.io/purchase-events.html)):

```json
{
    "purchase_reference": "230003",
    "purchase_date": "2026-10-23T10:51:45Z",
    "price": 66.9,
    "consumer": {
        "first_name": "Jane", "last_name": "Doe", "email": "jane@example.com",
        "phone": "0600000000", "language": "fr", "country": "FR"
    },
    "products": [
        {
            "product_ref": { "reference": "1C1B", "ean": "3700000000017" },
            "name": "Curcuma", "brand": "Brand", "price": 4.5,
            "product_url": "https://shop.example.com/curcuma.html",
            "image_url": "https://shop.example.com/media/image/curcuma.jpg"
        }
    ],
    "sales_channel": { "channel": "online", "website_id": "…" }
}
```

- `purchase_reference`: the order number; `purchase_date`: checkout completion, in UTC;
  `price`: order total.
- `consumer`: billing address names and phone (customer's if missing), customer email,
  language, billing country.
- `products`: **one entry per product, not per variant**: the reference is the product code,
  the reviews being about the product. The price is the unit price paid on the first line of the
  product. The EAN is sent only when every ordered variant of the product has the same one.
  Lines of `excluded_product_codes`, or whose `excluded_item_flag` reads true, are left out.
- `solicitation_parameters` is left out: the delays and the request type set in the Skeepers
  portal apply.
- Empty values are left out, not sent as null.

Replace `Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\ProductLinkResolverInterface` (product page
and image URLs) or `PurchaseEventBuilderInterface` (the whole event) with your own services when
needed.

### When

`trigger` queues the order when a Symfony workflow transition completes: `fulfill` of
`sylius_order` (`order_fulfilled`) or `ship` of `sylius_shipment` (`shipment_shipped`). With
`manual`, your code queues it:

```php
public function __construct(private PurchaseEventQueue $queue) {}

$this->queue->enqueue($order); // false if disabled, not saved yet, or already queued
```

`enqueue()` uses plain DBAL statements and may be called from a Doctrine `postFlush` listener.
An order is queued once only (unique `order_id`): it is never sent twice. While the plugin is
disabled, nothing is queued, so switching it on does not release a backlog of old orders.

### Sending

```bash
bin/console skeepers:purchase-events:send             # cron, every few minutes
bin/console skeepers:purchase-events:send --dry-run   # print the JSON, send nothing
bin/console skeepers:purchase-events:send --dry-run --order=230003 --order=230002
bin/console skeepers:purchase-events:send --status    # rows per state
bin/console skeepers:purchase-events:enqueue 230003   # queue an order by hand
```

| Answer | Outcome |
|---|---|
| 201 | all sent, the Skeepers ID kept |
| 206 | the events listed in the answer sent, the others in error (`error_description`) |
| 400 | nothing created: a batch is sent again one event at a time, an event refused alone is in error |
| 401 | token renewed once |
| 429 | left pending, the run stops |
| no answer, 5xx | in error, **outcome unknown** (Skeepers may have created them), the run stops |

Orders in error are never sent again automatically: check in the portal, then
`--retry-errors`. Orders without email, not completed, or older than `max_order_age_days` are
skipped. The token (OAuth2 client credentials, scope `openid`) is kept until 30 seconds before
it expires.

## Product reviews

```bash
bin/console skeepers:product-reviews:sync [--since=2026-10-01] [--raw-dir=var/skeepers] [--dry-run]
```

- reads every published review (verified-reviews API, audience `verified-reviews`), 200 per
  page; with `--since`, stops at the first review older than the date;
- leaves out syndicated reviews (from other websites of the same brand);
- product: the slug of `product_page_url` (`match_by_url`, `url_pattern`, `locale`), then
  `product_variation_id` as a product code, then as a variant code, then each part of a composed
  reference (`6A1D-5C4C`). Replace or decorate `ReviewProductResolverInterface` for other rules;
- author: the customer of the reviewed order (`order_reference` = order number) when it is in
  the shop, else the `bot_customer`, created once; the published name ("Jane D.") in
  `author_name`, "Anonymous" for an undisclosed author;
- a review already imported (same `external_id`) is updated, never duplicated; reviews are
  written as accepted;
- then the average rating and the review count of the reviewed products are computed again.

## Widgets

Each widget is the script URL given by the portal (`src`, with `defer`, `async`, `charset`) or
the code to paste as it is (`code`), and a placement:

- `head`: before `</head>` of every shop page;
- `body_end`: before the last `</body>` of every shop page;
- `manual`: only where a template calls `{{ skeepers_widget('product_stars') }}`.

`{{ skeepers_widgets('head') }}` prints every enabled widget of a placement. A widget can be
switched off with `enabled: false`. With no widget configured, nothing is added and no listener
is registered. Admin, API, AJAX and non-HTML answers are left alone.

## Tests

```bash
composer install
vendor/bin/phpunit
```

From a Sylius application that has the plugin installed:

```bash
SKEEPERS_AUTOLOAD=/path/to/shop/vendor/autoload.php /path/to/shop/vendor/bin/phpunit \
    -c vendor/altmenorg/sylius-skeepers-plugin/phpunit.xml.dist
```

The tests cover the JSON built from an order, the batches of 50, the answers of the API
(mocked), the token cache, the widgets and the page injection.

## Licence

MIT, see [LICENSE](LICENSE).
