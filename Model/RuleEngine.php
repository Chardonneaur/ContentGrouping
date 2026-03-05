<?php

namespace Piwik\Plugins\ContentGrouping\Model;

use Piwik\Log\LoggerInterface;
use Piwik\Container\StaticContainer;
use Piwik\Piwik;

class RuleEngine
{
    private const SAFE_BACKTRACK_LIMIT = 100000;

    /**
     * Evaluate a URL against a list of rules and return the matching group name.
     *
     * Rules must already be sorted by priority (ascending).
     * First matching rule wins.
     *
     * @param string $url The page URL to evaluate
     * @param array $rules Array of rule rows from RulesDao
     * @return string The group name, or translated "(not set)" if no rule matches
     */
    public function evaluateUrl($url, array $rules)
    {
        foreach ($rules as $rule) {
            if ($this->matchesRule($url, $rule)) {
                return $rule['group_name'];
            }
        }

        return self::getOthersGroupLabel();
    }

    public static function getOthersGroupLabel(): string
    {
        return Piwik::translate('General_Others');
    }

    public function matchesRule($url, array $rule): bool
    {
        $pattern = $rule['pattern'] ?? '';
        $matchType = $rule['match_type'] ?? 'prefix';

        if ($matchType === 'regex') {
            $regex = '~' . str_replace('~', '\~', $pattern) . '~';

            $previousLimit = ini_get('pcre.backtrack_limit');
            ini_set('pcre.backtrack_limit', self::SAFE_BACKTRACK_LIMIT);

            $result = preg_match($regex, $url);

            ini_set('pcre.backtrack_limit', $previousLimit);

            if ($result === false) {
                $errorCode = preg_last_error();
                try {
                    $logger = StaticContainer::get(LoggerInterface::class);
                    $logger->warning(
                        'ContentGrouping: regex error {errorCode} for pattern "{pattern}" on URL "{url}"',
                        ['errorCode' => $errorCode, 'pattern' => $pattern, 'url' => mb_substr($url, 0, 200)]
                    );
                } catch (\Exception $e) {
                    // logger unavailable during tests
                }
                return false;
            }

            return (bool) $result;
        }

        // prefix match
        return strpos($url, $pattern) === 0;
    }

    /**
     * Validate that a regex pattern is syntactically correct and safe from ReDoS.
     *
     * @param string $pattern
     * @return bool
     */
    public static function isValidRegex($pattern)
    {
        if (self::hasNestedQuantifiers($pattern)) {
            return false;
        }

        $regex = '~' . str_replace('~', '\~', $pattern) . '~';
        return @preg_match($regex, '') !== false;
    }

    /**
     * Detect nested quantifiers that can cause catastrophic backtracking (ReDoS).
     * Rejects patterns like (a+)+, (a*)+, (a+)*, (\w+)+, etc.
     *
     * @param string $pattern
     * @return bool True if nested quantifiers are detected
     */
    public static function hasNestedQuantifiers($pattern)
    {
        // Match a group (...) followed by a quantifier, where the group contains a quantifier
        // This catches (a+)+, (a+)*, (a*){2,}, (\w+)+, etc.
        return (bool) preg_match('/\([^)]*[+*}\?][^)]*\)\s*[+*?{]/', $pattern);
    }
}
