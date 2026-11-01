// Counters, trackers and chat widgets: never loaded during capture and never put into the copy. The owner sets up his own.
// Patterns are plain strings (the transform runs in the browser, see transform.mjs).

/** Hosts (substrings of host + path) of scripts, pixels and frames that are dropped. [label, pattern] */
export const TRACKER_URLS = [
  ['Яндекс Метрика', 'mc.yandex.'],
  ['Яндекс Метрика', 'yastatic.net/metrika'],
  ['Яндекс Метрика', '/metrika/tag.js'],
  ['Яндекс Метрика', '/metrika/watch.js'],
  ['Яндекс РСЯ', 'an.yandex.ru'],
  ['Яндекс РСЯ', 'yandex.ru/ads'],
  ['Яндекс РСЯ', 'yandexadexchange.net'],
  ['Google Analytics', 'google-analytics.com'],
  ['Google Analytics', 'analytics.google.com'],
  ['Google Tag Manager', 'googletagmanager.com'],
  ['Google Ads', 'googleadservices.com'],
  ['Google Ads', 'googlesyndication.com'],
  ['Google Ads', 'doubleclick.net'],
  ['Google reCAPTCHA', 'google.com/recaptcha'],
  ['Google reCAPTCHA', 'gstatic.com/recaptcha'],
  ['Facebook Pixel', 'connect.facebook.net'],
  ['Facebook Pixel', 'facebook.com/tr'],
  ['VK пиксель', 'vk.com/rtrg'],
  ['VK пиксель', 'vk.com/js/api/openapi'],
  ['VK пиксель', 'vk.ru/rtrg'],
  ['VK пиксель', 'ad.mail.ru'],
  ['Top.Mail.ru', 'top-fwz1.mail.ru'],
  ['Top.Mail.ru', 'top.mail.ru'],
  ['Top.Mail.ru', 'mail.ru/counter'],
  ['LiveInternet', 'counter.yadro.ru'],
  ['LiveInternet', 'liveinternet.ru'],
  ['Tilda statistics', 'stat.tildacdn.'],
  ['Tilda statistics', 'tilda-stat'],
  ['Tilda statistics', 'stat.tildaapi.com'],
  ['Tilda statistics', 'forms.tildaapi.com'],
  ['Tilda statistics', 'forms.tildacdn.com'],
  ['Hotjar', 'hotjar.com'],
  ['Clarity', 'clarity.ms'],
  ['Jivo', 'jivosite.com'],
  ['Jivo', 'jivo.chat'],
  ['Carrot quest', 'carrotquest.io'],
  ['Tawk.to', 'tawk.to'],
  ['LiveChat', 'livechatinc.com'],
  ['Intercom', 'intercom.io'],
  ['Crisp', 'crisp.chat'],
  ['Callibri', 'callibri.ru'],
  ['Calltouch', 'calltouch.net'],
  ['Roistat', 'roistat.com'],
  ['CoMagic', 'comagic.ru'],
  ['UIS (подмена номеров)', 'uiscom.ru'],
  ['UIS (подмена номеров)', 'uiscom.net'],
  ['Mango Office', 'mango-office.ru'],
  ['Replain', 'replain.cc'],
  ['Talk-Me', 'talk-me.ru'],
  ['Chat24', 'chat24.io'],
  ['Битрикс24 виджет', 'bitrix24.ru/b'],
  ['Битрикс24 виджет', 'bitrix24.com/b'],
  ['Битрикс24 виджет', 'bitrix.info/crm'],
  ['Битрикс24 виджет', 'cdn.bitrix24.'],
  ['Wix аналитика', 'frog.wix.com'],
  ['Wix аналитика', 'wix.com/_api/wix-laboratory'],
  ['Wix аналитика', 'wixanswers.com'],
  ['Wix аналитика', 'static.parastorage.com/services/wix-thunderbolt/dist/.*analytics'],
  ['Pinterest', 'pintrk'],
  ['TikTok', 'analytics.tiktok.com'],
  ['Matomo', 'matomo.'],
  ['Segment', 'segment.com/analytics'],
  ['Sentry', 'sentry.io'],
  ['Cloudflare Insights', 'static.cloudflareinsights.com'],
  ['Cloudflare Insights', 'cloudflareinsights.com'],
  ['Cloudflare Insights', 'cdn-cgi/rum'],
  ['Cloudflare Insights', '/cdn-cgi/'],
  ['Кнопки соцсетей VK', 'vk.com/js/api/share'],
  ['Кнопки соцсетей VK', 'vk.com/share.php'],
  ['Кнопки соцсетей VK', 'vk.com/widget'],
  ['Кнопки соцсетей', 'platform.twitter.com'],
  ['Кнопки соцсетей', 'syndication.twitter.com'],
  ['Кнопки соцсетей', 'graph.facebook.com'],
  ['Кнопки соцсетей', 'api.pinterest.com'],
  ['Кнопки соцсетей', 'assets.pinterest.com'],
  ['Кнопки соцсетей', 'connect.ok.ru'],
  ['Кнопки соцсетей', 'platform.linkedin.com'],
  ['Кнопки соцсетей', 'telegram.org/js/telegram-widget'],
  ['ClickTex', 'clicktex.ru'],
  ['2ГИС виджет', 'disk.2gis.com/widget-constructor'],
  ['Yandex Share', 'yastatic.net/share'],
  ['Yandex Share', 'yastatic.net/es5-shims'],
];

/** Fragments of inline scripts that set up a counter or a chat. [label, regexp source] */
export const TRACKER_INLINE = [
  ['Яндекс Метрика', '\\bym\\s*\\(\\s*\\d'],
  ['Яндекс Метрика', 'Ya\\.Metrika'],
  ['Яндекс Метрика', 'mc\\.yandex\\.'],
  ['Яндекс Метрика', 'yandex_metrika_callbacks'],
  ['Google Analytics', '\\bgtag\\s*\\('],
  ['Google Analytics', "\\bga\\s*\\(\\s*['\"]create"],
  ['Google Analytics', 'google-analytics\\.com'],
  ['Google Tag Manager', 'googletagmanager\\.com'],
  ['Google Tag Manager', 'GoogleAnalyticsObject'],
  ['Facebook Pixel', '\\bfbq\\s*\\('],
  ['Facebook Pixel', 'connect\\.facebook\\.net'],
  ['VK пиксель', 'VK\\.Retargeting'],
  ['VK пиксель', 'vk\\.com/rtrg'],
  ['Top.Mail.ru', '_tmr\\b'],
  ['Top.Mail.ru', 'top-fwz1\\.mail\\.ru'],
  ['LiveInternet', 'liveinternet\\.ru'],
  ['Tilda statistics', 'tildastat|tilda-stat|stat\\.tildacdn|window\\.mainTracker'],
  ['Hotjar', '_hjSettings|hotjar'],
  ['Clarity', "clarity\\s*\\(|clarity\\.ms"],
  ['Jivo', 'jivo_|jivosite'],
  ['Carrot quest', 'carrotquest'],
  ['Tawk.to', 'Tawk_API|tawk\\.to'],
  ['Callibri', 'callibri'],
  ['Calltouch', 'calltouch|ct\\(\\s*[\'"]'],
  ['Roistat', 'roistat'],
  ['CoMagic', 'comagic'],
  ['Битрикс24 виджет', 'bitrix24|\\bb24\\b.*widget'],
  ['Cloudflare Insights', 'cloudflareinsights|__cfBeacon'],
  ['Wix аналитика', 'frog\\.wix\\.com|wixBiSession|bi-logger'],
];

/** Hosts whose frames are kept (embeds that cannot be carried over). Must match ALLOWED_FRAMES in the PHP side. */
export const ALLOWED_FRAME_HOSTS = [
  'www.youtube.com', 'www.youtube-nocookie.com', 'youtube.com', 'player.vimeo.com', 'rutube.ru', 'vk.com', 'vk.ru', 'vkvideo.ru',
  'yandex.ru', 'yandex.com', 'www.google.com', 'www.openstreetmap.org', 'widgets.2gis.com', 'dzen.ru',
];

/** Frame addresses (host + path prefix) that are allowed; everything else on those hosts is dropped. */
export const ALLOWED_FRAME_PREFIXES = [
  'www.youtube.com/embed/', 'youtube.com/embed/', 'www.youtube-nocookie.com/embed/', 'player.vimeo.com/video/', 'rutube.ru/play/embed/',
  'vk.com/video_ext.php', 'vk.ru/video_ext.php', 'vkvideo.ru/video_ext.php', 'yandex.ru/map-widget/', 'yandex.com/map-widget/',
  'www.google.com/maps/embed', 'www.openstreetmap.org/export/embed.html', 'widgets.2gis.com/widget', 'dzen.ru/embed/',
];

/** Requests refused during capture so that the counters of the source site do not count the capture as visits. */
export function isTrackerUrl(url) {
  const u = url.toLowerCase();
  for (const [, p] of TRACKER_URLS) {
    if (p.includes('.*')) { if (new RegExp(p).test(u)) return true; } else if (u.includes(p)) return true;
  }
  return false;
}

export function trackerLabel(url) {
  const u = url.toLowerCase();
  for (const [label, p] of TRACKER_URLS) {
    if (p.includes('.*') ? new RegExp(p).test(u) : u.includes(p)) return label;
  }
  return '';
}
