<?php
/**
 * @file parsers/aPlusPlus/IssueParser.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IssueParser
 * @brief Handles parsing and importing the issues
 */

namespace APP\plugins\importexport\articleImporter\parsers\aPlusPlus;

use APP\issue\Issue;
use APP\facades\Repo;
use APP\plugins\importexport\articleImporter\EntityManager;
use DateTimeImmutable;

trait IssueParser
{
    use EntityManager;

    /** @var Issue Issue instance */
    private ?Issue $_issue = null;

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
        $issue = Repo::issue()->newDataObject();
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
        $issue->setData('accessStatus', Issue::ISSUE_ACCESS_OPEN);
        $issue->setData('showVolume', true);
        $issue->setData('showNumber', true);
        $issue->setData('showYear', true);
        $issue->setData('showTitle', false);
        $issue->stampModified();
        Repo::issue()->add($issue);
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
