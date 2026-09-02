<?php

namespace Modules\Feeds;

use App\Core\Modules\Contracts\ModuleUninstall;

/**
 * Feed configuration is a setting, not a record: the XML is generated on
 * request from the catalogue and nothing else in the platform reads these two
 * tables. A tenant who stops selling through comparison sites can have them
 * gone.
 *
 * `feed_category_mappings` references `categories`, not the other way round,
 * so removing the mappings leaves the category tree untouched.
 */
class Lifecycle implements ModuleUninstall
{
    /**
     * @return list<string>
     */
    public function tablesToPurge(): array
    {
        return ['feed_category_mappings', 'product_feeds'];
    }
}
