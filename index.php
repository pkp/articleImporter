<?php

/**
 * @defgroup plugins_importexport_articleImporter
 */

/**
 * @file index.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_importexport_articleImporter
 * @brief Wrapper for ArticleImporter import plugin
 *
 */

namespace PKP\Plugins\ImportExport\ArticleImporter;

require_once 'ArticleImporterPlugin.inc.php';

return new ArticleImporterPlugin();
