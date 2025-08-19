<?php
/**
 * @file parsers/aPlusPlus/IssueParser.inc.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IssueParser
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief Handles parsing and importing the issues
 */

namespace PKP\Plugins\ImportExport\ArticleImporter\Parsers\APlusPlus;

use DAORegistry;
use DateTimeImmutable;
use Issue;
use IssueDAO;
use PKP\Plugins\ImportExport\ArticleImporter\EntityManager;

trait IssueParser
{
    use EntityManager;

    /** @var Issue Issue instance */
    private $_issue;

    /**
     * Parses and retrieves the issue, if an issue with the same name exists, it will be retrieved
     */
    public function getIssue(): Issue
    {
        if ($this->_issue) {
            return $this->_issue;
        }

        $entry = $this->getArticleEntry();
        if ($issue = $this->getCachedIssue($entry->getVolume(), $entry->getIssue())) {
            return $this->_issue = $issue;
        }

        if ($this->_issue = $this->getCachedIssue($entry->getVolume(), $entry->getIssue())) {
            return $this->_issue;
        }

        // Create a new issue
        /** @var IssueDAO */
        $issueDao = DAORegistry::getDAO('IssueDAO');
        $issue = $issueDao->newDataObject();

        $publicationDate = $this->getDateFromNode($this->selectFirst('Journal/Volume/Issue/IssueInfo/IssueHistory/OnlineDate'))
            ?: $this->getDateFromNode($this->selectFirst('Journal/Volume/Issue/IssueInfo/IssueHistory/CoverDate'))
            ?: $this->getPublicationDate();

        $issue->setData('journalId', $this->getContextId());
        $issue->setData('volume', $entry->getVolume());
        $issue->setData('number', $entry->getIssue());
        $issue->setData('year', (int) $publicationDate->format('Y'));
        $issue->setData('published', true);
        $issue->setData('current', false);
        $issue->setData('datePublished', $publicationDate->format(static::DATETIME_FORMAT));
        $issue->setData('accessStatus', ISSUE_ACCESS_OPEN);
        $issue->setData('showVolume', true);
        $issue->setData('showNumber', true);
        $issue->setData('showYear', true);
        $issue->setData('showTitle', false);
        $issue->stampModified();
        $issueDao->insertObject($issue);
        $this->setIssueCoverImage($issue);
        $this->trackEntity($issue);
        $this->setCachedIssue($entry->getVolume(), $entry->getIssue(), $issue);
        return $this->_issue = $issue;
    }

    /**
     * Retrieves the issue publication date
     */
    public function getIssuePublicationDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->getIssue()->getData('datePublished'));
    }
}
