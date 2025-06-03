<?php
/**
 * @file parsers/aPlusPlus/PublicationParser.inc.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PublicationParser
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief Handles parsing and importing the publications
 */

namespace PKP\Plugins\ImportExport\ArticleImporter\Parsers\APlusPlus;

use Application;
use APP\Services\SubmissionFileService;
use ArticleGalleyDAO;
use Core;
use DAORegistry;
use DateTimeImmutable;
use Exception;
use PKP\Services\PKPFileService;
use PKPLocale;
use Publication;
use PublicationDAO;
use Services;
use SubmissionFileDAO;
use SubmissionKeywordDAO;

trait PublicationParser
{
    /**
     * Parse, import and retrieve the publication
     */
    public function getPublication(): Publication
    {
        $publicationDate = $this->getPublicationDate() ?: $this->getIssue()->getDatePublished();
        $version = $this->getArticleVersion();

        // Create the publication
        /** @var PublicationDAO */
        $publicationDao = DAORegistry::getDAO('PublicationDAO');
        /** @var Publication */
        $publication = $publicationDao->newDataObject();
        $publication->setData('submissionId', $this->getSubmission()->getId());
        $publication->setData('status', STATUS_PUBLISHED);
        $publication->setData('version', (int)$version);
        $publication->setData('seq', $this->getSubmission()->getId());
        $publication->setData('accessStatus', $this->_getAccessStatus());
        $publication->setData('datePublished', $publicationDate->format(static::DATETIME_FORMAT));
        $publication->setData('sectionId', $this->getSection()->getId());
        $publication->setData('issueId', $this->getIssue()->getId());
        $publication->setData('urlPath', null);

        // Set article pages
        $firstPage = $this->selectText('Journal/Volume/Issue/Article/ArticleInfo/ArticleFirstPage');
        $lastPage = $this->selectText('Journal/Volume/Issue/Article/ArticleInfo/ArticleLastPage');
        if ($firstPage || $lastPage) {
            $publication->setData('pages', "{$firstPage}" . ($lastPage ? "-{$lastPage}" : ''));
        }

        $hasTitle = false;
        $publicationLocale = null;

        // Set title
        foreach ($this->select('Journal/Volume/Issue/Article/ArticleInfo/ArticleTitle') as $node) {
            $locale = $this->getLocale($node->getAttribute('Language'));
            // The publication language is defined by the first title node
            if (!$publicationLocale) {
                $publicationLocale = $locale;
            }
            $value = $this->selectText('.', $node);
            $hasTitle |= strlen($value);
            $publication->setData('title', $value, $locale);
        }

        if (!$hasTitle) {
            throw new Exception(__('plugins.importexport.articleImporter.articleTitleMissing'));
        }

        $publication->setData('locale', $publicationLocale);
        $publication->setData('language', PKPLocale::getIso1FromLocale($publicationLocale));

        // Set subtitle
        foreach ($this->select('Journal/Volume/Issue/Article/ArticleInfo/ArticleSubTitle') as $node) {
            $publication->setData('subtitle', $this->selectText('.', $node), $this->getLocale($node->getAttribute('Language')));
        }

        // Set article abstract
        foreach ($this->select('Journal/Volume/Issue/Article/ArticleHeader/Abstract') as $abstract) {
            $value = trim($this->getTextContent($abstract, function ($node, $content) use ($abstract) {
                // Ignores the main Heading tag
                if ($node->nodeName == 'Heading' && $node->parentNode === $abstract) {
                    return '';
                }
                // Transforms the known tags, the remaining ones will be stripped
                if ($node->nodeName == 'Heading') {
                    return "<p><strong>{$content}</strong></p>";
                }
                $tag = [
                    'Emphasis' => 'em',
                    'Subscript' => 'sub',
                    'Superscript' => 'sup',
                    'Para' => 'p'
                ][$node->nodeName] ?? null;
                return $tag ? "<{$tag}>{$content}</{$tag}>" : $content;
            }));
            if ($value) {
                $publication->setData('abstract', $value, $this->getLocale($abstract->getAttribute('Language')));
            }
        }

        // Set public IDs
        foreach ($this->getPublicIds() as $type => $value) {
            $publication->setData('pub-id::' . $type, $value);
        }

        // Set copyright year and holder and license permissions
        $publication->setData('copyrightHolder', $this->selectText('Journal/Volume/Issue/Article/ArticleInfo/ArticleCopyright/CopyrightHolderName'), $this->getLocale());
        $publication->setData('copyrightNotice', null);
        $publication->setData('copyrightYear', $this->selectText('Journal/Volume/Issue/Article/ArticleInfo/ArticleCopyright/CopyrightYear') ?: $publicationDate->format('Y'));
        $publication->setData('licenseUrl', null);
        $this->setPublicationCoverImage($publication);

        // Inserts the publication and updates the submission's publication ID
        $publication = Services::get('publication')->add($publication, Application::get()->getRequest());

        $this->_processKeywords($publication);
        // Reload object with keywords (otherwise they will be cleared later on)
        $publication = Services::get('publication')->get($publication->getId());
        $this->_processAuthors($publication);
        $publication = Services::get('publication')->edit($publication, [], Application::get()->getRequest());

        // Handle PDF galley
        $this->_insertPDFGalley($publication);

        // Publishes the article
        Services::get('publication')->publish($publication);

        return $publication;
    }

    /**
     * Retrieves the access status of the submission
     */
    private function _getAccessStatus(): int
    {
        // Checks if there's an ArticleGrant different of OpenAccess
        return $this->evaluate("count(Journal/Volume/Issue/Article/ArticleInfo/ArticleGrants/*[@Grant!='OpenAccess'])") > 0
            ? ARTICLE_ACCESS_ISSUE_DEFAULT
            : ARTICLE_ACCESS_OPEN;
    }

    /**
     * Inserts the PDF galley
     */
    private function _insertPDFGalley(Publication $publication): void
    {
        $file = $this->getArticleVersion()->getSubmissionFile();
        if (!$file) {
            return;
        }
        $filename = $file->getFilename();

        // Create a representation of the article (i.e. a galley)
        /** @var ArticleGalleyDAO */
        $representationDao = Application::getRepresentationDAO();
        $representation = $representationDao->newDataObject();
        $representation->setData('publicationId', $publication->getId());
        $representation->setData('name', $filename, $this->getLocale());
        $representation->setData('seq', 1);
        $representation->setData('label', 'PDF');
        $representation->setData('locale', $this->getLocale());
        $newRepresentationId = $representationDao->insertObject($representation);

        // Add the PDF file and link representation with submission file
        /** @var SubmissionFileService $submissionFileService */
        $submissionFileService = Services::get('submissionFile');
        /** @var PKPFileService $fileService */
        $fileService = Services::get('file');
        $submission = $this->getSubmission();

        $submissionDir = $submissionFileService->getSubmissionDir($submission->getData('contextId'), $submission->getId());
        $newFileId = $fileService->add(
            $file->getPathname(),
            $submissionDir . '/' . uniqid() . '.pdf'
        );

        /** @var SubmissionFileDAO $submissionFileDao */
        $submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
        $newSubmissionFile = $submissionFileDao->newDataObject();
        $newSubmissionFile->setData('submissionId', $submission->getId());
        $newSubmissionFile->setData('fileId', $newFileId);
        $newSubmissionFile->setData('genreId', $this->getConfiguration()->getSubmissionGenre()->getId());
        $newSubmissionFile->setData('fileStage', SUBMISSION_FILE_PROOF);
        $newSubmissionFile->setData('uploaderUserId', $this->getConfiguration()->getEditor()->getId());
        $newSubmissionFile->setData('createdAt', Core::getCurrentDate());
        $newSubmissionFile->setData('updatedAt', Core::getCurrentDate());
        $newSubmissionFile->setData('assocType', ASSOC_TYPE_REPRESENTATION);
        $newSubmissionFile->setData('assocId', $newRepresentationId);
        $newSubmissionFile->setData('name', $filename, $this->getLocale());
        $submissionFile = $submissionFileService->add($newSubmissionFile, Application::get()->getRequest());

        $representation = $representationDao->getById($newRepresentationId);
        $representation->setFileId($submissionFile->getData('fileId'));
        $representationDao->updateObject($representation);
    }

    /**
     * Retrieves the public IDs
     *
     * @return array Returns array, where the key is the type and value the ID
     */
    public function getPublicIds(): array
    {
        $articleEntry = $this->getArticleEntry();
        $ids = ['publisher-id' => "{$articleEntry->getVolume()}.{$articleEntry->getIssue()}.{$articleEntry->getArticle()}.{$this->getArticleVersion()->getVersion()}"];
        if ($value = $this->selectText('Journal/Volume/Issue/Article/@ID')) {
            $ids['publisher-id'] = $value;
        }
        if ($value = $this->selectText('Journal/Volume/Issue/Article/ArticleInfo/ArticleDOI')) {
            $ids['doi'] = $value;
        }
        return $ids;
    }

    /**
     * Retrieves the publication date
     */
    public function getPublicationDate(): DateTimeImmutable
    {
        $date = $this->getDateFromNode($this->selectFirst('Journal/Volume/Issue/Article/ArticleInfo/ArticleHistory/OnlineDate'))
            ?: $this->getIssuePublicationDate();
        if (!$date) {
            throw new Exception(__('plugins.importexport.articleImporter.missingPublicationDate'));
        }
        return $date;
    }

    /**
     * Process the article keywords
     */
    private function _processKeywords(Publication $publication): void
    {
        /** @var SubmissionKeywordDAO */
        $submissionKeywordDAO = DAORegistry::getDAO('SubmissionKeywordDAO');
        $keywords = [];
        foreach ($this->select('Journal/Volume/Issue/Article/ArticleHeader/KeywordGroup') as $node) {
            $locale = $this->getLocale($node->getAttribute('Language'));
            foreach ($this->select('Keyword', $node) as $node) {
                $keywords[$locale][] = $this->selectText('.', $node);
            }
        }
        if (count($keywords)) {
            $submissionKeywordDAO->insertKeywords($keywords, $publication->getId());
        }
    }
}
