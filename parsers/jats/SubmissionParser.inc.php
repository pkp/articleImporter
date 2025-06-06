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
use PKP\Plugins\ImportExport\ArticleImporter\EntityManager;
use Services;
use StageAssignmentDAO;
use Submission;
use SubmissionDAO;

trait SubmissionParser
{
    use EntityManager;

    /**
     * Parses and retrieves the submission
     */
    public function getSubmission(): Submission
    {
        if ($submission = $this->getCachedSubmission()) {
            return $submission;
        }

        /** @var SubmissionDAO */
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $submission = $submissionDao->newDataObject();
        $submission->setData('contextId', $this->getContextId());
        $submission->setData('status', STATUS_PUBLISHED);
        $submission->setData('submissionProgress', 0);
        $submission->setData('stageId', WORKFLOW_STAGE_ID_PRODUCTION);
        $submission->setData('sectionId', $this->getSection()->getId());
        $submission->setData('locale', $this->getLocale());
        $date = $this->getDateFromNode($this->selectFirst("front/article-meta/history/date[@date-type='received']")) ?: $this->getPublicationDate()->add(new DateInterval('P1D'));
        $submission->setData('dateSubmitted', $date->format(static::DATETIME_FORMAT));
        // Creates the submission
        $submission = Services::get('submission')->add($submission, Application::get()->getRequest());
        $this->trackEntity($submission);
        $this->setCachedSubmission($submission);
        $this->_assignEditor();
        return $submission;
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
