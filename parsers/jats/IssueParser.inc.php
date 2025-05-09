<?php
/**
 * @file plugins/importexport/articleImporter/parsers/jats/IssueParser.inc.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IssueParser
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief Handles parsing and importing the issues
 */

namespace PKP\Plugins\ImportExport\ArticleImporter\Parsers\Jats;

use PKP\Plugins\ImportExport\ArticleImporter\ArticleImporterPlugin;

trait IssueParser
{
    /** @var bool True if the issue was created by this instance */
    private $_isIssueOwner;
    /** @var \Issue Issue instance */
    private $_issue;
    /** @var array{date:?DateTimeImmutable, title: ?string, section: ?string} Issue metadata */
    private $_issueMeta;

    /**
     * Rollbacks the operation
     */
    private function _rollbackIssue(): void
    {
        if ($this->_isIssueOwner) {
            \DAORegistry::getDAO('IssueDAO')->deleteObject($this->_issue);
        }
    }

    /**
     * Get issue meta
     *
     * @return array{date:?DateTimeImmutable, title: ?string, section: ?string}
     */
    private function getIssueMeta(): array
    {
        if ($this->_issueMeta) {
            return $this->_issueMeta;
        }

        $document = new DOMDocument('1.0', 'utf-8');
        $path = $this->getArticleEntry()->getMetadataFile()->getPathInfo()->getPathInfo();
        $issueMetaPath = $path . '/' . $path->getBasename() . '/' . $path->getBasename() . '.xml';
        if (file_exists($issueMetaPath)) {
            return $this->_issueMeta = [
                'date' => null,
                'title' => null,
                'section' => null
            ];
        }

        $document->load($issueMetaPath);
        $xpath = new DOMXPath($document);
        $doi = $this->getPublicIds()['doi'] ?? null;
        $node = $this->selectFirst('issue-meta/pub-date', null, $xpath);
        $publicationDate = $this->getDateFromNode($node, $xpath);
        $title = $this->selectText('issue-meta/issue-title', null, $xpath);
        $section = $doi ? $this->selectText("//article-id[.='" . $doi . "']/ancestor::issue-subject-group/issue-subject-title", null, $xpath) : null;
        return $this->_issueMeta = [
            'date' => $publicationDate,
            'title' => $title,
            'section' => $section
        ];
    }

    /**
     * Parses and retrieves the issue, if an issue with the same name exists, it will be retrieved
     */
    public function getIssue(): \Issue
    {
        static $cache = [];

        if ($this->_issue) {
            return $this->_issue;
        }

        $entry = $this->getArticleEntry();
        $volume = $this->selectText('front/article-meta/volume') ?: $entry->getVolume();
        $issueNumber = $this->selectText('front/article-meta/issue') ?: $entry->getIssue();
        if ($issue = $cache[$this->getContextId()][$volume][$issueNumber] ?? null) {
            return $this->_issue = $issue;
        }

        // If this issue exists, return it
        $issues = \Services::get('issue')->getMany([
            'contextId' => $this->getContextId(),
            'volumes' => $volume,
            'numbers' => $issueNumber
        ]);
        $this->_issue = $issues->current();

        if (!$this->_issue) {
            $locale = $this->getLocale();

            // Create a new issue
            $issueDao = \DAORegistry::getDAO('IssueDAO');
            $issue = $issueDao->newDataObject();

            $node = $this->selectFirst("front/article-meta/pub-date[@pub-type='collection']");
            $publicationDate = $this->getIssueMeta()['date'] ?? $this->getDateFromNode($node) ?? $this->getPublicationDate();

            $issue->setData('title', $this->getIssueMeta()['title'], $locale);
            $issue->setData('journalId', $this->getContextId());
            $issue->setData('volume', $volume);
            $issue->setData('number', $issueNumber);
            $issue->setData('year', (int) $publicationDate->format('Y'));
            $issue->setData('published', true);
            $issue->setData('current', false);
            $issue->setData('datePublished', $publicationDate->format(ArticleImporterPlugin::DATETIME_FORMAT));
            $issue->setData('accessStatus', \ISSUE_ACCESS_OPEN);
            $issue->setData('showVolume', true);
            $issue->setData('showNumber', true);
            $issue->setData('showYear', true);
            $issue->setData('showTitle', true);
            $issue->stampModified();
            $issueDao->insertObject($issue);

            $issueFolder = (string)$entry->getSubmissionFile()->getPathInfo();
            $this->setIssueCover($issueFolder, $issue);

            $this->_isIssueOwner = true;

            $this->_issue = $issue;
        }

        return $cache[$this->getContextId()][$volume][$issueNumber] = $this->_issue;
    }

    /**
     * Retrieves the issue publication date
     */
    public function getIssuePublicationDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->getIssue()->getData('datePublished'));
    }
}
