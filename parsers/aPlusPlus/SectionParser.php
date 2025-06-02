<?php
/**
 * @file parsers/aPlusPlus/SectionParser.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SectionParser
 * @brief Handles parsing and importing the sections
 */

namespace APP\plugins\importexport\articleImporter\parsers\aPlusPlus;

use APP\facades\Repo;
use APP\section\Section;

trait SectionParser
{
    /** @var bool True if the section was created by this instance */
    private bool $_isSectionOwner = false;

    /** @var Section Section instance */
    private Section $_section;

    /**
     * Rollbacks the operation
     */
    private function _rollbackSection(): void
    {
        if ($this->_isSectionOwner) {
            Repo::section()->delete($this->_section);
        }
    }

    /**
     * Parses and retrieves the section, if a section with the same name exists, it will be retrieved
     */
    public function getSection(): Section
    {
        static $cache = [];

        if ($this->_section) {
            return $this->_section;
        }

        $sectionName = null;
        $locale = $this->getLocale();

        if ($this->getConfiguration()->canUseCategoryAsSection()) {
            // Retrieves the section name and locale
            $node = $this->selectFirst('Journal/Volume/Issue/Article/ArticleInfo/ArticleCategory');
            if ($node) {
                $sectionName = ucwords(strtolower($this->selectText('.', $node)));
                $locale = $this->getLocale($node->getAttribute('Language'));
            }
        }

        $sectionName = $sectionName ?: $this->getIssueMeta()['section'] ?: $this->getConfiguration()->getDefaultSectionName();
        // Tries to find an entry in the cache
        $this->_section = $cache[$this->getContextId()][$locale][$sectionName] ?? null;

        if (!$this->_section) {
            // Tries to find an entry in the database
            $this->_section = Repo::section()->getCollector()->filterByTitles([$sectionName])->filterByContextIds([$this->getContextId()])->getMany()->first();
        }

        if (!$this->_section) {
            // Creates a new section
            $section = Repo::section()->newDataObject();
            $section->setData('contextId', $this->getContextId());
            $section->setData('title', $sectionName, $locale);
            $section->setData('abbrev', strtoupper(substr($sectionName, 0, 3)), $locale);
            $section->setData('abstractsNotRequired', true);
            $section->setData('metaIndexed', true);
            $section->setData('metaReviewed', false);
            $section->setData('policy', __('section.default.policy'), $this->getLocale());
            $section->setData('editorRestricted', true);
            $section->setData('hideTitle', false);
            $section->setData('hideAuthor', false);

            Repo::section()->add($section);
            $this->_section = $section;
        }

        // Includes the section into the issue's custom order
        $this->includeSection($this->_section);

        // Caches the entry
        $cache[$this->getContextId()][$locale][$sectionName] = $this->_section;

        return $this->_section;
    }
}
