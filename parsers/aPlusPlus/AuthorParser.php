<?php
/**
 * @file parsers/aPlusPlus/AuthorParser.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2000-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AuthorParser
 * @brief Handles parsing and importing the authors
 */

namespace APP\plugins\importexport\articleImporter\parsers\aPlusPlus;

use APP\db\DAORegistry;
use APP\publication\Publication;
use APP\author\Author;
use APP\facades\Repo;

trait AuthorParser
{
    /** @var int Keeps the count of inserted authors */
    private $_authorCount = 0;

    /**
     * Processes all the authors
     */
    private function _processAuthors(Publication $publication): void
    {
        $firstAuthor = null;
        foreach ($this->select('Journal/Volume/Issue/Article/ArticleHeader/AuthorGroup/Author') as $node) {
            $author = $this->_processAuthor($publication, $node);
            $firstAuthor ?? $firstAuthor = $author;
        }
        // If there's no authors, create a default author
        $firstAuthor ?? $firstAuthor = $this->_createDefaultAuthor($publication);
        $publication->setData('primaryContactId', $firstAuthor->getId());
    }

    /**
     * Handles an author node
     */
    private function _processAuthor(Publication $publication, \DOMNode $authorNode): Author
    {
        $node = $this->selectFirst('AuthorName', $authorNode);

        $firstName = [];
        foreach ($this->select('GivenName', $node) as $name) {
            $firstName[] = $this->selectText('.', $name);
        }
        $firstName = implode(' ', $firstName);
        $lastName = implode(' ', array_filter([$this->selectText('Particle', $node), $this->selectText('FamilyName', $node)], 'strlen'));
        if ($lastName && !$firstName) {
            $firstName = $lastName;
            $lastName = '';
        } elseif (!$lastName && !$firstName) {
            $firstName = $this->getConfiguration()->getContext()->getName($this->getLocale());
        }

        // Try to retrieve the affiliation name
        $affiliation = null;
        $ids = explode(' ', $authorNode->getAttribute('AffiliationIDS'));
        if ($affiliationId = $authorNode->getAttribute('CorrespondingAffiliationID') ?: reset($ids)) {
            $affiliation = $this->selectText("Journal/Volume/Issue/Article/ArticleHeader/AuthorGroup/Affiliation[@ID='${affiliationId}']/OrgName");
        }

        $author = Repo::author()->dao->newDataObject();
        $author->setData('givenName', $firstName, $this->getLocale());
        if ($lastName) {
            $author->setData('familyName', $lastName, $this->getLocale());
        }
        //$author->setData('preferredPublicName', "", $this->getLocale());
        $author->setData('email', $this->selectText('Contact/Email', $authorNode) ?: $this->getConfiguration()->getEmail());
        $author->setData('url', $this->selectText('Contact/URL', $authorNode));
        $author->setData('affiliation', $affiliation, $this->getLocale());
        $author->setData('seq', $this->_authorCount + 1);
        $author->setData('publicationId', $publication->getId());
        $author->setData('includeInBrowse', true);
        $author->setData('primaryContact', !$this->_authorCount);
        $author->setData('userGroupId', $this->getConfiguration()->getAuthorGroupId());

        Repo::author()->add($author);
        ++$this->_authorCount;
        return $author;
    }
}
