<?php
/**
 * @file parsers\aPlusPlus\Parser.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Parser
 * @brief Parser class, aggregates all the sub-parsers
 */

namespace APP\plugins\importexport\articleImporter\parsers\aPlusPlus;

use APP\plugins\importexport\articleImporter\BaseParser;
use DateTimeImmutable;
use DOMNode;

class Parser extends BaseParser
{
    // Aggregates the parsers
    use PublicationParser;
    use IssueParser;
    use SectionParser;
    use SubmissionParser;
    use AuthorParser;

    /**
     * Retrieves whether the parser can deal with the underlying document
     */
    public function canParse(): bool
    {
        return (bool) $this->selectFirst('Journal/Volume/Issue/Article/ArticleInfo/ArticleTitle');
    }

    /**
     * Given a nodes with month/year/day, tries to form a valid date string and retrieve a DateTimeImmutable
     */
    public function getDateFromNode(?DOMNode $node): ?DateTimeImmutable
    {
        if (!$node || !strlen($year = $this->selectText('Year', $node))) {
            return null;
        }

        $year = min((int) $year, date('Y'));
        $month = str_pad(max((int) $this->selectText('Month', $node), 1), 2, '0', STR_PAD_LEFT);
        $day = str_pad(max((int) $this->selectText('Day', $node), 1), 2, '0', STR_PAD_LEFT);

        if ($year < 100) {
            $currentYear = date('Y');
            $year += (int)($currentYear / 100) * 100;
            if ($year > $currentYear) {
                $year -= 100;
            }
        }

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return new DateTimeImmutable($year . '-' . $month . '-' . $day);
    }
}
