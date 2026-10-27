<?php
declare(strict_types=1);

namespace AgeCMS;

/** Builds a page address from its title: Cyrillic is transliterated, everything else becomes hyphens. */
final class Slug
{
    private const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y',
        'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f',
        'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    /** "Ремонт квартир под ключ" becomes "remont-kvartir-pod-klyuch". Empty when the title has no usable letters. */
    public static function fromTitle(string $title, int $max = 60): string
    {
        $s = strtr(mb_strtolower(trim($title)), self::MAP);
        $s = preg_replace('~[^a-z0-9]+~', '-', $s) ?? '';
        $s = trim($s, '-');
        if (strlen($s) > $max) {
            $s = substr($s, 0, $max);
            $cut = strrpos($s, '-');
            $s = trim($cut !== false && $cut > 20 ? substr($s, 0, $cut) : $s, '-');
        }
        return $s;
    }

    /** A free address: the title's slug, then -2, -3 when taken. Never reuses the address of a deleted page. */
    public static function free(string $title, string $prefix = ''): string
    {
        $base = self::fromTitle($title);
        if ($base === '') {
            $base = 'page';
        }
        $prefix = trim($prefix, '/');
        $base = ($prefix !== '' && $prefix !== 'index' ? $prefix . '/' : '') . $base;
        for ($i = 1; $i < 1000; $i++) {
            $slug = $i === 1 ? $base : $base . '-' . $i;
            if (Pages::slugValid($slug) && Pages::row($slug) === null && ModuleRoutes::claimedBy($slug) === null && Database::row('SELECT 1 FROM revisions WHERE page_slug = ? LIMIT 1', [$slug]) === null) {
                return $slug;
            }
        }
        return $base . '-' . bin2hex(random_bytes(3));
    }
}
