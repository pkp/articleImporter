<?php
/**
 * @file parsers/aPlusPlus/SubmissionParser.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmissionParser
 * @brief Handles parsing and importing the submissions
 */

namespace APP\plugins\importexport\articleImporter\parsers\aPlusPlus;

use APP\facades\Repo;
use APP\plugins\importexport\articleImporter\ArticleImporterPlugin;
use DateInterval;
use APP\submission\Submission;
use PKP\db\DAORegistry;
use PKP\stageAssignment\StageAssignmentDAO;

trait SubmissionParser
{
    /** @var Submission Submission instance */
    private ?Submission $_submission = null;

    /**
     * Rollbacks the operation
     */
    private function _rollbackSubmission(): void
    {
        if ($this->_submission) {
            Repo::submission()->delete($this->_submission);
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

        $article = Repo::submission()->newDataObject();
        $article->setData('contextId', $this->getContextId());
        $article->setData('status', Submission::STATUS_PUBLISHED);
        $article->setData('submissionProgress', '');
        $article->setData('stageId', WORKFLOW_STAGE_ID_PRODUCTION);
        $article->setData('sectionId', $this->getSection()->getId());
        $date = $this->getDateFromNode($this->selectFirst('Journal/Volume/Issue/Article/ArticleInfo/ArticleHistory/RegistrationDate')) ?: $this->getPublicationDate()->add(new DateInterval('P1D'));
        $article->setData('dateSubmitted', $date->format(ArticleImporterPlugin::DATETIME_FORMAT));

        // Creates the submission
        $this->_submission = Repo::submission()->dao->insert($article);

        $this->_assignEditor();

        return $this->_submission;
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
