<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2025 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);


namespace App\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PriceDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AliexpressProvider implements InfoProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
    ) {
    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'Aliexpress',
            'description' => 'Web scraping from aliexpress.com to get part information.',
            'url' => 'https://aliexpress.com/',
            'disabled_help' => 'Enable this provider in the Part-DB configuration.',
        ];
    }

    public function getProviderKey(): string
    {
        return 'aliexpress';
    }

    public function isActive(): bool
    {
        // Always active; you can later wire this to settings / env if desired.
        return true;
    }

    private function getBaseURL(): string
    {
        // Without the trailing slash
        return 'https://de.aliexpress.com';
    }

    /**
     * @return SearchResultDTO[]
     */
    public function searchByKeyword(string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        // AliExpress uses SEO search URLs, but the "old" wholesale endpoint mit Queryparametern
        $response = $this->client->request('GET', $this->getBaseURL() . '/wholesale', [
            'query' => [
                'SearchText' => $keyword,
                'CatId' => 0,
                'd' => 'y',
            ],
            'headers' => [
                'User-Agent' => 'Part-DB-AliexpressProvider/1.0',
                'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
            ],
        ]);

        $content = $response->getContent();
        $dom = new Crawler($content, $this->getBaseURL() . '/wholesale');

        $results = [];

        // Each result card: <div class="hr_bm search-item-card-wrapper-gallery"> ... </div>
        $dom->filter('div.search-item-card-wrapper-gallery')->each(
            function (Crawler $node) use (&$results): void {
                // Link to product or SSR bundle
                $linkNode = $node->filter('a.search-card-item')->first();
                if ($linkNode->count() === 0) {
                    return;
                }

                $href = $linkNode->attr('href');
                if ($href === null || $href === '') {
                    return;
                }

                // Make URL absolute and strip query string (for display)
                $productURL = $this->cleanProductURL($href);

                // Try to get a numeric product ID (for /item/{id}.html later)
                $productID = $this->extractProductID($href);
                if ($productID === null) {
                    // If we cannot extract a stable ID, skip this result
                    return;
                }

                // Title: AliExpress cards often use <h1> / <h2> / <h3> or div[title]
                $name = null;
                if ($node->filter('div[title]')->count() > 0) {
                    $name = trim($node->filter('div[title]')->first()->attr('title') ?? '');
                } elseif ($node->filter('h1, h2, h3')->count() > 0) {
                    $name = trim($node->filter('h1, h2, h3')->first()->text(''));
                }

                if ($name === null || $name === '') {
                    // No usable title -> skip
                    return;
                }

                // Preview image
                $imgUrl = null;
                if ($node->filter('img.product-img, img')->count() > 0) {
                    $imgUrl = $node->filter('img.product-img, img')->first()->attr('src') ?? null;
                }

                if ($imgUrl !== null && str_starts_with($imgUrl, '//')) {
                    $imgUrl = 'https:' . $imgUrl;
                }

                $results[] = new SearchResultDTO(
                    provider_key: $this->getProviderKey(),
                    provider_id: $productID,
                    name: $name,
                    description: '',
                    category: null,
                    manufacturer: null,
                    mpn: null,
                    preview_image_url: $imgUrl,
                    manufacturing_status: null,
                    provider_url: $productURL,
                    footprint: null,
                );
            }
        );

        return $results;
    }

    private function cleanProductURL(string $url): string
    {
        // Make relative URLs absolute
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } elseif (str_starts_with($url, '/')) {
            $url = rtrim($this->getBaseURL(), '/') . $url;
        }

        // Strip query string for nicer display, keep base path
        $parts = explode('?', $url, 2);

        return $parts[0];
    }

    /**
     * Extracts a numeric AliExpress product ID from various URL formats.
     *
     * Supported patterns:
     *  - /item/1005006063706718.html
     *  - /ssr/...BundleDeals2?productIds=1005006063706718:12000036624981621&...
     */
    private function extractProductID(string $url): ?string
    {
        // 1) Classic /item/{id}.html
        if (preg_match('/\/(\d+)\.html/', $url, $matches) === 1) {
            return $matches[1];
        }

        // 2) SSR URLs: productIds=1005006063706718:12000036624981621,...
        $parts = parse_url($url);
        if (!isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);
        if (empty($query['productIds'])) {
            return null;
        }

        // productIds can be a comma-separated list, each entry possibly with ":"
        // Take first non-empty numeric part
        $productIds = explode(',', (string) $query['productIds']);
        foreach ($productIds as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            // E.g. "1005006063706718:12000036624981621"
            $firstPart = explode(':', $entry)[0];
            if (ctype_digit($firstPart)) {
                return $firstPart;
            }
        }

        return null;
    }

    public function getDetails(string $id): PartDetailDTO
    {
        if (!ctype_digit($id)) {
            throw new \InvalidArgumentException('The id must be numeric.');
        }

        $productUrl = $this->getBaseURL() . '/item/' . $id . '.html';

        $response = $this->client->request('GET', $productUrl, [
            'headers' => [
                'User-Agent' => 'Part-DB-AliexpressProvider/1.0',
                'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
            ],
        ]);

        $html = $response->getContent();
        $crawler = new Crawler($html, $productUrl);

        // --- Name / Title ----------------------------------------------------
        $name = $this->firstAttr($crawler, 'meta[property="og:title"]', 'content');
        if ($name === null && $crawler->filter('h1')->count() > 0) {
            $name = trim($crawler->filter('h1')->first()->text(''));
        }
        if ($name === null || $name === '') {
            $name = $id;
        }

        // --- Short description ----------------------------------------------
        $shortDescription = $this->firstAttr($crawler, 'meta[property="og:description"]', 'content');

        // --- Long description ("notes") -------------------------------------
        $notesHtml = null;
        if ($crawler->filter('#product-description')->count() > 0) {
            try {
                $notesHtml = $crawler->filter('#product-description')->html();
                // Strip <script> tags to avoid weird output
                $notesHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $notesHtml ?? '') ?: null;
            } catch (\Throwable) {
                // If html() fails (malformed DOM), we simply ignore notes
                $notesHtml = null;
            }
        }

        // --- Image -----------------------------------------------------------
        $imageUrl = $this->firstAttr($crawler, 'meta[property="og:image"]', 'content');
        if ($imageUrl !== null && str_starts_with($imageUrl, '//')) {
            $imageUrl = 'https:' . $imageUrl;
        }

        // --- Price & Vendor info --------------------------------------------
        $priceValue = $this->firstAttr($crawler, 'meta[property="og:price:amount"]', 'content');
        $currency = $this->firstAttr($crawler, 'meta[property="og:price:currency"]', 'content') ?? 'USD';

        $prices = [];
        if ($priceValue !== null && $priceValue !== '') {
            // Normalize decimal separators
            $normalized = str_replace(',', '.', $priceValue);

            if (!is_numeric($normalized)) {
                if (preg_match('/([\d.,]+)/', $priceValue, $m) === 1) {
                    $normalized = str_replace(',', '.', $m[1]);
                }
            }

            $prices[] = new PriceDTO(
                minimum_discount_amount: 1,
                price: (string) $normalized,
                currency_iso_code: $currency,
                includes_tax: false
            );
        }

        $vendorInfos = [];
        if ($prices !== []) {
            $vendorInfos[] = new PurchaseInfoDTO(
                distributor_name: 'Aliexpress',
                order_number: $id,
                prices: $prices,
                product_url: $productUrl
            );
        }

        return new PartDetailDTO(
            provider_key: $this->getProviderKey(),
            provider_id: $id,
            name: $name,
            description: $shortDescription ?? '',
            preview_image_url: $imageUrl,
            provider_url: $productUrl,
            notes: $notesHtml ?? $shortDescription,
            vendor_infos: $vendorInfos
        );
    }

    /**
     * Helper to read the first matching attribute from a selector.
     */
    private function firstAttr(Crawler $crawler, string $selector, string $attribute): ?string
    {
        if ($crawler->filter($selector)->count() === 0) {
            return null;
        }

        $value = $crawler->filter($selector)->first()->attr($attribute);

        return $value !== null && $value !== '' ? $value : null;
    }

    public function getCapabilities(): array
    {
        return [
            ProviderCapabilities::BASIC,
            ProviderCapabilities::PICTURE,
            ProviderCapabilities::PRICE,
        ];
    }
}