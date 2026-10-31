<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Net;

/** robots.txt: the rules for "*" (Allow/Disallow, longest match wins, * and $ wildcards) and the Sitemap lines. */
final class Robots
{
    /** @param list<array{allow:bool,rule:string}> $rules @param list<string> $sitemaps */
    public function __construct(public readonly array $rules = [], public readonly array $sitemaps = [])
    {
    }

    public static function parse(string $text): self
    {
        $rules = [];
        $sitemaps = [];
        $groupAgents = [];
        $inRules = false;
        $forUs = false;
        foreach (preg_split('~\r?\n~', $text) ?: [] as $line) {
            $line = trim(preg_replace('~#.*$~', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ($key === 'sitemap') {
                $sitemaps[] = $value;
                continue;
            }
            if ($key === 'user-agent') {
                if ($inRules) {
                    $groupAgents = [];
                    $inRules = false;
                }
                $groupAgents[] = strtolower($value);
                $forUs = in_array('*', $groupAgents, true) || in_array('agecms-importer', $groupAgents, true);
                continue;
            }
            if ($key === 'allow' || $key === 'disallow') {
                $inRules = true;
                if ($forUs && $value !== '') {
                    $rules[] = ['allow' => $key === 'allow', 'rule' => $value];
                }
            }
        }
        return new self($rules, array_values(array_unique($sitemaps)));
    }

    /** $path is the path with the query, as in a URL. */
    public function allowed(string $path): bool
    {
        $best = null;
        $bestLength = -1;
        foreach ($this->rules as $r) {
            $regex = '~^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($r['rule'], '~')) . '~';
            if (preg_match($regex, $path) === 1) {
                $len = strlen($r['rule']);
                if ($len > $bestLength || ($len === $bestLength && $r['allow'])) {
                    $best = $r['allow'];
                    $bestLength = $len;
                }
            }
        }
        return $best ?? true;
    }
}
