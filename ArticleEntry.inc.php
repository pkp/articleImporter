<?php
/**
 * @file ArticleEntry.inc.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleEntry
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief Represents an article entry with its basic identification and version management
 */

namespace PKP\Plugins\ImportExport\ArticleImporter;

use Generator;
use PKP\Plugins\ImportExport\ArticleImporter\Exceptions\ArticleSkippedException;
use SplFileInfo;

class ArticleEntry
{
    /** @var SplFileInfo The article directory */
    private $_directory;
    /** @var int The issue's volume */
    private $_volume;
    /** @var string The issue's number */
    private $_issue;
    /** @var string The article's number */
    private $_article;

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
        $submission = null;
        foreach ($this->getVersions() as $version) {
            $submission = $version->process($configuration, $submission)->getSubmission();
            $processed = true;
        }

        if (!$processed) {
            throw new ArticleSkippedException('No versions were found');
        }
    }
}
