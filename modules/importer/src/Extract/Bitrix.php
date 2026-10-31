<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Extract;

use AgeCMS\Modules\Importer\Html\Dom;

/**
 * 1C-Bitrix sites: the work area of the template (#workarea, .workarea, bx-* containers, .news-detail), the
 * breadcrumb component for the hierarchy, news (/news/, /novosti/, /press/, also one level down: /about/news/) as posts
 * with their date (machine-readable or as the template shows it: "16 сентября 2013").
 * Crawling itself is done by Generic; Bitrix has no open API for content.
 */
final class Bitrix
{
    public static function profile(): Profile
    {
        $c = static fn(string $class): string => '//*[' . Dom::cls($class) . ']';
        return new Profile(
            'bitrix',
            [
                $c('news-detail'), "//*[@id='workarea']", $c('workarea'), $c('bx-content'), $c('page-content'), "//*[@id='content']",
                $c('content-inner'), $c('main-content'), $c('content'), "//main", '//article',
            ],
            [
                "//*[@id='bx-panel']", "//*[@id='panel']", $c('bx-breadcrumb'), $c('bx-pagination'), $c('modern-page-navigation'),
                $c('news-list'), $c('bx-newslist'), $c('news-date-time'), $c('bx-newsdetail-date'), $c('search-page'), $c('bx-authform'), $c('bx_catalog_list_home'),
            ],
            ["//*[contains(@class,'bx-breadcrumb')]", "//*[@itemtype='http://schema.org/BreadcrumbList' or @itemtype='https://schema.org/BreadcrumbList']", "//*[contains(@class,'breadcrumb')]"],
            [
                "//meta[@property='article:published_time']/@content", "//*[" . Dom::cls('news-date-time') . "]", "//*[" . Dom::cls('bx-newsdetail-date') . "]",
                "//*[contains(@class,'news-detail')]//*[contains(@class,'date')]", "//time[@datetime]/@datetime",
                // Templates of studios: the visible date of the item is more often right than datePublished (the date it was entered).
                "//*[" . Dom::cls('date-container') . " or " . Dom::cls('news-date') . " or " . Dom::cls('news-item-date') . " or " . Dom::cls('article-date') . " or " . Dom::cls('detail-date') . " or " . Dom::cls('period') . "]",
                "//*[@itemprop='datePublished']/@content",
            ],
            ['~^/(news|novosti|press|press-center|press-tsentr|articles|stati|blog|events|sobytiya|aktsii|akcii|actions|publications)/.+~u', '~^/[^/]+/(news|novosti|press|articles|stati|blog|sobytiya|publikatsii)/.+~u'],
        );
    }

    /**
     * News pages get the date from the detail template when there was no machine-readable one.
     *
     * @param array<string,mixed> $page
     * @return array<string,mixed>
     */
    public static function refine(array $page, string $html): array
    {
        if ($page['kind'] === 'post' && $page['published_at'] === '' && preg_match('~class="[^"]*(news-date-time|bx-newsdetail-date|date-container|news-date)[^"]*"[^>]*>\s*([^<]+)~i', $html, $m) === 1) {
            $page['published_at'] = HtmlPage::date(trim($m[2]));
        }
        return $page;
    }
}
