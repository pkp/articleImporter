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
use DateTimeImmutable;

trait IssueParser
{
    /** @var bool True if the issue was created by this instance */
    private bool $_isIssueOwner = false;

    /** @var Issue Issue instance */
    private ?Issue $_issue = null;

    /**
     * Rollbacks the operation
     */
    private function _rollbackIssue(): void
    {
        if ($this->_isIssueOwner) {
            Repo::issue()->delete($this->_issue);
        }
    }

    /**
     * Parses and retrieves the issue, if an issue with the same name exists, it will be retrieved
     */
    public function getIssue(): Issue
    {
        static $cache = [];

        if ($this->_issue) {
            return $this->_issue;
        }

        $entry = $this->getArticleEntry();
        if ($issue = $cache[$this->getContextId()][$entry->getVolume()][$entry->getIssue()] ?? null) {
            return $this->_issue = $issue;
        }

        // If this issue exists, return it
        $issues = Repo::issue()->getCollector()
            ->filterByContextIds([$this->getContextId()])
            ->filterByVolumes([$entry->getVolume()])
            ->filterByNumbers([$entry->getIssue()])
            ->getMany();
        $this->_issue = $issues->first();

        if (!$this->_issue) {
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

            $this->_isIssueOwner = true;
            $this->_issue = $issue;
        }

        return $cache[$this->getContextId()][$entry->getVolume()][$entry->getIssue()] = $this->_issue;
    }

    /**
     * Retrieves the issue publication date
     */
    public function getIssuePublicationDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->getIssue()->getData('datePublished'));
    }
}
