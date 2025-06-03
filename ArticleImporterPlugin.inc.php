<?php
/**
 * @file ArticleImporterPlugin.inc.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleImporterPlugin
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief ArticleImporter XML import plugin
 */

namespace PKP\Plugins\ImportExport\ArticleImporter;

use Application;
use DAORegistry;
use HookRegistry;
use ImportExportPlugin;
use JournalDAO;
use PageRouter;
use PKP\Plugins\ImportExport\ArticleImporter\Exceptions\ArticleSkippedException;
use PluginRegistry;
use Registry;
use Services;
use SessionManager;
use IssueDAO;
use PKP\Plugins\ImportExport\ArticleImporter\Parsers\APlusPlus\Parser as APlusPlusParser;
use PKP\Plugins\ImportExport\ArticleImporter\Parsers\Jats\Parser as JatsParser;
use Throwable;

import('lib.pkp.classes.plugins.ImportExportPlugin');

class ArticleImporterPlugin extends ImportExportPlugin
{
    /**
     * Registers a custom autoloader to handle the plugin namespace
     */
    private function useAutoLoader()
    {
        spl_autoload_register(function ($className) {
            // Removes the base namespace from the class name
            $path = explode(__NAMESPACE__ . '\\', $className, 2);
            if (!reset($path)) {
                // Breaks the remaining class name by \ to retrieve the folder and class name
                $path = explode('\\', end($path));
                $class = array_pop($path);
                $path = array_map(function ($name) {
                    return strtolower($name[0]) . substr($name, 1);
                }, $path);
                $path[] = $class;
                // Uses the internal loader
                $this->import(implode('.', $path));
            }
        });
    }

    /**
     * @copydoc ImportExportPlugin::getDescription()
     */
    public function executeCLI($scriptName, &$args): void
    {
        ini_set('memory_limit', -1);
        ini_set('assert.exception', 0);
        SessionManager::getManager();
        // Disable the time limit
        set_time_limit(0);

        // Expects 5 non-empty arguments
        if (count(array_filter($args, 'strlen')) < 5) {
            $this->usage($scriptName);
            return;
        }

        // Map arguments to variables
        [$contextPath, $username, $editorUsername, $email, $importPath] = $args;

        $count = $imported = $failed = $skipped = 0;
        try {
            $configuration = new Configuration(
                [APlusPlusParser::class, JatsParser::class],
                $contextPath,
                $username,
                $editorUsername,
                $email,
                $importPath,
                'Articles',
                !in_array('--no-html', $args)
            );

            $this->_writeLine(__('plugins.importexport.articleImporter.importStart'));

            // FIXME: This attaches the associated user to the request and is a workaround for no users being present when running CLI tools.
            $user = $configuration->getUser();
            Registry::set('user', $user);

            /** @var JournalDAO */
            $journalDao = DAORegistry::getDAO('JournalDAO');
            $journal = $journalDao->getByPath($contextPath);
            // Set global context
            $request = Application::get()->getRequest();
            if (!$request->getContext()) {
                HookRegistry::register('Router::getRequestedContextPaths', function (string $hook, array $args) use ($journal): bool {
                    $args[0] = [$journal->getPath()];
                    return false;
                });
                $router = new PageRouter();
                $router->setApplication(Application::get());
                $request->setRouter($router);
            }

            PluginRegistry::loadCategory('pubIds', true, $configuration->getContext()->getId());

            // Iterates through all the found article entries, already sorted by ascending volume > issue > article
            $iterator = $configuration->getArticleIterator();
            $count = 0;
            /** @var ArticleEntry */
            foreach ($iterator as $entry) {
                ++$count;
                $article = implode('-', [$entry->getVolume(), $entry->getIssue(), $entry->getArticle()]);
                try {
                    // Process the article
                    $entry->process($configuration);
                    ++$imported;
                    $this->_writeLine(__('plugins.importexport.articleImporter.articleImported', ['article' => $article]));
                } catch (ArticleSkippedException $e) {
                    $this->_writeLine(__('plugins.importexport.articleImporter.articleSkipped', ['article' => $article, 'message' => $e->getMessage()]));
                    ++$skipped;
                } catch (Throwable $e) {
                    $this->_writeLine(__('plugins.importexport.articleImporter.articleSkipped', ['article' => $article, 'message' => $e->getMessage()]));
                    ++$failed;
                }
            }

            // Resequences issue orders
            if ($imported) {
                $this->resequenceIssues($configuration);
            }

            $this->_writeLine(__('plugins.importexport.articleImporter.importEnd'));
        } catch (Throwable $e) {
            $this->_writeLine(__('plugins.importexport.articleImporter.importError', ['message' => $e->getMessage()]));
        }
        $this->_writeLine(__('plugins.importexport.articleImporter.importStatus', ['count' => $count, 'imported' => $imported, 'failed' => $failed, 'skipped' => $skipped]));
    }

    /**
     * Resequences the issues
     */
    public function resequenceIssues(Configuration $configuration): void
    {
        $contextId = $configuration->getContext()->getId();
        /** @var IssueDAO */
        $issueDao = DAORegistry::getDAO('IssueDAO');
        // Clears previous ordering
        $issueDao->deleteCustomIssueOrdering($contextId);

        // Retrieves issue IDs sorted by volume and number
        $rsIssues = Services::get('issue')->getQueryBuilder([
            'contextId' => $contextId,
            'isPublished' => true,
            'orderBy' => 'seq',
            'orderDirection' => 'ASC'
        ])
            ->getQuery()
            ->orderBy('volume', 'DESC')
            ->orderByRaw('CAST(number AS UNSIGNED) DESC')
            ->select('i.issue_id')
            ->pluck('i.issue_id');
        $sequence = 0;
        $latestIssue = null;
        foreach ($rsIssues as $id) {
            $latestIssue || ($latestIssue = $id);
            $issueDao->insertCustomIssueOrder($contextId, $id, ++$sequence);
        }

        // Sets latest issue as the current one
        $latestIssue = Services::get('issue')->get($latestIssue);
        $latestIssue->setData('current', true);
        $issueDao->updateCurrent($contextId, $latestIssue);
    }

    /**
     * Outputs a message with a line break
     */
    private function _writeLine(?string $message): void
    {
        echo $message, PHP_EOL;
        flush();
    }

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path);
        $this->addLocaleData();
        $this->useAutoLoader();
        return $success;
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName(): string
    {
        $class = explode('\\', __CLASS__);
        return end($class);
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.importexport.articleImporter.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.importexport.articleImporter.description');
    }

    /**
     * @copydoc ImportExportPlugin::usage()
     */
    public function usage($scriptName): void
    {
        $this->_writeLine(__('plugins.importexport.articleImporter.cliUsage', ['scriptName' => $scriptName, 'pluginName' => $this->getName()]));
    }
}
