<?php
/**
 * @file ArticleEntry.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleEntry
 * @brief Represents an article entry with its basic identification and version management
 */

namespace APP\plugins\importexport\articleImporter;

use APP\plugins\importexport\articleImporter\exceptions\ArticleSkippedException;
use Generator;
use SplFileInfo;

class ArticleEntry
{
    /** @var SplFileInfo The article directory */
    private SplFileInfo $_directory;
    /** @var int The issue's volume */
    private int $_volume = 0;
    /** @var string The issue's number */
    private int $_issue = 0;
    /** @var string The article's number */
    private int $_article = 0;

    /**
     * Constructor
     *
     * @param SplFileInfo $directory The article directory
     */
    public function __construct(SplFileInfo $directory)
    {
        $this->_directory = $directory;
        foreach ([&$this->_article, &$this->_issue, &$this->_volume] as &$item) {
            $item = $directory->getFilename();
            $directory = $directory->getPathInfo();
        }
    }

    /**
     * Gets all available versions as a Generator
     *
     * @return iterable<ArticleVersion> Yields ArticleVersion instances
     */
    public function getVersions(): Generator
    {
        foreach (glob("{$this->_directory->getPathname()}/*", GLOB_ONLYDIR) as $versionDir) {
            yield new ArticleVersion($this, new SplFileInfo($versionDir));
        }
    }

    /**
     * Retrieves the issue volume
     */
    public function getVolume(): int
    {
        return (int) $this->_volume;
    }

    /**
     * Retrieves the issue number
     */
    public function getIssue(): string
    {
        return $this->_issue;
    }

    /**
     * Retrieves the article number
     */
    public function getArticle(): string
    {
        return $this->_article;
    }

    /**
     * Processes the article
     */
    public function process(Configuration $configuration): void
    {
        $processed = false;
        foreach ($this->getVersions() as $version) {
            $version->process($configuration);
            $processed = true;
        }

        if (!$processed) {
            throw new ArticleSkippedException('No versions were processed');
        }
    }
}
