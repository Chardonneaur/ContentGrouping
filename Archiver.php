<?php

namespace Piwik\Plugins\ContentGrouping;

class Archiver extends \Piwik\Plugin\Archiver
{
    public const CONTENT_GROUPS_RECORD_NAME = 'ContentGrouping_groups';
    public const DEFAULT_MAPPING_NAME = 'default';

    public static function normalizeMappingName($mappingName): string
    {
        $mappingName = trim((string) $mappingName);

        if ($mappingName === '') {
            return self::DEFAULT_MAPPING_NAME;
        }

        return $mappingName;
    }

    public static function getRecordNameForMapping($mappingName): string
    {
        $mappingName = self::normalizeMappingName($mappingName);

        if ($mappingName === self::DEFAULT_MAPPING_NAME) {
            return self::CONTENT_GROUPS_RECORD_NAME;
        }

        return self::CONTENT_GROUPS_RECORD_NAME . '_' . md5($mappingName);
    }
}
