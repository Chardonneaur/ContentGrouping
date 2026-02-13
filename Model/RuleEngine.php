<?php

namespace Piwik\Plugins\ContentGrouping\Model;

use Piwik\Piwik;

class RuleEngine
{
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
            $pattern = $rule['pattern'] ?? '';
            $matchType = $rule['match_type'] ?? 'prefix';

            if ($matchType === 'regex') {
                $regex = '~' . str_replace('~', '\~', $pattern) . '~';
                if (@preg_match($regex, $url)) {
                    return $rule['group_name'];
                }
            } else {
                // prefix match
                if (strpos($url, $pattern) === 0) {
                    return $rule['group_name'];
                }
            }
        }

        return Piwik::translate('General_NotDefined', Piwik::translate('ContentGrouping_ContentGroup'));
    }

    /**
     * Validate that a regex pattern is syntactically correct.
     *
     * @param string $pattern
     * @return bool
     */
    public static function isValidRegex($pattern)
    {
        $regex = '~' . str_replace('~', '\~', $pattern) . '~';
        return @preg_match($regex, '') !== false;
    }
}
