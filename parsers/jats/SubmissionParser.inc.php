<?php
/**
 * @file parsers/jats/SubmissionParser.inc.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmissionParser
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief Handles parsing and importing the submissions
 */

namespace PKP\Plugins\ImportExport\ArticleImporter\Parsers\Jats;

use Application;
use DAORegistry;
use DateInterval;
use Services;
use StageAssignmentDAO;
use Submission;
use SubmissionDAO;

trait SubmissionParser
{
    /** @var Submission Submission instance */
    private $_submission;
    /** @var bool True if the submission was created by this instance */
    private $_isSubmissionOwner;

    /**
     * Rollbacks the operation
     */
    private function _rollbackSubmission(): void
    {
        if ($this->_isSubmissionOwner && $this->_submission) {
            /** @var SubmissionDAO */
            $submissionDao = DAORegistry::getDAO('SubmissionDAO');
            $submissionDao->deleteObject($this->_submission);
        }
    }

    /**
     * Parses and retrieves the submission
     */
    public function getSubmission(): Submission
    {
        if ($this->_submission) {
            return $this->_submission;
        }

        /** @var SubmissionDAO */
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $article = $submissionDao->newDataObject();
        $article->setData('contextId', $this->getContextId());
        $article->setData('status', STATUS_PUBLISHED);
        $article->setData('submissionProgress', 0);
        $article->setData('stageId', WORKFLOW_STAGE_ID_PRODUCTION);
        $article->setData('sectionId', $this->getSection()->getId());
        $article->setData('locale', $this->getLocale());

        $date = $this->getDateFromNode($this->selectFirst("front/article-meta/history/date[@date-type='received']")) ?: $this->getPublicationDate()->add(new DateInterval('P1D'));
        $article->setData('dateSubmitted', $date->format(static::DATETIME_FORMAT));

        // Creates the submission
        $this->_submission = Services::get('submission')->add($article, Application::get()->getRequest());
        $this->_isSubmissionOwner = true;

        $this->_assignEditor();

        return $this->_submission;
    }

    /**
     * Sets the submission
     */
    public function setSubmission(Submission $submission): void
    {
        $this->_submission = $submission;
        $this->_isSubmissionOwner = false;
    }

    /**
     * Assign editor as participant in production stage
     */
    private function _assignEditor(): void
    {
        /** @var StageAssignmentDAO */
        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
        $stageAssignmentDao->build($this->getSubmission()->getId(), $this->getConfiguration()->getEditorGroupId(), $this->getConfiguration()->getEditor()->getId());
    }
}
