<?php
/**
 * @file parsers/aPlusPlus/SectionParser.inc.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SectionParser
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief Handles parsing and importing the sections
 */

namespace PKP\Plugins\ImportExport\ArticleImporter\Parsers\APlusPlus;

use Application;
use AppLocale;
use PKP\Plugins\ImportExport\ArticleImporter\EntityManager;
use Section;

trait SectionParser
{
    use EntityManager;

    /** @var Section Section instance */
    private $_section;

    /**
     * Parses and retrieves the section, if a section with the same name exists, it will be retrieved
     */
    public function getSection(): Section
    {
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
        if ($this->_section = $this->getCachedSection($sectionName, $locale)) {
            return $this->_section;
        }

        // Creates a new section
        AppLocale::requireComponents(LOCALE_COMPONENT_APP_DEFAULT);
        $sectionDao = Application::getSectionDAO();
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
        $this->trackEntity($section);
        $this->setCachedSection($sectionName, $locale, $section);
        return $this->_section = $section;
    }
}
