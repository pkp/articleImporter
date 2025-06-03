<?php
/**
 * @file ArticleEntry.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2000-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleEntry
 * @brief Glues together the volume/issue/article numbers and the article files
 */

namespace APP\plugins\importexport\articleImporter;

use FilesystemIterator;
use Illuminate\Contracts\Filesystem\Filesystem;
use APP\plugins\importexport\articleImporter\exceptions\InvalidDocTypeException;
use APP\plugins\importexport\articleImporter\exceptions\NoSuitableParserException;

class ArticleEntry
{
    /** @var \SplFileInfo[] List of files */
    private array $_files = [];

    /** @var int The issue's volume */
    private int $_volume;

    /** @var string The issue's number */
    private int $_issue;

    /** @var string The article's number */
    private int $_article;

    /**
     * Constructor
     *
     * @param int $volume The issue's volume
     * @param string $issue The issue's number
     * @param string $article The article's number
     */
    public function __construct(int $volume, string $issue, string $article)
    {
        $this->_volume = $volume;
        $this->_issue = $issue;
        $this->_article = $article;
    }

    /**
     * Adds a file to the list
     */
    public function addFile(\SplFileInfo $file): void
    {
        $this->_files[] = $file;
    }

    /**
     * Retrieves the file list
     *
     * @return \SplFileInfo[]
     */
    public function getFiles(): array
    {
        return $this->_files;
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
     * Retrieves the submission file
     *
     * @throws \Exception Throws if there's more than one submission file
     */
    public function getSubmissionFile(): ?\SplFileInfo
    {
        $count = count($paths = array_filter($this->_files, function ($path) {
            return preg_match('/\.pdf$/i', $path);
        }));
        if ($count > 1) {
            throw new \Exception(__('plugins.importexport.articleImporter.unexpectedGalley', ['count' => $count]));
        }
        return reset($paths) ?: null;
    }

    /**
     * Retrieves the metadata file
     *
     * @throws \Exception Throws if there's more than one metadata file
     */
    public function getMetadataFile(): \SplFileInfo
    {
        $count = count($paths = array_filter($this->_files, function ($path) {
            return preg_match('/\.xml$/i', $path);
        }));
        if ($count != 1) {
            throw new \Exception(__('plugins.importexport.articleImporter.unexpectedMetadata', ['count' => $count]));
        }
        return reset($paths);
    }

    /**
     * Processes the entry
     *
     * @throws NoSuitableParserException Throws if no parser could understand the format
     */
    public function process(Configuration $configuration): BaseParser
    {
        foreach ($configuration->getParsers() as $parser) {
            try {
                $instance = new $parser($configuration, $this);
                $instance->execute();
                return $instance;
            } catch (InvalidDocTypeException $e) {
                // If the parser cannot understand the format, try the next
                continue;
            }
        }
        // If no parser could understand the format
        throw new NoSuitableParserException(__('plugins.importexport.articleImporter.invalidDoctype'));
    }

    /**
     * Retrieves the HTML galley file
     *
     * @throws \Exception Throws if there's more than one
     * @return \SplFileInfo[]
     */
    public function getHtmlFiles(): array
    {
        return array_values(array_filter($this->_files, function ($path) {
            return preg_match('/\.html?$/i', $path);
        }));
    }

    /**
     * Reloads the immediate files, this is used after generating HTML files out of a JATS body tag
     */
    public function reloadFiles(): void
    {
        $iterator = new FilesystemIterator($this->getMetadataFile()->getPathInfo(), FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS);
        $this->_files = array_values(iterator_to_array($iterator));
    }

    /**
     * Retrieves the cover file
     *
     * @throws \Exception Throws if there's more than one cover file
     */
    public function getSubmissionCoverFile(): ?\SplFileInfo
    {
        $count = count($paths = array_filter($this->_files, function ($path) {
            return preg_match('/article_cover\.[a-z]{3}/i', $path);
        }));
        if ($count > 1) {
            throw new \Exception(__('plugins.importexport.articleImporter.unexpectedMetadata', ['count' => $count]));
        }
        return reset($paths) ?: null;
    }

    /**
     * Retrieve the supplementary files
     * @return \SplFileInfo[]
     */
    public function getSupplementaryFiles(): array
    {
        return is_dir($path = $this->getMetadataFile()->getPathInfo() . '/supplementary')
            ? array_values(iterator_to_array(new FilesystemIterator($path)))
            : [];
    }
}
