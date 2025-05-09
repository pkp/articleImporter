<?php
/**
 * @file plugins/importexport/articleImporter/parsers/jats/SectionParser.inc.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SectionParser
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief Handles parsing and importing the sections
 */

namespace PKP\Plugins\ImportExport\ArticleImporter\Parsers\Jats;

trait SectionParser
{
    /** @var bool True if the section was created by this instance */
    private $_isSectionOwner;
    /** @var \Section Section instance */
    private $_section;

    /**
     * Rollbacks the operation
     */
    private function _rollbackSection(): void
    {
        if ($this->_isSectionOwner) {
            \Application::getSectionDAO()->deleteObject($this->_section);
        }
    }

    /**
     * Parses and retrieves the section, if a section with the same name exists, it will be retrieved
     */
    public function getSection(): \Section
    {
        static $cache = [];

        if ($this->_section) {
            return $this->_section;
        }

        $sectionName = null;
        $locale = $this->getLocale();

        if ($this->getConfiguration()->canUseCategoryAsSection()) {
            // Retrieves the section name and locale
            $node = $this->selectFirst('front/article-meta/article-categories/subj-group');
            if ($node) {
                $sectionName = ucwords(strtolower($this->selectText('subject', $node)));
                $locale = $this->getLocale($node->getAttribute('xml:lang'));
            }
        }

        $sectionName = $sectionName ?: $this->getIssueMeta()['section'] ?: $this->getConfiguration()->getDefaultSectionName();
        // Tries to find an entry in the cache
        $this->_section = $cache[$this->getContextId()][$locale][$sectionName] ?? null;

        if (!$this->_section) {
            // Tries to find an entry in the database
            $sectionDao = \Application::getSectionDAO();
            $this->_section = $sectionDao->getByTitle($sectionName, $this->getContextId(), $locale);
        }

        if (!$this->_section) {
            // Creates a new section
            \AppLocale::requireComponents(\LOCALE_COMPONENT_APP_DEFAULT);
            $section = $sectionDao->newDataObject();
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

            $sectionDao->insertObject($section);
            $this->_section = $section;
        }

        // Includes the section into the issue's custom order
        $this->includeSection($this->_section);

        // Caches the entry
        $cache[$this->getContextId()][$locale][$sectionName] = $this->_section;

        return $this->_section;
    }
}
