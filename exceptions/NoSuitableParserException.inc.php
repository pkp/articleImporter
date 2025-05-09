<?php
/**
 * @file exceptions/NoSuitableParserException.inc.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NoSuitableParserException
 * @ingroup plugins_importexport_articleImporter
 *
 * @brief Exception triggered when there's no suitable parser for the article
 */

namespace PKP\Plugins\ImportExport\ArticleImporter\Exceptions;

class NoSuitableParserException extends ArticleSkippedException
{
}
