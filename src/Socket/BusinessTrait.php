<?php
declare(strict_types=1);

namespace BaileysPHP\Socket;

use BaileysPHP\Utils\Media;
use BaileysPHP\WABinary\BinaryNode;
use BaileysPHP\WABinary\GenericUtils;
use BaileysPHP\WABinary\Jid;

/**
 * WhatsApp Business catalog/profile — port of Baileys
 * src/Socket/business.ts + src/Utils/business.ts.
 */
trait BusinessTrait
{
    /**
     * updateBussinesProfile (sic, Baileys spelling).
     * $args: address/email/description, websites: list<string>,
     * hours: ['timezone'=>string, 'days'=>[['day'=>'mon','mode'=>'specific_hours'|'open_24h'|'closed','openTimeInMinutes'=>int,'closeTimeInMinutes'=>int]]]
     */
    public function updateBussinesProfile(array $args): BinaryNode
    {
        $nodes = [];
        foreach (['address', 'email', 'description'] as $field) {
            if (isset($args[$field])) {
                $nodes[] = new BinaryNode($field, [], (string)$args[$field]);
            }
        }
        foreach (($args['websites'] ?? []) as $website) {
            $nodes[] = new BinaryNode('website', [], (string)$website);
        }
        if (isset($args['hours'])) {
            $dayNodes = [];
            foreach (($args['hours']['days'] ?? []) as $dayConfig) {
                $attrs = [
                    'day_of_week' => $dayConfig['day'],
                    'mode' => $dayConfig['mode'],
                ];
                if ($dayConfig['mode'] === 'specific_hours') {
                    $attrs['open_time'] = (string)$dayConfig['openTimeInMinutes'];
                    $attrs['close_time'] = (string)$dayConfig['closeTimeInMinutes'];
                }
                $dayNodes[] = new BinaryNode('business_hours_config', $attrs);
            }
            $nodes[] = new BinaryNode('business_hours', ['timezone' => $args['hours']['timezone'] ?? ''], $dayNodes);
        }
        return $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'type' => 'set',
            'xmlns' => 'w:biz',
        ], [
            new BinaryNode('business_profile', ['v' => '3', 'mutation_type' => 'delta'], $nodes),
        ]));
    }

    /** updateCoverPhoto(image) → fbid */
    public function updateCoverPhoto(mixed $photo): mixed
    {
        $bytes = Media::resolveMediaInput($photo);
        $fileSha256B64 = base64_encode(hash('sha256', $bytes, true));
        $upload = $this->waUploadToServer($bytes, 'biz-cover-photo', $fileSha256B64);
        $fbid = $upload['fbid'];
        $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'type' => 'set',
            'xmlns' => 'w:biz',
        ], [
            new BinaryNode('business_profile', ['v' => '3', 'mutation_type' => 'delta'], [
                new BinaryNode('cover_photo', [
                    'id' => (string)$fbid,
                    'op' => 'update',
                    'token' => (string)($upload['meta_hmac'] ?? ''),
                    'ts' => (string)($upload['ts'] ?? ''),
                ]),
            ]),
        ]));
        return $fbid;
    }

    public function removeCoverPhoto(string $id): BinaryNode
    {
        return $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'type' => 'set',
            'xmlns' => 'w:biz',
        ], [
            new BinaryNode('business_profile', ['v' => '3', 'mutation_type' => 'delta'], [
                new BinaryNode('cover_photo', ['op' => 'delete', 'id' => $id]),
            ]),
        ]));
    }

    /** getCatalog(['jid'=>?, 'limit'=>?, 'cursor'=>?]) */
    public function getCatalog(array $options = []): array
    {
        $jid = Jid::normalizedUser($options['jid'] ?? ($this->creds()->me['id'] ?? ''));
        $params = [
            new BinaryNode('limit', [], (string)($options['limit'] ?? 10)),
            new BinaryNode('width', [], '100'),
            new BinaryNode('height', [], '100'),
        ];
        if (!empty($options['cursor'])) {
            $params[] = new BinaryNode('after', [], (string)$options['cursor']);
        }
        $result = $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'type' => 'get',
            'xmlns' => 'w:biz:catalog',
        ], [
            new BinaryNode('product_catalog', ['jid' => $jid, 'allow_shop_source' => 'true'], $params),
        ]));
        $catalogNode = GenericUtils::getBinaryNodeChild($result, 'product_catalog');
        $paging = GenericUtils::getBinaryNodeChild($catalogNode, 'paging');
        return [
            'products' => array_map(fn($n) => self::parseProductNode($n), GenericUtils::getBinaryNodeChildren($catalogNode, 'product')),
            'nextPageCursor' => $paging ? GenericUtils::getBinaryNodeChildString($paging, 'after') : null,
        ];
    }

    /** getCollections(jid?, limit) */
    public function getCollections(?string $jid = null, int $limit = 51): array
    {
        $jid = Jid::normalizedUser($jid ?? ($this->creds()->me['id'] ?? ''));
        $result = $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'type' => 'get',
            'xmlns' => 'w:biz:catalog',
            'smax_id' => '35',
        ], [
            new BinaryNode('collections', ['biz_jid' => $jid], [
                new BinaryNode('collection_limit', [], (string)$limit),
                new BinaryNode('item_limit', [], (string)$limit),
                new BinaryNode('width', [], '100'),
                new BinaryNode('height', [], '100'),
            ]),
        ]));
        $collectionsNode = GenericUtils::getBinaryNodeChild($result, 'collections');
        $collections = [];
        foreach (GenericUtils::getBinaryNodeChildren($collectionsNode, 'collection') as $collectionNode) {
            $collections[] = [
                'id' => GenericUtils::getBinaryNodeChildString($collectionNode, 'id'),
                'name' => GenericUtils::getBinaryNodeChildString($collectionNode, 'name'),
                'products' => array_map(fn($n) => self::parseProductNode($n), GenericUtils::getBinaryNodeChildren($collectionNode, 'product')),
                'status' => self::parseCatalogStatusInfo($collectionNode),
            ];
        }
        return ['collections' => $collections];
    }

    /** getOrderDetails(orderId, tokenBase64) */
    public function getOrderDetails(string $orderId, string $tokenBase64): array
    {
        $result = $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'type' => 'get',
            'xmlns' => 'fb:thrift_iq',
            'smax_id' => '5',
        ], [
            new BinaryNode('order', ['op' => 'get', 'id' => $orderId], [
                new BinaryNode('image_dimensions', [], [
                    new BinaryNode('width', [], '100'),
                    new BinaryNode('height', [], '100'),
                ]),
                new BinaryNode('token', [], $tokenBase64),
            ]),
        ]));
        $orderNode = GenericUtils::getBinaryNodeChild($result, 'order');
        $products = [];
        foreach (GenericUtils::getBinaryNodeChildren($orderNode, 'product') as $productNode) {
            $imageNode = GenericUtils::getBinaryNodeChild($productNode, 'image');
            $products[] = [
                'id' => GenericUtils::getBinaryNodeChildString($productNode, 'id'),
                'name' => GenericUtils::getBinaryNodeChildString($productNode, 'name'),
                'imageUrl' => GenericUtils::getBinaryNodeChildString($imageNode, 'url'),
                'price' => (int)(GenericUtils::getBinaryNodeChildString($productNode, 'price') ?? 0),
                'currency' => GenericUtils::getBinaryNodeChildString($productNode, 'currency'),
                'quantity' => (int)(GenericUtils::getBinaryNodeChildString($productNode, 'quantity') ?? 0),
            ];
        }
        $priceNode = GenericUtils::getBinaryNodeChild($orderNode, 'price');
        return [
            'price' => [
                'total' => (int)(GenericUtils::getBinaryNodeChildString($priceNode, 'total') ?? 0),
                'currency' => GenericUtils::getBinaryNodeChildString($priceNode, 'currency'),
            ],
            'products' => $products,
        ];
    }

    public function productCreate(array $create): array
    {
        $create['isHidden'] = !!($create['isHidden'] ?? false);
        $create = $this->uploadProductImagesIfNeeded($create);
        $result = $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'type' => 'set',
            'xmlns' => 'w:biz:catalog',
        ], [
            new BinaryNode('product_catalog_add', ['v' => '1'], [
                self::toProductNode(null, $create),
                new BinaryNode('width', [], '100'),
                new BinaryNode('height', [], '100'),
            ]),
        ]));
        $productNode = GenericUtils::getBinaryNodeChild(GenericUtils::getBinaryNodeChild($result, 'product_catalog_add'), 'product');
        return self::parseProductNode($productNode);
    }

    public function productUpdate(string $productId, array $update): array
    {
        $update = $this->uploadProductImagesIfNeeded($update);
        $result = $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'type' => 'set',
            'xmlns' => 'w:biz:catalog',
        ], [
            new BinaryNode('product_catalog_edit', ['v' => '1'], [
                self::toProductNode($productId, $update),
                new BinaryNode('width', [], '100'),
                new BinaryNode('height', [], '100'),
            ]),
        ]));
        $productNode = GenericUtils::getBinaryNodeChild(GenericUtils::getBinaryNodeChild($result, 'product_catalog_edit'), 'product');
        return self::parseProductNode($productNode);
    }

    /** @param list<string> $productIds @return array{deleted:int} */
    public function productDelete(array $productIds): array
    {
        $result = $this->query(new BinaryNode('iq', [
            'to' => 's.whatsapp.net',
            'type' => 'set',
            'xmlns' => 'w:biz:catalog',
        ], [
            new BinaryNode('product_catalog_delete', ['v' => '1'], array_map(
                fn($id) => new BinaryNode('product', [], [new BinaryNode('id', [], (string)$id)]),
                $productIds
            )),
        ]));
        $node = GenericUtils::getBinaryNodeChild($result, 'product_catalog_delete');
        return ['deleted' => (int)($node?->attrs['deleted_count'] ?? 0)];
    }

    // ------------------------------------------------------------------ utils
    private function uploadProductImagesIfNeeded(array $product): array
    {
        if (empty($product['images']) || !is_array($product['images'])) {
            return $product;
        }
        $out = [];
        foreach ($product['images'] as $img) {
            if (is_array($img) && isset($img['url']) && str_contains((string)$img['url'], '.whatsapp.net')) {
                $out[] = ['url' => (string)$img['url']];
                continue;
            }
            $bytes = Media::resolveMediaInput($img);
            $shaB64 = base64_encode(hash('sha256', $bytes, true));
            $upload = $this->waUploadToServer($bytes, 'product-catalog-image', $shaB64);
            $out[] = ['url' => Media::getUrlFromDirectPath($upload['directPath'], $this->mediaHost)];
        }
        $product['images'] = $out;
        return $product;
    }

    private static function toProductNode(?string $productId, array $product): BinaryNode
    {
        $attrs = [];
        $content = [];
        if ($productId !== null) {
            $content[] = new BinaryNode('id', [], $productId);
        }
        if (isset($product['name'])) {
            $content[] = new BinaryNode('name', [], (string)$product['name']);
        }
        if (isset($product['description'])) {
            $content[] = new BinaryNode('description', [], (string)$product['description']);
        }
        if (isset($product['retailerId'])) {
            $content[] = new BinaryNode('retailer_id', [], (string)$product['retailerId']);
        }
        if (!empty($product['images'])) {
            $content[] = new BinaryNode('media', [], array_map(function ($img) {
                if (!is_array($img) || !isset($img['url'])) {
                    throw new \InvalidArgumentException('Expected img for product to already be uploaded');
                }
                return new BinaryNode('image', [], [new BinaryNode('url', [], (string)$img['url'])]);
            }, $product['images']));
        }
        if (isset($product['price'])) {
            $content[] = new BinaryNode('price', [], (string)$product['price']);
        }
        if (isset($product['currency'])) {
            $content[] = new BinaryNode('currency', [], (string)$product['currency']);
        }
        if (array_key_exists('originCountryCode', $product)) {
            if ($product['originCountryCode'] === null) {
                $attrs['compliance_category'] = 'COUNTRY_ORIGIN_EXEMPT';
            } else {
                $content[] = new BinaryNode('compliance_info', [], [
                    new BinaryNode('country_code_origin', [], (string)$product['originCountryCode']),
                ]);
            }
        }
        if (isset($product['isHidden'])) {
            $attrs['is_hidden'] = $product['isHidden'] ? 'true' : 'false';
        }
        return new BinaryNode('product', $attrs, $content);
    }

    private static function parseProductNode(?BinaryNode $productNode): array
    {
        if ($productNode === null) {
            throw new \RuntimeException('missing product node in catalog response');
        }
        $mediaNode = GenericUtils::getBinaryNodeChild($productNode, 'media');
        $imgNode = GenericUtils::getBinaryNodeChild($mediaNode, 'image');
        $statusInfoNode = GenericUtils::getBinaryNodeChild($productNode, 'status_info');
        return [
            'id' => GenericUtils::getBinaryNodeChildString($productNode, 'id'),
            'imageUrls' => [
                'requested' => GenericUtils::getBinaryNodeChildString($imgNode, 'request_image_url'),
                'original' => GenericUtils::getBinaryNodeChildString($imgNode, 'original_image_url'),
            ],
            'reviewStatus' => ['whatsapp' => GenericUtils::getBinaryNodeChildString($statusInfoNode, 'status')],
            'availability' => 'in stock',
            'name' => GenericUtils::getBinaryNodeChildString($productNode, 'name'),
            'retailerId' => GenericUtils::getBinaryNodeChildString($productNode, 'retailer_id'),
            'url' => GenericUtils::getBinaryNodeChildString($productNode, 'url'),
            'description' => GenericUtils::getBinaryNodeChildString($productNode, 'description'),
            'price' => (int)(GenericUtils::getBinaryNodeChildString($productNode, 'price') ?? 0),
            'currency' => GenericUtils::getBinaryNodeChildString($productNode, 'currency'),
            'isHidden' => ($productNode->attrs['is_hidden'] ?? '') === 'true',
        ];
    }

    private static function parseCatalogStatusInfo(BinaryNode $node): array
    {
        $statusNode = GenericUtils::getBinaryNodeChild($node, 'status_info');
        return [
            'status' => GenericUtils::getBinaryNodeChildString($statusNode, 'status'),
            'canAppeal' => GenericUtils::getBinaryNodeChildString($statusNode, 'can_appeal') === 'true',
        ];
    }
}
