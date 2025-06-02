<?php
/**
 * @file ArticleIterator.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleIterator
 * @brief Article iterator, responsible to navigate through the volume/issue/article structure and group the article files.
 */

namespace APP\plugins\importexport\articleImporter;

use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ArticleIterator implements \IteratorAggregate
{
    private const REQUIRED_DEPTH = 4; // volume/issue/article/version
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
        $directoryIterator = new RecursiveDirectoryIterator($this->path);
        $recursiveIterator = new RecursiveIteratorIterator(
            $directoryIterator,
            RecursiveIteratorIterator::SELF_FIRST
        );
        $recursiveIterator->setMaxDepth(self::REQUIRED_DEPTH);

        foreach ($recursiveIterator as $fileInfo) {
            if (!$fileInfo->isDot() && $fileInfo->isDir() && $recursiveIterator->getDepth() === static::REQUIRED_DEPTH) {
                yield new ArticleEntry($fileInfo);
            }
        }
    }
}
