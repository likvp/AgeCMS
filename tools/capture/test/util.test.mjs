import test from 'node:test';
import assert from 'node:assert/strict';
import { localName, pageKey, oldPath, parseRobots } from '../lib/util.mjs';
import { isTrackerUrl, trackerLabel } from '../lib/trackers.mjs';

test('names of files never look like server scripts', () => {
  const n = localName('https://example.com/img/webpc-passthru.php?src=a', 'image/webp', new Set());
  assert.ok(!/\.php(\.|$)/i.test(n), n);
  assert.match(n, /^[A-Za-z0-9._~/-]+$/);
});

test('a page key drops tracking parameters and the index file', () => {
  assert.equal(pageKey('https://www.example.com/about/index.html?utm_source=x#top'), pageKey('https://example.com/about/index.html'));
  assert.equal(oldPath('https://example.com/a?b=1&utm_medium=z'), '/a?b=1');
});

test('counters and social widgets are recognised', () => {
  assert.ok(isTrackerUrl('https://mc.yandex.ru/metrika/tag.js'));
  assert.ok(isTrackerUrl('https://www.googletagmanager.com/gtag/js?id=G-1'));
  assert.equal(trackerLabel('https://vk.com/js/api/share.js'), 'Кнопки соцсетей VK');
  assert.ok(!isTrackerUrl('https://example.com/js/app.js'));
});

test('robots.txt: the longest rule wins', () => {
  const r = parseRobots('User-agent: *\nDisallow: /private\nAllow: /private/open\nSitemap: https://e.com/s.xml');
  assert.deepEqual(r.sitemaps, ['https://e.com/s.xml']);
});
