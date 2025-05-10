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
 * @brief Glues together the volume/issue/article numbers and the article files
 */

namespace PKP\Plugins\ImportExport\ArticleImporter;

use Exception;
use FilesystemIterator;
use PKP\Plugins\ImportExport\ArticleImporter\Exceptions\InvalidDocTypeException;
use PKP\Plugins\ImportExport\ArticleImporter\Exceptions\NoSuitableParserException;
use SplFileInfo;

class ArticleEntry
{
    /** @var array Map of versions to their files */
    private $_filesByVersion = [];
    /** @var int The issue's volume */
    private $_volume;
    /** @var string The issue's number */
    private $_issue;
    /** @var string The article's number */
    private $_article;

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
     *
     * @param SplFileInfo $file The file to add
     * @param string $version The version number of the file
     */
    public function addFile(SplFileInfo $file, string $version): void
    {
        $this->_filesByVersion[$version][] = $file;
    }

    /**
     * Gets all available versions
     *
     * @return array List of version numbers
     */
    public function getVersions(): array
    {
        return array_keys($this->_filesByVersion);
    }

    /**
     * Gets all files for a specific version
     *
     * @param string $version The version number
     * @return SplFileInfo[] List of files for the version
     */
    public function getFilesForVersion(string $version): array
    {
        return $this->_filesByVersion[$version] ?? [];
    }

    /**
     * Gets the metadata file
     *
     * @param string $version The version number
     * @return SplFileInfo The metadata file
     * @throws Exception If no metadata file is found
     */
    public function getMetadataFile(string $version): SplFileInfo
    {
        $files = $this->getFilesForVersion($version);
        $count = count($paths = array_filter($files, function ($path) {
            return preg_match('/\.xml$/i', $path);
        }));
        if ($count != 1) {
            throw new Exception(__('plugins.importexport.articleImporter.unexpectedMetadata', ['count' => $count]));
        }
        return reset($paths);
    }

    /**
     * Gets the submission file
     *
     * @param string $version The version number
     * @return SplFileInfo|null The submission file or null if not found
     * @throws Exception If multiple submission files are found
     */
    public function getSubmissionFile(string $version): ?SplFileInfo
    {
        $files = $this->getFilesForVersion($version);
        $count = count($paths = array_filter($files, function ($path) {
            return preg_match('/\.pdf$/i', $path);
        }));
        if ($count > 1) {
            throw new Exception(__('plugins.importexport.articleImporter.unexpectedGalley', ['count' => $count]));
        }
        return reset($paths) ?: null;
    }

    /**
     * Gets the HTML files
     *
     * @param string $version The version number
     * @return SplFileInfo[] List of HTML files
     */
    public function getHtmlFiles(string $version): array
    {
        $files = $this->getFilesForVersion($version);
        return array_values(array_filter($files, function ($path) {
            return preg_match('/\.html?$/i', $path);
        }));
    }

    /**
     * Gets the supplementary files
     *
     * @param string $version The version number
     * @return SplFileInfo[] List of supplementary files
     */
    public function getSupplementaryFiles(string $version): array
    {
        $metadataFile = $this->getMetadataFile($version);
        return is_dir($path = $metadataFile->getPathInfo() . '/supplementary')
            ? array_values(iterator_to_array(new FilesystemIterator($path)))
            : [];
    }

    /**
     * Gets the cover file
     *
     * @param string $version The version number
     * @return SplFileInfo|null The cover file or null if not found
     * @throws Exception If multiple cover files are found
     */
    public function getSubmissionCoverFile(string $version): ?SplFileInfo
    {
        $files = $this->getFilesForVersion($version);
        $count = count($paths = array_filter($files, function ($path) {
            return preg_match('/article_cover\.[a-z]{3}/i', $path);
        }));
        if ($count > 1) {
            throw new Exception(__('plugins.importexport.articleImporter.unexpectedMetadata', ['count' => $count]));
        }
        return reset($paths) ?: null;
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
     * Processes the entry
     *
     * @throws NoSuitableParserException Throws if no parser could understand the format
     */
    public function process(Configuration $configuration): BaseParser
    {
		/** @var BaseParser */
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
     * Reloads the immediate files, this is used after generating HTML files out of a JATS body tag
     *
     * @param string $version The version number
     */
    public function reloadFiles(string $version): void
    {
        $iterator = new FilesystemIterator($this->getMetadataFile($version)->getPathInfo(), FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS);
        $this->_filesByVersion[$version] = array_values(iterator_to_array($iterator));
    }
}
