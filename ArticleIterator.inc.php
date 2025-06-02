<?php
/**
 * @file ArticleIterator.inc.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleIterator
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief Article iterator, responsible to navigate through the volume/issue/article structure and group the article files.
 */

namespace PKP\Plugins\ImportExport\ArticleImporter;

use Generator;
use SplFileInfo;

class ArticleIterator implements \IteratorAggregate
{
    /** @var string The path */
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Retrieves the iterator
     * @return iterable<ArticleEntry>
     */
    public function getIterator(): Generator
    {
        // volume/issue/article
        foreach (glob("{$this->path}/*/*/*", GLOB_ONLYDIR) as $path) {
            yield new ArticleEntry(new SplFileInfo($path));
        }
    }
}
