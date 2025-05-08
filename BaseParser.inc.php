<?php
/**
 * @file plugins/importexport/articleImporter/BaseParser.inc.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BaseParser
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief Base class that parsers should extend
 */

namespace PKP\Plugins\ImportExport\ArticleImporter;

use Application;
use Author;
use AuthorDAO;
use DAORegistry;
use DOMDocument;
use DOMDocumentType;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Exception;
use GenreDAO;
use Issue;
use IssueDAO;
use PKP\Plugins\ImportExport\ArticleImporter\Exceptions\AlreadyExistsException;
use PKP\Plugins\ImportExport\ArticleImporter\Exceptions\InvalidDocTypeException;
use PKPLocale;
use Publication;
use PublicFileManager;
use Section;
use Submission;
use SubmissionDAO;
use XSLTProcessor;

abstract class BaseParser
{
    /** @var Configuration Configuration */
    private $_configuration;
    /** @var ArticleEntry Article entry */
    private $_entry;
    /** @var \DOMDocument The DOMDocument instance for the XML metadata */
    private $_document;
    /** @var \DOMXPath The DOMXPath instance for the XML metadata */
    private $_xpath;
    /** @var int Context ID */
    private $_contextId;
    /** @var string Default locale, grabbed from the submission or the context's primary locale */
    private $_locale;
    /** @var int[] cache of genres by context id and extension */
    private $_cachedGenres;
    /** @var string[] */
    private $_usedLocales = [];

    /**
     * Constructor
     */
    public function __construct(Configuration $configuration, ArticleEntry $entry)
    {
        $this->_configuration = $configuration;
        $this->_entry = $entry;
        $context = $this->_configuration->getContext();
        $this->_contextId = $context->getId();
        $this->_locale = $context->getPrimaryLocale();
    }

    /**
     * Parses the publication
     */
    abstract public function getPublication(): \Publication;

    /**
     * Parses the issue
     */
    abstract public function getIssue(): \Issue;

    /**
     * Parses the submission
     */
    abstract public function getSubmission(): \Submission;

    /**
     * Parses the section
     */
    abstract public function getSection(): \Section;

    /**
     * Retrieves the public IDs
     *
     * @return array Returns array, where the key is the type and value the ID
     */
    abstract public function getPublicIds(): array;

    /**
     * Rollbacks the process
     */
    abstract public function rollback(): void;

    /**
     * Retrieves the DOCTYPE
     *
     * @return array \DOMDocumentType[]
     */
    abstract public function getDocType(): array;

    /**
     * Executes the parser
     *
     * @throws \Exception Throws when something goes wrong, and an attempt to revert the actions will be performed
     */
    public function execute(): void
    {
        try {
            $this
                ->_ensureMetadataIsValidAndParse()
                ->_ensureSubmissionDoesNotExist()
                ->_processFullText(true)
                ->getPublication();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    private function _processFullText(bool $overwrite = false): static
    {
        static $xslt;

        if (!$this->selectFirst('/article/body') || (!$overwrite && count($this->getArticleEntry()->getHtmlFiles()))) {
            return $this;
        }
        libxml_use_internal_errors(true);
        if (!$xslt) {
            $document = new DOMDocument('1.0', 'utf-8');
            $document->load(__DIR__ . '/jats/xslt/main/jats-html.xsl');
            $xslt = new XSLTProcessor();
            $xslt->registerPHPFunctions();
            $xslt->importStyleSheet($document);
        }

        $metadata = $this->getArticleEntry()->getMetadataFile();
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
            $this->getArticleEntry()->reloadFiles();
        }

        //exit;
        return $this;
    }

    /**
     * Validates the metadata file and try to parse the XML
     *
     * @throws \Exception Throws when there's an error to parse the XML
     *
     * @return Parser
     */
    private function _ensureMetadataIsValidAndParse(): self
    {
        // Tries to parse the XML
        $this->_document = new \DOMDocument();
        if (!$this->_document->load($this->getArticleEntry()->getMetadataFile()->getPathname())) {
            throw new \Exception(__('plugins.importexport.jats.failedToParseXMLDocument'));
        }

        // Checks whether the loaded document is supported by the parser (the doctype should match)
        $docType = $this->_document->doctype;
        $supportedDocTypes = $this->getDocType();
        $found = false;
        foreach ($supportedDocTypes as $supportedDocType) {
            if ([$docType->systemId, $docType->publicId, $docType->name] == [$supportedDocType->systemId, $supportedDocType->publicId, $supportedDocType->name]) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new InvalidDocTypeException(__('plugins.importexport.articleImporter.invalidDoctype'));
        }

        $this->_xpath = new \DOMXPath($this->_document);
        $this->_xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');
        $this->_locale = $this->getLocale($this->selectText('@xml:lang'));
        return $this;
    }

    /**
     * Evaluates and retrieves the given XPath expression
     *
     * @param string $path XPath expression
     * @param \DOMNode $context Optional context node
     */
    public function evaluate(string $path, ?DOMNode $context = null, DOMXPath $xpath = null)
    {
        return ($xpath ?? $this->_xpath)->evaluate($path, $context);
    }

    /**
     * Evaluates and retrieves the given XPath expression as a trimmed and tag stripped string
     * The path is expected to target a single node
     *
     * @param string $path XPath expression
     * @param DOMNode $context Optional context node
     */
    public function selectText(string $path, ?DOMNode $context = null, DOMXPath $xpath = null): string
    {
        return strip_tags(trim($this->evaluate("string({$path})", $context, $xpath)));
    }

    /**
     * Retrieves the nodes that match the given XPath expression
     *
     * @param string $path XPath expression
     * @param DOMNode $context Optional context node
     */
    public function select(string $path, ?DOMNode $context = null, DOMXPath $xpath = null): DOMNodeList
    {
        return ($xpath ?? $this->_xpath)->query($path, $context);
    }

    /**
     * Query the given XPath expression and retrieves the first item
     *
     * @param string $path XPath expression
     * @param DOMNode $context Optional context node
     */
    public function selectFirst(string $path, ?DOMNode $context = null, DOMXPath $xpath = null): ?object
    {
        return $this->select($path, $context, $xpath)->item(0);
    }

    /**
     * Checks if the submission isn't already registered using the public IDs
     *
     * @throws \Exception Throws when a submission with the same public ID is found
     *
     * @return Parser
     */
    public function _ensureSubmissionDoesNotExist(): self
    {
        foreach ($this->getPublicIds() as $type => $id) {
            if (\Application::getSubmissionDAO()->getByPubId($type, $id, $this->getContextId())) {
                throw new AlreadyExistsException(__('plugins.importexport.articleImporter.alreadyExists', ['type' => $type, 'id' => $id]));
            }
        }
        return $this;
    }

    /**
     * Retrieves the context ID
     */
    public function getContextId(): int
    {
        return $this->_contextId;
    }

    /**
     * Tries to map the given locale to the PKP standard, returns the default locale if it fails or if the parameter is null
     *
     * @param string $locale
     */
    public function getLocale(?string $locale = null): string
    {
        if ($locale && !\PKPLocale::isLocaleValid($locale)) {
            $locale = strtolower($locale);
            // Tries to convert from recognized formats
            $iso3 = \PKPLocale::getIso3FromIso1($locale) ?: \PKPLocale::getIso3FromLocale($locale);
            // If the language part of the locale is the same (ex. fr_FR and fr_CA), then gives preference to context's locale
            $locale = $iso3 == \PKPLocale::getIso3FromLocale($this->_locale) ? $this->_locale : \PKPLocale::getLocaleFromIso3($iso3);
        }
        $locale = $locale ?: $this->_locale;
        return $this->_usedLocales[$locale] = $locale;
    }

    public function getUsedLocales(): array
    {
        return $this->_usedLocales;
    }

    /**
     * Retrieves the configuration instance
     */
    public function getConfiguration(): Configuration
    {
        return $this->_configuration;
    }

    /**
     * Retrieves the article entry instance
     */
    public function getArticleEntry(): ArticleEntry
    {
        return $this->_entry;
    }

    /**
     * Includes a section into the issue custom order
     */
    public function includeSection(\Section $section): void
    {
        static $cache = [];

        // If the section wasn't included into the issue yet
        if (!isset($cache[$this->getIssue()->getId()][$section->getId()])) {
            // Adds it to the list
            $cache[$this->getIssue()->getId()][$section->getId()] = true;
            $sectionDao = \Application::getSectionDAO();
            // Checks whether the section is already present in the issue
            if (!$sectionDao->getCustomSectionOrder($this->getIssue()->getId(), $section->getId())) {
                $sectionDao->insertCustomSectionOrder($this->getIssue()->getId(), $section->getId(), count($cache[$this->getIssue()->getId()]));
            }
        }
    }

    /**
     * Manually evaluates the textContent of the node with the help of a transformation callback
     *
     * @param callable $callback The callback will receive two arguments, the current node being parsed and the already transformed textContent of it
     */
    public function getTextContent(?\DOMNode $node, callable $callback)
    {
        if (!$node) {
            return null;
        }
        if ($node instanceof \DOMText) {
            return htmlspecialchars($node->textContent, ENT_HTML5 | ENT_NOQUOTES);
        }
        $data = '';
        foreach ($node->childNodes ?? [] as $child) {
            $data .= $this->getTextContent($child, $callback);
        }
        return $callback($node, $data);
    }

    /**
     * Looks in $issueFolder for a cover image, and applies it to $issue if found
     */
    public function setIssueCover(string $issueFolder, \Issue &$issue)
    {
        $issueDao = \DAORegistry::getDAO('IssueDAO');
        $issueCover = null;
        foreach ($this->getConfiguration()->getImageExtensions() as $ext) {
            $checkFile = $issueFolder . DIRECTORY_SEPARATOR . $this->getConfiguration()->getIssueCoverFilename() . '.' . $ext;
            if (file_exists($checkFile)) {
                $issueCover = $checkFile;
                break;
            }
        }
        if ($issueCover) {
            import('classes.file.PublicFileManager');
            $publicFileManager = new \PublicFileManager();
            $fileparts = explode('.', $issueCover);
            $ext = array_pop($fileparts);
            $newFileName = 'cover_issue_' . $issue->getId() . '_' . $this->getLocale() . '.' . $ext;
            $publicFileManager->copyContextFile($this->getContextId(), $issueCover, $newFileName);
            $issue->setCoverImage($newFileName, $this->getLocale());
            $issueDao->updateObject($issue);
        }
    }


    /**
     * Find a Genre ID for a context and extension
     *
     * @param $contextId
     * @param $extension
     *
     * @return int
     */
    protected function _getGenreId($contextId, $extension)
    {
        if (isset($this->_cachedGenres[$contextId][$extension])) {
            return $this->_cachedGenres[$contextId][$extension];
        }

        $genreDao = \DAORegistry::getDAO('GenreDAO');
        if (in_array($extension, $this->getConfiguration()->getImageExtensions())) {
            $genre = $genreDao->getByKey('IMAGE', $contextId);
            $genreId = $genre->getId();
        } else {
            $genre = $genreDao->getByKey('MULTIMEDIA', $contextId);
            $genreId = $genre->getId();
        }
        $this->_cachedGenres[$contextId][$extension] = $genreId;
        return $genreId;
    }

    /**
     * Creates a default author for articles with no authors
     */
    protected function _createDefaultAuthor(\Publication $publication): \Author
    {
        $authorDao = \DAORegistry::getDAO('AuthorDAO');
        $author = $authorDao->newDataObject();
        $author->setData('givenName', $this->getConfiguration()->getContext()->getName($this->getLocale()), $this->getLocale());
        $author->setData('seq', 1);
        $author->setData('publicationId', $publication->getId());
        $author->setData('email', $this->getConfiguration()->getEmail());
        $author->setData('includeInBrowse', true);
        $author->setData('primaryContact', true);
        $author->setData('userGroupId', $this->getConfiguration()->getAuthorGroupId());

        $authorDao->insertObject($author);
        return $author;
    }
}
