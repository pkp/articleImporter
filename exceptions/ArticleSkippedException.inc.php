<?php
/**
 * @file exceptions/ArticleSkippedException.inc.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleSkippedException
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief Exception triggered when the article cannot be parsed
 */

namespace PKP\Plugins\ImportExport\ArticleImporter\Exceptions;

use Exception;

class ArticleSkippedException extends Exception
{
}
