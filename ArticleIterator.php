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

use ArrayIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ArticleIterator extends ArrayIterator
{
    /**
     * Constructor
     *
     * @param string $path The base import path
     */
    public function __construct(string $path)
    {
        parent::__construct($this->_getEntries($path));
    }

    /**
     * Retrieves a list of ArticleEntry with the paths that follow the folder convention
     *
     * @return ArticleEntry[]
     */
    private function _getEntries(string $path): array
    {
        $directoryIterator = new RecursiveDirectoryIterator($path);
        $recursiveIterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);
        // Ignores deeper folders
        $recursiveIterator->setMaxDepth(4);
        $articleEntries = [];

        foreach ($recursiveIterator as $file) {
            if ($file->isFile() && $recursiveIterator->getDepth() >= 3) {
                $this->_processFile($file, $recursiveIterator->getDepth(), $articleEntries);
            }
        }

        // Sorts the entries by key (at this point made up of "volume-issue-article")
        ksort($articleEntries, SORT_NATURAL);

        return $articleEntries;
    }

    /**
     * Process a file and add it to the appropriate ArticleEntry
     */
    private function _processFile(SplFileInfo $file, int $depth, array &$articleEntries): void
    {
        $pathInfo = $file->getPathInfo();
        if ($depth === 4) {
            $version = $pathInfo->getFilename();
            $pathInfo = $pathInfo->getPathInfo();
        } else {
            $version = '1';
        }

        $article = $pathInfo->getFilename();
        $issue = $pathInfo->getPathInfo()->getFilename();
        $volume = $pathInfo->getPathInfo()->getPathInfo()->getFilename();

        $key = "{$volume}-{$issue}-{$article}";
        ($articleEntries[$key] ?? $articleEntries[$key] = new ArticleEntry($volume, $issue, $article))
            ->addFile($file, $version);
    }
}
