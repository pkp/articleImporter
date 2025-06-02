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

use Exception;
use Generator;
use FilesystemIterator;
use APP\plugins\importexport\articleImporter\exceptions\InvalidDocTypeException;
use APP\plugins\importexport\articleImporter\exceptions\NoSuitableParserException;
use SplFileInfo;

class ArticleEntry
{
    /** @var SplFileInfo The article directory */
    private $_directory;
    /** @var int The issue's volume */
    private int $_volume;

    /** @var string The issue's number */
    private int $_issue;

    /** @var string The article's number */
    private int $_article;

    /**
     * Constructor
     *
     * @param SplFileInfo $directory The article directory
     */
    public function __construct(SplFileInfo $directory)
    {
        $this->_directory = $directory;
        $pathInfo = $directory->getPathInfo();
        foreach ([&$this->_article, &$this->_issue, &$this->_volume] as &$item) {
            $item = $pathInfo->getFilename();
            $pathInfo = $pathInfo->getPathInfo();
        }
    }

    /**
     * Gets all available versions as a Generator
     *
     * @return iterable<ArticleVersion> Yields ArticleVersion instances
     */
    public function getVersions(): Generator
    {
        foreach (new \DirectoryIterator($this->_directory->getPathname()) as $versionDir) {
            if (!$versionDir->isDot() && $versionDir->isDir()) {
                yield new ArticleVersion($this, $versionDir);
            }
        }
    }

    /**
     * Retrieves the issue volume
     */
    public function getVolume(): int
    {
        return $this->_volume;
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
		foreach ($this->getVersions() as $version) {
			$version->process($configuration);
		}
	}
}
