<?php
/**
 * @file parsers/jats/PublicationParser.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PublicationParser
 * @brief Handles parsing and importing the publications
 */

namespace APP\plugins\importexport\articleImporter\parsers\jats;

use APP\plugins\importexport\articleImporter\ArticleImporterPlugin;
use APP\submission\Submission;
use DateTime;
use DateTimeImmutable;
use DOMDocument;
use Exception;
use FilesystemIterator;
use PKP\file\TemporaryFileDAO;
use PKP\Services\PKPFileService;
use APP\publication\Publication;
use PKP\file\TemporaryFile;
use PKP\db\DAORegistry;
use APP\core\Services;
use PKP\core\Core;
use APP\core\Application;
use PKP\i18n\LocaleConversion;
use PKP\submission\Genre;
use PKP\plugins\PluginRegistry;
use PKP\submissionFile\SubmissionFile;
use PKP\file\TemporaryFileManager;
use PKP\core\PKPString;
use APP\facades\Repo;
use PKP\controlledVocab\ControlledVocab;
use SplFileInfo;
use Stringy\Stringy;

trait PublicationParser
{
    /**
     * Parse, import and retrieve the publication
     */
    public function getPublication(): Publication
    {
        $publicationDate = $this->getPublicationDate() ?: $this->getIssuePublicationDate();
        $latestPublication = null;

        // Create a publication for each version
        foreach ($this->getArticleEntry()->getVersions() as $version) {
            // Create the publication
            $publication = Repo::publication()->dao->newDataObject();
            $publication->setData('submissionId', $this->getSubmission()->getId());
            $publication->setData('status', STATUS_PUBLISHED);
            $publication->setData('version', (int)$version);
            $publication->setData('seq', $this->getSubmission()->getId());
            $publication->setData('accessStatus', ARTICLE_ACCESS_OPEN);
            $publication->setData('datePublished', $publicationDate->format(ArticleImporterPlugin::DATETIME_FORMAT));
            $publication->setData('sectionId', $this->getSection()->getId());
            $publication->setData('issueId', $this->getIssue()->getId());
            $publication->setData('urlPath', null);

            // Set article pages
            $firstPage = $this->selectText('front/article-meta/fpage');
            $lastPage = $this->selectText('front/article-meta/lpage');
            if ($firstPage || $lastPage) {
                $publication->setData('pages', "{$firstPage}" . ($lastPage ? "-{$lastPage}" : ''));
            }

            $hasTitle = false;

            // Set title
            if ($node = $this->selectFirst('front/article-meta/title-group/article-title')) {
                $locale = $this->getLocale($node->getAttribute('xml:lang'));
                $value = $this->selectText('.', $this->clearXref($node));
                $hasTitle = strlen($value);
                $publication->setData('title', $value, $locale);
            }

            // Set subtitle
            if ($node = $this->selectFirst('front/article-meta/title-group/subtitle')) {
                $publication->setData('subtitle', $this->selectText('.', $this->clearXref($node)), $this->getLocale($node->getAttribute('xml:lang')));
            }

            // Set localized title/subtitle
            foreach ($this->select('front/article-meta/title-group/trans-title-group') as $node) {
                $locale = $this->getLocale($node->getAttribute('xml:lang'));
                if ($value = $this->selectText('trans-title', $this->clearXref($node))) {
                    $hasTitle = true;
                    $publication->setData('title', $value, $locale);
                }
                if ($value = $this->selectText('trans-subtitle', $this->clearXref($node))) {
                    $publication->setData('subtitle', $value, $locale);
                }
            }

            if (!$hasTitle) {
                throw new Exception(__('plugins.importexport.articleImporter.articleTitleMissing'));
            }

            $publication->setData('language', LocaleConversion::getIso1FromLocale($this->getSubmission()->getData('locale')));

            // Set abstract
            foreach ($this->select('front/article-meta/abstract|front/article-meta/trans-abstract') as $node) {
                $value = trim($this->getTextContent($node, function ($node, $content) {
                    // Transforms the known tags, the remaining ones will be stripped
                    $tag = [
                        'title' => 'strong',
                        'italic' => 'em',
                        'sub' => 'sub',
                        'sup' => 'sup',
                        'p' => 'p'
                    ][$node->nodeName] ?? null;
                    return $tag ? "<{$tag}>{$content}</{$tag}>" : $content;
                }));
                if ($value) {
                    $publication->setData('abstract', $value, $this->getLocale($node->getAttribute('xml:lang')));
                }
            }

        // Set public IDs
        $pubIdPlugins = false;
        foreach ($this->getPublicIds() as $type => $value) {
        if ($type === 'doi') {
         $doiFound = Repo::doi()->getCollector()->filterByIdentifier($value)->getMany()->first();
                 if ($doiFound) {
                     $publication->setData('doiId', $doiFound->getId());
                 } else {
                     $newDoiObject = Repo::doi()->newDataObject([
                         'doi' => $value,
                         'contextId' => $this->getSubmission()->getData('contextId')
                     ]);
                     $doiId = Repo::doi()->add($newDoiObject);
                     $publication->setData('doiId', $doiId);
        }
            } else {
        if ($type !== 'publisher-id' && !$pubIdPlugins) {
                    $pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $this->getContextId());
                }
                $publication->setData('pub-id::' . $type, $value);
        }
        }

            // Set copyright year and holder and license permissions
            $publication->setData('copyrightHolder', $this->selectText('front/article-meta/permissions/copyright-holder'), $this->getLocale());
            $publication->setData('copyrightNotice', $this->selectText('front/article-meta/permissions/copyright-statement'), $this->getLocale());
            $publication->setData('copyrightYear', $this->selectText('front/article-meta/permissions/copyright-year') ?: $publicationDate->format('Y'));
            $publication->setData('licenseUrl', $this->selectText('front/article-meta/permissions/license/attribute::xlink:href'));

            $publication = $this->_processCitations($publication);
            $this->_setCoverImage($publication);
            $this->_processCategories($publication);

        // Inserts the publication and updates the submission
    Repo::publication()->dao->insert($publication);
    $submission = $this->getSubmission();
    $submission->setData('currentPublicationId', $publication->getId());
    Repo::submission()->edit($submission, []);

            $this->_processKeywords($publication);
            $this->_processAuthors($publication);

            // Process full text and generate HTML files
            $this->_processFullText($publication, $version, true);

            // Handle PDF galley
            $this->_insertPDFGalley($publication, $version);

            // Record this XML itself
            $this->_insertXMLSubmissionFile($publication, $version);
            $this->_insertHTMLGalley($publication, $version);
            $this->_insertSupplementaryGalleys($publication, $version);

            $publication = Repo::publication()->get($publication->getId());

            // Publishes the article
            Repo::publication()->publish($publication);

            $latestPublication = $publication;
        }

        return $latestPublication;
    }

    /**
     * Inserts citations
     */
    private function _processCitations(Publication $publication): Publication
    {
        $citationText = '';
        foreach ($this->select('/article/back/ref-list/ref') as $citation) {
            $data = $citation->ownerDocument->saveHTML($citation);
            if (strlen($data)) {
                $document = new DOMDocument('1.0', 'utf-8');
                $document->preserveWhiteSpace = false;
                $document->loadXML($data);
                $document->documentElement->normalize();
                $data = $document->documentElement->textContent . "\n";
            } else {
                $data = trim($citation->textContent);
            }
            if (!$data) {
                continue;
            }
            $citationText .= $data . "\n";
        }
        if ($citationText) {
            $publication->setData('citationsRaw', $citationText);
        }
        return $publication;
    }

    /**
     * Inserts the XML as a JATS file
     */
    private function _insertXMLSubmissionFile(Publication $publication, string $version): void
    {
        $file = $this->getArticleEntry()->getMetadataFile($version);
        $filename = $file->getPathname();

        $genreId = Genre::GENRE_CATEGORY_DOCUMENT;
        $fileStage = SubmissionFile::SUBMISSION_FILE_JATS;
        $userId = $this->getConfiguration()->getUser()->getId();

        $submission = $this->getSubmission();

        /** @var PKPFileService $fileService */
        $fileService = Services::get('file');

        $submissionDir = Repo::submissionFile()->getSubmissionDir($submission->getData('contextId'), $submission->getId());
        $newFileId = $fileService->add(
            $filename,
            $submissionDir . '/' . uniqid() . '.xml'
        );

        $newSubmissionFile = Repo::submissionFile()->dao->newDataObject();
        $newSubmissionFile->setData('submissionId', $submission->getId());
        $newSubmissionFile->setData('fileId', $newFileId);
        $newSubmissionFile->setData('genreId', $genreId);
        $newSubmissionFile->setData('fileStage', $fileStage);
        $newSubmissionFile->setData('uploaderUserId', $this->getConfiguration()->getEditor()->getId());
        $newSubmissionFile->setData('createdAt', Core::getCurrentDate());
        $newSubmissionFile->setData('updatedAt', Core::getCurrentDate());
        $newSubmissionFile->setData('name', $file->getFilename(), $this->getLocale());

        $submissionFileId = Repo::submissionFile()->add($newSubmissionFile);

        foreach ($this->select('//asset|//graphic') as $asset) {
            $assetFilename = mb_strtolower($asset->getAttribute($asset->nodeName == 'path' ? 'href' : 'xlink:href'));
            $dependentFilePath = dirname($filename) . DIRECTORY_SEPARATOR . $assetFilename;
            if (file_exists($dependentFilePath)) {
                $this->_createDependentFile($submission, $userId, $submissionFileId, $dependentFilePath);
            }
        }
    }

    /**
     * Creates a dependent file
     */
    protected function _createDependentFile(Submission $submission, int $userId, int $submissionFileId, string $filePath)
    {
        $filename = basename($filePath);
        $fileType = pathinfo($filePath, PATHINFO_EXTENSION);
        $genreId = $this->_getGenreId($this->getContextId(), $fileType);
        /** @var PKPFileService $fileService */
        $fileService = Services::get('file');

        $submissionDir = Repo::submissionFile()->getSubmissionDir($submission->getData('contextId'), $submission->getId());
        $newFileId = $fileService->add($filePath, $submissionDir . '/' . uniqid() . '.' . $fileType);

        $newSubmissionFile = Repo::submissionFile()->dao->newDataObject();
        $newSubmissionFile->setData('submissionId', $submission->getId());
        $newSubmissionFile->setData('fileId', $newFileId);
        $newSubmissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_DEPENDENT);
        $newSubmissionFile->setData('genreId', $genreId);
        $newSubmissionFile->setData('createdAt', Core::getCurrentDate());
        $newSubmissionFile->setData('updatedAt', Core::getCurrentDate());
        $newSubmissionFile->setData('uploaderUserId', $userId);
        $newSubmissionFile->setData('assocType', Application::ASSOC_TYPE_SUBMISSION_FILE);
        $newSubmissionFile->setData('assocId', $submissionFileId);
        $newSubmissionFile->setData('name', $filename, $this->getLocale());
        // Expect properties to be stored as empty for artwork metadata
        $newSubmissionFile->setData('caption', '');
        $newSubmissionFile->setData('credit', '');
        $newSubmissionFile->setData('copyrightOwner', '');
        $newSubmissionFile->setData('terms', '');

        Repo::submissionFile()->add($newSubmissionFile);
    }

    /**
     * Inserts the PDF galley
     */
    private function _insertPDFGalley(Publication $publication, string $version): void
    {
        $file = $this->getArticleEntry()->getSubmissionFile($version);
        if (!$file) {
            return;
        }
        $filename = $file->getFilename();

        // Create a galley for the article
        $newGalley = Repo::galley()->dao->newDataObject();
        $newGalley->setData('publicationId', $publication->getId());
        $newGalley->setData('name', $filename, $this->getLocale());
        $newGalley->setData('seq', 1);
        $newGalley->setData('label', 'PDF');
        $newGalley->setData('locale', $this->getLocale());
        $newGalleyId = Repo::galley()->add($newGalley);

        // Add the PDF file and link galley with submission file
        /** @var PKPFileService $fileService */
        $fileService = Services::get('file');
        $submission = $this->getSubmission();

        $submissionDir = Repo::submissionFile()->getSubmissionDir($submission->getData('contextId'), $submission->getId());
        $newFileId = $fileService->add($file->getPathname(), $submissionDir . '/' . uniqid() . '.pdf');

        $newSubmissionFile = Repo::submissionFile()->dao->newDataObject();
        $newSubmissionFile->setData('submissionId', $submission->getId());
        $newSubmissionFile->setData('fileId', $newFileId);
        $newSubmissionFile->setData('genreId', $this->getConfiguration()->getSubmissionGenre()->getId());
        $newSubmissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_PROOF);
        $newSubmissionFile->setData('uploaderUserId', $this->getConfiguration()->getEditor()->getId());
        $newSubmissionFile->setData('createdAt', Core::getCurrentDate());
        $newSubmissionFile->setData('updatedAt', Core::getCurrentDate());
        $newSubmissionFile->setData('assocType', Application::ASSOC_TYPE_REPRESENTATION);
        $newSubmissionFile->setData('assocId', $newGalleyId);
        $newSubmissionFile->setData('name', $filename, $this->getLocale());
        $submissionFileId = Repo::submissionFile()->add($newSubmissionFile);

        $galley = Repo::galley()->get($newGalleyId);
        $galley->setData('submissionFileId', $submissionFileId);
        Repo::galley()->edit($galley, []);
    }

    /**
     * Inserts the supplementary galleys
     */
    private function _insertSupplementaryGalleys(Publication $publication, string $version): void
    {
        $htmlFiles = count($this->getArticleEntry()->getHtmlFiles($version));
        $files = $this->getArticleEntry()->getSupplementaryFiles($version);
        /** @var SplFileInfo */
        foreach ($files as $i => $file) {
            // Create a galley for the article
            $newGalley = Repo::galley()->dao->newDataObject();
            $newGalley->setData('publicationId', $publication->getId());
            $newGalley->setData('name', $file->getBasename(), $this->getLocale());
            $newGalley->setData('seq', 2 + $htmlFiles + $i);
            $newGalley->setData('label', 'Supplement' . (count($files) > 1 ? ' ' . ($i + 1) : ''));
            $newGalley->setData('locale', $this->getLocale());
            $newGalleyId = Repo::galley()->add($newGalley);

            $submission = $this->getSubmission();
            /** @var PKPFileService $fileService */
            $fileService = Services::get('file');
            $submissionDir = Repo::submissionFile()->getSubmissionDir($submission->getData('contextId'), $submission->getId());
            $newFileId = $fileService->add($file->getPathname(), $submissionDir . '/' . uniqid() . '.' . $file->getExtension());

            $newSubmissionFile = Repo::submissionFile()->newDataObject();
            $newSubmissionFile->setData('submissionId', $submission->getId());
            $newSubmissionFile->setData('fileId', $newFileId);
            $newSubmissionFile->setData('genreId', $this->getConfiguration()->getSubmissionGenre()->getId());
            $newSubmissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_PROOF);
            $newSubmissionFile->setData('uploaderUserId', $this->getConfiguration()->getEditor()->getId());
            $newSubmissionFile->setData('createdAt', Core::getCurrentDate());
            $newSubmissionFile->setData('updatedAt', Core::getCurrentDate());
            $newSubmissionFile->setData('assocType', Application::ASSOC_TYPE_REPRESENTATION);
            $newSubmissionFile->setData('assocId', $newGalleyId);
            $newSubmissionFile->setData('name', $file->getBasename(), $this->getLocale());

            $submissionFileId = Repo::submissionFile()->add($newSubmissionFile);

            $galley = Repo::galley()->get($newGalleyId);
            $galley->setData('submissionFileId', $submissionFileId);
            Repo::galley()->edit($galley, []);
        }
    }

    /**
     * Retrieves the public IDs
     *
     * @return array Returns array, where the key is the type and value the ID
     */
    public function getPublicIds(): array
    {
        $ids = [];
        foreach ($this->select('front/article-meta/article-id') as $node) {
            $ids[strtolower($node->getAttribute('pub-id-type'))] = $this->selectText('.', $node);
        }
        return $ids;
    }

    /**
     * Retrieves the publication date
     */
    public function getPublicationDate(): DateTimeImmutable
    {
        $node = null;
        // Find the most suitable pub-date node
        foreach ($this->select('front/article-meta/pub-date') as $node) {
            if (in_array($node->getAttribute('pub-type'), ['given-online-pub', 'epub']) || $node->getAttribute('publication-format') == 'electronic') {
                break;
            }
        }
        if (!$date = $this->getDateFromNode($node)) {
            throw new Exception(__('plugins.importexport.articleImporter.missingPublicationDate'));
        }
        return $date;
    }

    private function _setCoverImage(Publication $publication): void
    {
        $pfm = new TemporaryFileManager();
        $filename = $this->getArticleEntry()->getSubmissionCoverFile();
        if (!$filename) {
            return;
        }
        // Get the file extension, then rename the file.
        $fileExtension = $pfm->parseFileExtension($filename->getBasename());

        if (!$pfm->fileExists($pfm->getBasePath(), 'dir')) {
            // Try to create destination directory
            $pfm->mkdirtree($pfm->getBasePath());
        }

        $newFileName = basename(tempnam($pfm->getBasePath(), $fileExtension));
        if (!$newFileName) return;

        $pfm->copyFile($filename, $pfm->getBasePath() . "/{$newFileName}");
        /** @var TemporaryFileDAO */
        $temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO');
        /** @var TemporaryFile */
        $temporaryFile = $temporaryFileDao->newDataObject();

        $temporaryFile->setUserId(Application::get()->getRequest()->getUser()->getId());
        $temporaryFile->setServerFileName($newFileName);
        $temporaryFile->setFileType(PKPString::mime_content_type($pfm->getBasePath() . "/$newFileName", $fileExtension));
        $temporaryFile->setFileSize($filename->getSize());
        $temporaryFile->setOriginalFileName($pfm->truncateFileName($filename->getBasename(), 127));
        $temporaryFile->setDateUploaded(Core::getCurrentDate());

        $temporaryFileDao->insertObject($temporaryFile);

        $publication->setData('coverImage', [
            'dateUploaded' => (new DateTime())->format('Y-m-d H:i:s'),
            'uploadName' => $temporaryFile->getOriginalFileName(),
            'temporaryFileId' => $temporaryFile->getId(),
            'altText' => 'Publication image'
        ], $this->getLocale());
    }

    /**
     * Inserts the HTML as a production ready file
     */
    private function _insertHTMLGalley(Publication $publication, string $version): void
    {
        /** @var SplFileInfo */
        foreach ($this->getArticleEntry()->getHtmlFiles($version) as $i => $file) {
            $pieces = explode('.', $file->getBasename(".{$file->getExtension()}"));
            $lang = end($pieces);
            // Create a galley of the article (i.e. a galley)
            $newGalley = Repo::galley()->dao->newDataObject();
            $newGalley->setData('publicationId', $publication->getId());
            $newGalley->setData('name', $file->getBasename(), $this->getLocale($lang));
            $newGalley->setData('seq', 2 + $i);
            $newGalley->setData('label', 'HTML');
            $newGalley->setData('locale', $this->getLocale($lang));
            $newGalleyId = Repo::galley()->add($newGalley);

            $userId = $this->getConfiguration()->getUser()->getId();

            $submission = $this->getSubmission();

            /** @var PKPFileService $fileService */
            $fileService = Services::get('file');

            $content = str_replace('src="graphic/', 'src="', file_get_contents($file->getPathname()));
            if (preg_match_all('/src="([^"]*)"/', $content, $matches)) {
                foreach ($matches[1] as $path) {
                    $path = urldecode($path);
                    if ($path[0] === '/') {
                        continue;
                    }
                    $realPath = $file->getPath() . "/graphic/{$path}";
                    $extension = pathinfo($realPath, PATHINFO_EXTENSION);
                    if (strtolower(substr($extension, 0, 3)) === 'tif') {
                        $newFilename = basename($path, ".{$extension}") . '.jpg';
                        $content = str_replace($path, $newFilename, $content);
                        if (!file_exists($realPath = $file->getPath() . '/graphic/' .  $newFilename)) {
                            throw new Exception("Convert {$realPath} from {$extension} to jpg");
                        }
                    } else if (!file_exists($realPath)) {
                        throw new Exception("Missing file {$realPath}");
                    }
                }
            }
            $content = preg_replace_callback('/href="([^"]*)"/', function ($href) use ($content) {
                if (filter_var($href[1], FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
                    $href[0] = str_replace($href[1], "mailto:{$href[1]}", $href[0]);
                }
                if ($href[1][0] === '#' && is_bool(strpos($content, 'id="' . substr($href[1], 1) . '"'))) {
                    echo "ID not found: {$href[1]}\n";
                }
                return $href[0];
            }, $content);

            $filename = tempnam(sys_get_temp_dir(), 'tmp');
            file_put_contents($filename, $content);

            $submissionDir = Repo::submissionFile()->getSubmissionDir($submission->getData('contextId'), $submission->getId());
            $newFileId = $fileService->add($filename, $submissionDir . '/' . uniqid() . '.html');
            unlink($filename);

            $newSubmissionFile = Repo::submissionFile()->dao->newDataObject();
            $newSubmissionFile->setData('submissionId', $submission->getId());
            $newSubmissionFile->setData('fileId', $newFileId);
            $newSubmissionFile->setData('genreId', $this->getConfiguration()->getSubmissionGenre()->getId());
            $newSubmissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_PROOF);
            $newSubmissionFile->setData('uploaderUserId', $this->getConfiguration()->getEditor()->getId());
            $newSubmissionFile->setData('createdAt', Core::getCurrentDate());
            $newSubmissionFile->setData('updatedAt', Core::getCurrentDate());
            $newSubmissionFile->setData('assocType', Application::ASSOC_TYPE_REPRESENTATION);
            $newSubmissionFile->setData('assocId', $newGalleyId);
            $newSubmissionFile->setData('name', $file->getBasename(), $this->getLocale($lang));

            $submissionFileId = Repo::submissionFile()->add($newSubmissionFile);

            /** @var SplFileInfo */
            foreach (is_dir($file->getPath() . '/graphic') ? new FilesystemIterator($file->getPath() . '/graphic') : [] as $assetFilename) {
                if ($assetFilename->isDir()) {
                    throw new Exception("Unexpected directory {$assetFilename}");
                }
                $this->_createDependentFile($submission, $userId, $submissionFileId, $assetFilename);
            }

            $galley = Repo::galley()->get($newGalleyId);
            $galley->setData('submissionFileId', $submissionFileId);
            Repo::galley()->edit($galley, []);
        }

        // Reload files to include the generated HTML files
        $this->getArticleEntry()->reloadFiles($version);
    }

    /**
     * Process the article keywords
     */
    private function _processKeywords(Publication $publication): void
    {
        $keywords = [];
        foreach ($this->select('front/article-meta/kwd-group') as $node) {
            $locale = $this->getLocale($node->getAttribute('xml:lang'));
            foreach ($this->select('kwd', $node) as $node) {
                $keywords[$locale][] = $this->selectText('.', $node);
            }
        }
        if (count($keywords)) {
            Repo::controlledVocab()->insertBySymbolic(ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD, $keywords, Application::ASSOC_TYPE_PUBLICATION, $publication->getId());
        }
    }

    /**
     * Process the categories
     */
    private function _processCategories(Publication $publication): void
    {
        static $cache = [];
        $categoryIds = [];
        foreach ($this->select('front/article-meta/article-categories/subj-group') as $node) {
            $locale = $this->getLocale();
            $name = $this->selectText('subject', $node);
            $locale = $this->getLocale($node->getAttribute('xml:lang'));

            // CUSTOM: Category names might have two languages, splitted by "/", where the second is "en_US"
            $name = preg_split('@\s*/\s*@', $name, 2);
            $names = [$locale => reset($name)];
            if (count($name) > 1) {
                $names['en_US'] = end($name);
            }

            // Tries to find an entry in the cache
            foreach ($names as $locale => $name) {
                if ($category = $cache[$this->getContextId()][$locale][$name] ?? null) {
                    break;
                }
            }

            $path = Stringy::create(reset($names))->toLowerCase()->dasherize()->regexReplace('[^a-z0-9\-\_.]', '') . '-' . substr($locale, 0, 2);
            if (!$category) {
                // Tries to find an entry in the database
                foreach ($names as $locale => $name) {
                    if ($category = Repo::category()->getCollector()->filterByPaths([$path])->filterByContextIds([$this->getContextId()])->getMany()->first()) {
                        break;
                    }
                }
            }

            if (!$category) {
                // Creates a new category
                $category = Repo::category()->newDataObject();
                $category->setData('contextId', $this->getContextId());
                foreach ($names as $locale => $name) {
                    $category->setData('title', $name, $locale);
                }
                $category->setData('parentId', null);
                $category->setData('path', $path);
                $category->setData('sortOption', 'datePublished-2');

                $categoryId = Repo::category()->add($category);
                $category->setId($categoryId);
            }

            // Caches the entry
            foreach ($names as $locale => $name) {
                $cache[$this->getContextId()][$locale][$name] = $category;
            }
            $categoryIds[] = $category->getId();
        }
        $publication->setData('categoryIds', $categoryIds);
    }

    /**
     * Process the full text and generate HTML files
     */
    private function _processFullText(Publication $publication, string $version, bool $overwrite = false): void
    {
        static $xslt;

        if (!$this->selectFirst('/article/body') || (!$overwrite && count($this->getArticleEntry()->getHtmlFiles($version)))) {
            return;
        }

        libxml_use_internal_errors(true);
        if (!$xslt) {
            $document = new DOMDocument('1.0', 'utf-8');
            $document->load(__DIR__ . '/xslt/main/jats-html.xsl');
            $xslt = new XSLTProcessor();
            $xslt->registerPHPFunctions();
            $xslt->importStyleSheet($document);
        }

        $metadata = $this->getArticleEntry()->getMetadataFile($version);
        $xml = new DOMDocument('1.0', 'utf-8');
        $xml->load($metadata);
        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');
        $defaultLang = $xml->documentElement->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang') ?: $this->getLocale();
        $langs = [$defaultLang => 0];
        foreach ($this->select("body//sec[@xml:lang!='{$defaultLang}']", null, $xpath) as $sec) {
            $langs[$sec->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang')] = 0;
        }
        foreach (array_keys($langs) as $lang) {
            $xml = new DOMDocument('1.0', 'utf-8');
            $xml->load($metadata);
            $xpath = new DOMXPath($xml);
            $xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

            foreach ($this->select('//contrib-group/contrib', null, $xpath) as $contribNode) {
                $affiliations = [];
                $xrefs = [];
                /** @var DOMElement */
                foreach ($contribNode->getElementsByTagName('xref') as $xref) {
                    $id = $xref->getAttribute('rid');
                    switch ($xref->getAttribute('ref-type')) {
                        case 'fn':
                            /** @var DOMElement $node */
                            if ($node = $this->selectFirst("//back/fn-group/fn[@id='{$id}']", null, $xpath)) {
                                $node = $node->cloneNode(true);
                                /** @var DOMElement */
                                foreach (iterator_to_array($node->getElementsByTagName('label')) as $label) {
                                    $label->parentNode->removeChild($label);
                                }
                                $this->fixJatsTags($node);
                                $bioNode = $node->ownerDocument->createElement('bio');
                                foreach (iterator_to_array($node->childNodes) as $childNode) {
                                    $bioNode->appendChild($childNode);
                                }
                                $xrefs[] = $xref;
                                $contribNode->appendChild($bioNode);
                            }
                            break;

                        case 'aff':
                            if ($affiliation = preg_replace(['/\r\n|\n\r|\r|\n/', '/\s{2,}/', '/\s+([,.])/'], [' ', ' ', '$1'], trim($this->selectText("../../aff[@id='{$id}']", $xref, $xpath)))) {
                                $affiliations[] = $affiliation;
                            }
                            $xrefs[] = $xref;
                            break;
                    }
                }
                if (count($affiliations)) {
                    $node = $contribNode->ownerDocument->createElement('aff', implode('; ', $affiliations));
                    $contribNode->appendChild($node);
                }
                foreach($xrefs as $xref) {
                    $xref->parentNode->removeChild($xref);
                }
            }

            if (count($langs) > 1) {
                $filter = "body//sec[@xml:lang!='{$lang}']";
                if ($defaultLang !== $lang) {
                    $filter .= " | body//sec[not(@xml:lang)]";
                }
                $isSharedFnGroup = count($this->select('back/fn-group', null, $xpath)) !== count($langs);
                if (!$isSharedFnGroup) {
                    $filter .= " | back/fn-group[@xml:lang!='{$lang}']";
                    if ($defaultLang !== $lang) {
                        $filter .= " | back/fn-group[not(@xml:lang)]";
                    }
                }
                foreach (iterator_to_array($this->select($filter, null, $xpath)) as $node) {
                    $node->parentNode->removeChild($node);
                }
            }

            /** @var DOMElement */
            foreach (iterator_to_array($this->select('body//sec//label', null, $xpath)) as $label) {
                for($title = $label; ($title = $title->nextSibling) && $title->nodeType !== XML_ELEMENT_NODE;);
                /** @var DOMElement $title */
                if ($title && $title->tagName === 'title') {
                    $title->insertBefore($title->ownerDocument->createTextNode($label->textContent . ' '), $title->firstChild);
                    $label->parentNode->removeChild($label);
                }
            }
            $switch = function (DOMElement $main, DOMElement $translation): void {
                $namespace = 'http://www.w3.org/XML/1998/namespace';
                $mainNodes = iterator_to_array($main->childNodes);
                $translationNodes = iterator_to_array($translation->childNodes);
                foreach ($mainNodes as $node) {
                    $translation->appendChild($node);
                }
                foreach ($translationNodes as $node) {
                    $main->appendChild($node);
                }

                $translationLang = $translation->parentNode->getAttributeNS($namespace, 'lang');
                $translation->parentNode->setAttributeNS($namespace, 'lang', $main->getAttributeNS($namespace, 'lang'));
                $main->setAttributeNS($namespace, 'lang', $translationLang);
            };
            /** @var DOMElement */
            if (
                ($title = $this->selectFirst("/article/front/article-meta/title-group/article-title[@xml:lang!='{$lang}']", null, $xpath))
                && ($translatedTitle = $this->selectFirst("/article/front/article-meta/title-group/trans-title-group[@xml:lang='{$lang}']/trans-title", null, $xpath))
            ) {
                $switch($title, $translatedTitle);
            }
            if (
                ($title = $this->selectFirst("/article/front/article-meta/title-group/subtitle[@xml:lang!='{$lang}']", null, $xpath))
                && ($translatedTitle = $this->selectFirst("/article/front/article-meta/title-group/trans-title-group[@xml:lang='{$lang}']/trans-subtitle", null, $xpath))
            ) {
                $switch($title, $translatedTitle);
            }
            $xml->documentElement->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang', $lang);

            /** @var DOMElement */
            foreach (iterator_to_array($this->select("//xref", null, $xpath)) as $xref) {
                if (!$this->selectFirst("//[@id='" . $xref->getAttribute('rid') . "']", null, $xpath)) {
                    echo "Dropped invalid xref to: " . $xref->getAttribute('rid') . "\n";
                    $xref->parentNode->removeChild($xref);
                }
            }

            $output = $xslt->transformToXML($xml);

            if ($output === false) {
                throw new Exception("Failed to create HTML file from JATS XML: \n" . print_r(libxml_get_errors(), true));
            }
            $path = $metadata->getPathInfo() . '/' . $metadata->getBasename($metadata->getExtension()) . (count($langs) > 1 ? "{$lang}." : '') . 'html';

            file_put_contents($path, $output);
            $this->getArticleEntry()->reloadFiles($version);
        }
    }
}
