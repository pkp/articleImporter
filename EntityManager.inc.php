<?php
/**
 * @file EntityManager.inc.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class EntityManager
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief Trait that provides caching functionality for parsers
 */

namespace PKP\Plugins\ImportExport\ArticleImporter;

use Application;
use Category;
use CategoryDAO;
use DAORegistry;
use Genre;
use GenreDAO;
use Issue;
use IssueDAO;
use Section;
use Services;
use Submission;
use SubmissionDAO;
use Throwable;

trait EntityManager
{
    /** @var array Cache */
    protected static $cache = [];

    /** @var array Track created entities for rollback */
    protected $entities = [];

    /**
     * Track a created entity for rollback
     */
    protected function trackEntity(object $entity): void
    {
        $this->entities[] = $entity;
    }

    /**
     * Delete tracked entities
     */
    protected function deleteTrackedEntities(): void
    {
        foreach ($this->entities as $entity) {
            try {
                $cache = null;
                if ($entity instanceof Submission) {
                    /** @var SubmissionDAO */
                    $submissionDao = DAORegistry::getDAO('SubmissionDAO');
                    $submissionDao->deleteObject($entity);
                    $cache = 'submission';
                } elseif ($entity instanceof Issue) {
                    /** @var IssueDAO */
                    $issueDao = DAORegistry::getDAO('IssueDAO');
                    $issueDao->deleteObject($entity);
                    $cache = 'issue';
                } elseif ($entity instanceof Section) {
                    Application::getSectionDAO()->deleteObject($entity);
                    $cache = 'section';
                } elseif ($entity instanceof Category) {
                    /** @var CategoryDAO */
                    $categoryDao = DAORegistry::getDAO('CategoryDAO');
                    $categoryDao->deleteObject($entity);
                    $cache = 'category';
                } else {
                    error_log('Unexpected entity type');
                }
                if ($cache) {
                    foreach(static::$cache[$cache] as $key => $value) {
                        if ($entity === $value) {
                            unset(static::$cache[$cache][$key]);
                            break;
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log("An error happened while removing an entity:\n{$e}");
            }
        }

        $this->entities = [];
    }

    /**
     * Get cached genre
     */
    protected function getCachedGenre(string $extension): Genre
    {
        $type = in_array($extension, $this->getConfiguration()->getImageExtensions()) ? 'IMAGE' : 'MULTIMEDIA';
        if (isset(static::$cache['genre'][$type])) {
            return static::$cache['genre'][$type];
        }

        /** @var GenreDAO */
        $genreDao = DAORegistry::getDAO('GenreDAO');
        return static::$cache['genre'][$type] = $genreDao->getByKey($type, $this->getContextId()) ?? $this->getConfiguration()->getSubmissionGenre();
    }

    /**
     * Get cached section
     */
    protected function getCachedSection(string $name, string $locale): ?Section
    {
        $sectionDao = Application::getSectionDAO();
        return static::$cache['section']["{$name}-{$locale}"] ?? $sectionDao->getByTitle($name, $this->getContextId(), $locale);
    }

    /**
     * Set cached section
     */
    protected function setCachedSection(string $name, string $locale, Section $section): void
    {
        static::$cache['section']["{$name}-{$locale}"] = $section;

        if (!$section) {
            return;
        }

        // Includes a section into the issue custom order
        $sectionDao = Application::getSectionDAO();
        // Checks whether the section is already present in the issue
        if (!$sectionDao->getCustomSectionOrder($this->getIssue()->getId(), $section->getId())) {
            $sectionDao->insertCustomSectionOrder($this->getIssue()->getId(), $section->getId(), count(static::$cache['section']));
        }
    }

    /**
     * Get cached submission
     */
    protected function getCachedSubmission(): ?Submission
    {
        $entry = $this->getArticleEntry();
        return static::$cache['submission']["{$entry->getVolume()}-{$entry->getIssue()}-{$entry->getArticle()}"] ?? null;
    }

    /**
     * Set cached submission
     */
    protected function setCachedSubmission(Submission $submission): void
    {
        $entry = $this->getArticleEntry();
        static::$cache['submission']["{$entry->getVolume()}-{$entry->getIssue()}-{$entry->getArticle()}"] = $submission;
    }

    /**
     * Get cached issue
     */
    protected function getCachedIssue(string $volume, string $number): ?Issue
    {
        return static::$cache['issue']["{$volume}-{$number}"] ?? Services::get('issue')->getMany([
            'contextId' => $this->getContextId(),
            'volumes' => $volume,
            'numbers' => $number
        ])->current();
    }

    /**
     * Set cached issue
     */
    protected function setCachedIssue(string $volume, string $number, Issue $issue): void
    {
        static::$cache['issue']["{$volume}-{$number}"] = $issue;
    }

    /**
     * Get cached category
     */
    protected function getCachedCategory(string $name, string $locale): ?Category
    {
        /** @var CategoryDAO */
        $categoryDao = DAORegistry::getDAO('CategoryDAO');
        return static::$cache['category']["{$name}-{$locale}"] ?? $categoryDao->getByTitle($name, $this->getContextId(), $locale);
    }

    /**
     * Set cached category
     */
    protected function setCachedCategory( string $name, string $locale, ?Category $category): void
    {
        static::$cache['category']["{$name}-{$locale}"] = $category;
    }
}
