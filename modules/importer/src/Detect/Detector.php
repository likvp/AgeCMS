<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Detect;

use AgeCMS\Modules\Importer\Net\Response;

/**
 * Guesses the CMS of a site from its home page: meta generator, typical paths and class names, headers and cookies.
 * Each sign has a weight; the weights of one platform combine as independent evidence (1 - product of (1 - w)).
 */
final class Detector
{
    public const NAMES = [
        'wordpress' => 'WordPress', 'tilda' => 'Tilda', 'bitrix' => '1С-Битрикс', 'joomla' => 'Joomla', 'wix' => 'Wix', 'modx' => 'MODX',
        'hostcms' => 'HostCMS', 'webflow' => 'Webflow', 'opencart' => 'OpenCart', 'drupal' => 'Drupal', 'umi' => 'UMI.CMS',
        'shopify' => 'Shopify', 'squarespace' => 'Squarespace', 'nethouse' => 'Nethouse', 'ucoz' => 'uCoz', 'insales' => 'InSales',
        'readymag' => 'Readymag', 'netcat' => 'NetCat', 'generic' => 'Обычный HTML',
    ];

    /**
     * Signs: platform => list of [where, regex, weight, evidence text]. where is "html", "generator" (meta generator
     * content), "header:<name>" or "cookie".
     */
    private const SIGNS = [
        'wordpress' => [
            ['generator', '~wordpress~i', 0.95, 'meta generator «%s»'],
            ['html', '~/wp-content/~i', 0.7, 'адреса /wp-content/'],
            ['html', '~/wp-includes/~i', 0.6, 'адреса /wp-includes/'],
            ['html', '~/wp-json/|api\.w\.org~i', 0.6, 'ссылка на /wp-json/'],
            ['header:link', '~api\.w\.org|wp-json~i', 0.9, 'заголовок Link с /wp-json/'],
            ['html', '~class="[^"]*\bwp-block-~i', 0.4, 'классы блоков Gutenberg (wp-block-)'],
            ['html', '~data-elementor-type~i', 0.5, 'разметка Elementor'],
        ],
        'tilda' => [
            ['generator', '~tilda~i', 0.95, 'meta generator «%s»'],
            ['html', '~tildacdn\.(com|pro|net)~i', 0.8, 'файлы с tildacdn'],
            ['html', '~data-tilda-page-id|data-tilda-project-id~i', 0.9, 'атрибут data-tilda-page-id'],
            ['html', '~class="[^"]*\bt-records\b~i', 0.7, 'контейнер t-records'],
            ['html', '~class="[^"]*\bt-rec\b[^"]*"[^>]*data-record-type|data-record-type="\d+"~i', 0.6, 'блоки t-rec с data-record-type'],
        ],
        'bitrix' => [
            ['generator', '~bitrix~i', 0.95, 'meta generator «%s»'],
            ['html', '~/bitrix/(templates|js|cache|components|panel)/~i', 0.75, 'адреса /bitrix/'],
            ['html', '~\bBX\.(setCSSList|setJSList|message|ready)|\bBX\s*=|bxSession~', 0.6, 'скрипты BX.'],
            ['cookie', '~BITRIX_SM_|BX_USER_ID~i', 0.9, 'cookie BITRIX_SM'],
            ['header:x-powered-cms', '~bitrix~i', 0.95, 'заголовок X-Powered-CMS'],
            ['header:x-powered-by', '~bitrix~i', 0.9, 'заголовок X-Powered-By'],
            ['header:x-bitrix-composite', '~.~', 0.95, 'заголовок X-Bitrix-Composite'],
            ['html', '~["\'(=]/upload/(resize_cache|iblock|medialibrary)/~i', 0.7, 'адреса /upload/iblock/'],
            ['html', '~["\'(=]/local/(templates|components)/~i', 0.5, 'адреса /local/templates/'],
        ],
        'joomla' => [
            ['generator', '~joomla~i', 0.95, 'meta generator «%s»'],
            ['html', '~/media/jui/~i', 0.7, 'адреса /media/jui/'],
            ['html', '~/media/system/js/~i', 0.5, 'адреса /media/system/js/'],
            ['html', '~joomla-script-options|Joomla\.~', 0.7, 'скрипты Joomla'],
            ['html', '~/components/com_|option=com_~i', 0.5, 'компоненты com_'],
            ['html', '~/media/(vendor/joomla|templates/site/|com_[a-z]+/)~i', 0.6, 'адреса /media/ Joomla 4'],
        ],
        'wix' => [
            ['generator', '~wix\.com~i', 0.95, 'meta generator «%s»'],
            ['html', '~wixstatic\.com|parastorage\.com~i', 0.8, 'файлы wixstatic'],
            ['header:x-wix-request-id', '~.~', 0.95, 'заголовок X-Wix-Request-Id'],
        ],
        'modx' => [
            ['generator', '~modx~i', 0.95, 'meta generator «%s»'],
            ['html', '~(?<![a-z0-9_.-])assets/components/~i', 0.5, 'адреса /assets/components/'],
            ['html', '~modx~i', 0.4, 'слово MODX в коде страницы'],
            ['header:x-powered-by', '~modx~i', 0.95, 'заголовок X-Powered-By'],
            ['html', '~(?<![a-z0-9_.-])assets/(templates|template|tpl)/~i', 0.3, 'адреса /assets/templates/'],
            ['html', '~(?<![a-z0-9_.-])assets/components/(ajaxform|minishop2|pdotools|minifyx|formit|tickets|msearch2|mspromocode|office|siteextra|babel|migx|easycomm|phpthumbon|phpthumbof|modxsite|ms2gallery|simpleab|fastuploadtv)/~i', 0.75, 'компоненты MODX в /assets/components/'],
            ['html', '~(?<![a-z0-9_.-])assets/(snippets|plugins|modules)/~i', 0.6, 'адреса /assets/snippets/ (Evolution)'],
            ['html', '~(?<![a-z0-9_.-])assets/cache/images/~i', 0.4, 'адреса /assets/cache/images/'],
            ['cookie', '~(^|[\s;,])evo[a-z0-9]{6,}=~im', 0.7, 'cookie сессии Evolution CMS'],
        ],
        'hostcms' => [
            ['generator', '~hostcms~i', 0.95, 'meta generator «%s»'],
            ['html', '~/hostcmsfiles/~i', 0.85, 'адреса /hostcmsfiles/'],
            ['header:x-powered-by', '~hostcms~i', 0.95, 'заголовок X-Powered-By'],
            ['html', '~["\'(=]/upload/(information_system|shop|shop_group|structure)_\d+/~i', 0.8, 'адреса /upload/information_system_N/'],
        ],
        'webflow' => [
            ['generator', '~webflow~i', 0.95, 'meta generator «%s»'],
            ['html', '~data-wf-page|data-wf-site~i', 0.9, 'атрибут data-wf-page'],
            ['html', '~website-files\.com|webflow\.(js|css)~i', 0.6, 'файлы Webflow'],
        ],
        'opencart' => [
            ['generator', '~opencart~i', 0.95, 'meta generator «%s»'],
            ['html', '~catalog/view/(theme|javascript)/~i', 0.75, 'адреса catalog/view/theme'],
            ['html', '~index\.php\?route=(common|product|information)/~i', 0.6, 'адреса index.php?route='],
        ],
        'drupal' => [
            ['generator', '~drupal~i', 0.95, 'meta generator «%s»'],
            ['header:x-generator', '~drupal~i', 0.95, 'заголовок X-Generator'],
            ['html', '~drupal-settings-json|Drupal\.settings~', 0.8, 'настройки Drupal в коде'],
            ['html', '~/sites/(default|all)/(files|themes|modules)/~i', 0.6, 'адреса /sites/default/'],
        ],
        'umi' => [
            ['generator', '~umi\.?cms~i', 0.95, 'meta generator «%s»'],
            ['html', '~/templates/[a-z0-9_]+/(js|css)/.*umi|umiru|data-umi-~i', 0.6, 'разметка UMI'],
            ['header:x-generated-by', '~umi~i', 0.95, 'заголовок X-Generated-By'],
        ],
        'shopify' => [
            ['html', '~cdn\.shopify\.com|Shopify\.theme~i', 0.9, 'файлы cdn.shopify.com'],
        ],
        'squarespace' => [
            ['html', '~static1\.squarespace\.com|squarespace-cdn~i', 0.9, 'файлы squarespace'],
        ],
        'nethouse' => [
            ['html', '~nethouse\.ru|nethouse-static~i', 0.8, 'файлы nethouse'],
        ],
        'ucoz' => [
            ['html', '~ucoz\.(ru|net)|/\.s/src/~i', 0.8, 'файлы uCoz'],
        ],
        'insales' => [
            ['html', '~insales\.ru|static\.insales~i', 0.8, 'файлы InSales'],
        ],
        'readymag' => [
            ['html', '~readymag\.(com|website)~i', 0.85, 'файлы Readymag'],
        ],
        'netcat' => [
            ['generator', '~netcat~i', 0.95, 'meta generator «%s»'],
            ['html', '~/netcat(_files|_template)?/~i', 0.8, 'адреса /netcat/'],
        ],
    ];

    /**
     * @return array{platform:string,name:string,confidence:float,evidence:list<string>,scores:array<string,float>}
     */
    public static function detect(Response $home): array
    {
        $html = $home->text();
        // Widgets of hosted services carry the paths of their own platform (the Bitrix24 form and chat button load
        // /bitrix/js/crm/ from *.bitrix24.ru): they say nothing about the CMS of the site.
        $widgets = [];
        $stripped = preg_replace('~<script\b[^>]*>(?:(?!</script>).)*?bitrix24\.(?:(?!</script>).)*?</script>~is', '', $html) ?? $html;
        $stripped = preg_replace('~(https?:)?//[a-z0-9.-]*bitrix24\.[a-z.]+/[^\s"\'<>)]*~i', '', $stripped) ?? $stripped;
        if ($stripped !== $html) {
            $widgets[] = 'виджет Битрикс24 (не признак CMS сайта)';
            $html = $stripped;
        }
        $generator = '';
        if (preg_match('~<meta[^>]+name=["\']generator["\'][^>]*content=["\']([^"\']+)~i', $html, $m) === 1
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]*name=["\']generator["\']~i', $html, $m) === 1) {
            $generator = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        $cookies = $home->header('set-cookie');
        $scores = [];
        $evidence = [];
        foreach (self::SIGNS as $platform => $signs) {
            $miss = 1.0;
            foreach ($signs as [$where, $regex, $weight, $text]) {
                $subject = match (true) {
                    $where === 'html' => $html,
                    $where === 'generator' => $generator,
                    $where === 'cookie' => $cookies,
                    str_starts_with($where, 'header:') => $home->header(substr($where, 7)),
                    default => '',
                };
                if ($subject !== '' && preg_match($regex, $subject) === 1) {
                    $miss *= 1 - $weight;
                    $evidence[$platform][] = sprintf($text, mb_substr($generator, 0, 60));
                }
            }
            if ($miss < 1.0) {
                $scores[$platform] = round(1 - $miss, 3);
            }
        }
        arsort($scores);
        $platform = array_key_first($scores);
        if ($platform !== null && $widgets !== []) {
            $evidence[$platform] = array_merge($evidence[$platform] ?? [], $widgets);
        }
        if ($platform === null || $scores[$platform] < 0.5) {
            return [
                'platform' => 'generic',
                'name' => self::NAMES['generic'],
                'confidence' => $platform === null ? 0.5 : round(1 - $scores[$platform], 2),
                'evidence' => $platform === null ? ['признаков известных CMS не найдено'] : ['слабые признаки ' . (self::NAMES[$platform] ?? $platform) . ': ' . implode(', ', $evidence[$platform] ?? [])],
                'scores' => $scores,
            ];
        }
        return [
            'platform' => $platform,
            'name' => self::NAMES[$platform] ?? $platform,
            'confidence' => round($scores[$platform], 2),
            'evidence' => $evidence[$platform] ?? [],
            'scores' => $scores,
        ];
    }

    public static function name(string $platform): string
    {
        return self::NAMES[$platform] ?? $platform;
    }
}
