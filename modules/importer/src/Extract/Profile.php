<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Extract;

use AgeCMS\Modules\Importer\Html\Dom;

/**
 * Where a CMS keeps the main content of a page, and what else to know about its markup: containers to try first,
 * extra junk, breadcrumbs, the publication date and which addresses are news or blog posts.
 */
final class Profile
{
    /**
     * @param list<string> $content XPath of content containers, best first
     * @param list<string> $junk XPath of elements to drop besides the common ones
     * @param list<string> $breadcrumbs XPath of breadcrumb containers
     * @param list<string> $date XPath of an element with the date (datetime/content attribute or text)
     * @param list<string> $postPaths regexes of paths that are posts (news, articles)
     */
    public function __construct(
        public readonly string $platform,
        public readonly array $content,
        public readonly array $junk = [],
        public readonly array $breadcrumbs = [],
        public readonly array $date = [],
        public readonly array $postPaths = [],
    ) {
    }

    private const POSTS = ['~^/(blog|news|novosti|stati|articles|article|press|press-center|press-tsentr|sobytiya|events|publikatsii|publications|journal|zhurnal|poleznoe|useful)/.+~u', '~^/[^/]+/(news|novosti|blog|stati|articles)/.+~u', '~^/\d{4}/\d{2}/~'];
    private const BREADCRUMBS = ["//*[contains(@class,'breadcrumb')]", "//*[contains(@itemtype,'BreadcrumbList')]", "//*[contains(@class,'bx-breadcrumb')]"];
    private const DATES = ["//meta[@property='article:published_time']/@content", "//time[@datetime]/@datetime", "//*[@itemprop='datePublished']/@content", "//*[@itemprop='datePublished']"];

    public static function for(string $platform): self
    {
        $c = static fn(string $class): string => '//*[' . Dom::cls($class) . ']';
        return match ($platform) {
            'wordpress' => new self('wordpress', [
                "//div[@data-elementor-type='wp-page' or @data-elementor-type='wp-post' or @data-elementor-type='single-page' or @data-elementor-type='single-post']",
                $c('entry-content'), $c('post-content'), $c('page-content'), $c('et_builder_inner_content'), $c('wpb-content-wrapper'), $c('td-post-content'), '//article', '//main',
            ], [$c('sharedaddy'), $c('jp-relatedposts'), $c('post-navigation'), $c('entry-meta'), $c('entry-footer'), "//*[@id='comments']"], self::BREADCRUMBS, self::DATES, self::POSTS),
            'bitrix' => Bitrix::profile(),
            'joomla' => new self('joomla', [
                "//*[@itemprop='articleBody']", $c('com-content-article__body'), $c('item-page'), $c('blog'), "//*[@id='content']", "//main", $c('content'),
            ], [$c('pagenav'), $c('article-info'), $c('icons'), "//*[@id='system-message-container']"], self::BREADCRUMBS, array_merge(self::DATES, ["//*[" . Dom::cls('published') . "]//time/@datetime"]), self::POSTS),
            'modx' => new self('modx', ["//*[@id='content']", $c('content'), $c('main-content'), "//main", '//article'], [], self::BREADCRUMBS, self::DATES, self::POSTS),
            'hostcms' => new self('hostcms', [$c('content'), "//*[@id='content']", $c('center-column'), $c('main-content'), "//main", '//article'], [$c('hostcms_informationsystem_items_pagination')], self::BREADCRUMBS, self::DATES, self::POSTS),
            'wix' => new self('wix', ["//*[@id='PAGES_CONTAINER']", "//main"], ["//*[@id='SITE_HEADER']", "//*[@id='SITE_FOOTER']", "//*[@id='WIX_ADS']", "//*[@id='SCROLL_TO_TOP']", "//*[@id='SCROLL_TO_BOTTOM']", "//*[@id='SKIP_TO_CONTENT_BTN']"], [], self::DATES, ['~/(post|single-post)/.+~']),
            'webflow' => new self('webflow', [$c('w-richtext'), "//main", '//body'], [$c('w-nav'), $c('w-webflow-badge')], [], self::DATES, ['~^/(blog|post|posts|news)/.+~']),
            'opencart' => new self('opencart', ["//*[@id='information-information']//*[@id='content']", "//*[@id='product-product']//*[@id='content']", "//*[@id='content']"], [$c('breadcrumb'), $c('product-layout')], self::BREADCRUMBS, self::DATES, []),
            'drupal' => new self('drupal', [$c('field--name-body'), $c('node__content'), "//main", "//*[@id='content']"], [$c('node__links'), $c('tabs')], self::BREADCRUMBS, self::DATES, self::POSTS),
            'umi' => new self('umi', ["//*[@id='content']", $c('content'), "//main"], [], self::BREADCRUMBS, self::DATES, self::POSTS),
            default => new self($platform === '' ? 'generic' : $platform, [], [], self::BREADCRUMBS, self::DATES, self::POSTS),
        };
    }

    public function isPostPath(string $path): bool
    {
        foreach ($this->postPaths as $re) {
            if (preg_match($re, mb_strtolower($path)) === 1) {
                return true;
            }
        }
        return false;
    }
}
