<?php
/**
 * @file parsers/jats/Parser.php
 *
 * Copyright (c) 2020 Simon Fraser University
 * Copyright (c) 2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Parser
 * @brief Parser class, aggregates all the sub-parsers
 */

namespace APP\plugins\importexport\articleImporter\parsers\jats;

use APP\plugins\importexport\articleImporter\BaseParser;
use DateTimeImmutable;
use DOMDocumentType;
use DOMElement;
use DOMImplementation;
use DOMNode;
use DOMXPath;

class Parser extends BaseParser
{
    // Aggregates the parsers
    use PublicationParser;
    use IssueParser;
    use SectionParser;
    use SubmissionParser;
    use AuthorParser;

    /**
     * Retrieves the DOCTYPE
     *
     * @return DOMDocumentType[]
     */
    public function getDocType(): array
    {
        return [
            (new DOMImplementation())->createDocumentType('article', '-//EDP//DTD EDP Publishing JATS v1.0 20130606//EN', 'JATS-edppublishing1.dtd'),
            (new DOMImplementation())->createDocumentType('article', '-//NLM//DTD Journal Archiving with OASIS Tables v3.0 20080202//EN', 'http://dtd.nlm.nih.gov/archiving/3.0/archive-oasis-article3.dtd'),
            (new DOMImplementation())->createDocumentType('article', '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN', 'http://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd'),
        ];
    }

    /**
     * Rollbacks the process
     */
    public function rollback(): void
    {
        $this->_rollbackSection();
        $this->_rollbackIssue();
        $this->_rollbackSubmission();
    }

    /**
     * Given a nodes with month/year/day, tries to form a valid date string and retrieve a DateTimeImmutable
     */
    public function getDateFromNode(?DOMNode $node, DOMXPath $xpath = null): ?DateTimeImmutable
    {
        if (!$node || !strlen($year = $this->selectText('year', $node, $xpath))) {
            return null;
        }

        $year = min((int) $year, date('Y'));
        $month = str_pad(max((int) $this->selectText('month', $node, $xpath), 1), 2, '0', STR_PAD_LEFT);
        $day = str_pad(max((int) $this->selectText('day', $node, $xpath), 1), 2, '0', STR_PAD_LEFT);

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

    /**
     * Convert JATS tags to HTML
     */
    public function fixJatsTags(DOMElement $node): DOMElement
    {
        $replace = function (string $tagName, string $newTagName, callable $configure = null) use ($node): void {
            /** @var DOMElement */
            foreach ($node->getElementsByTagName($tagName) as $childNode) {
                $childNodes = iterator_to_array($childNode->childNodes);
                $newNode = new DOMElement($newTagName);
                $childNode->parentNode->replaceChild($newNode, $childNode);
                $configure && $configure($newNode, $childNode);
                foreach ($childNodes as $childNode) {
                    $newNode->appendChild($childNode);
                }
            }
        };
        $replace('bold', 'b');
        $replace('italic', 'i');
        $replace('strike', 's');
        $replace('underline', 'u');
        $replace('list', 'ul');
        $replace('list-item', 'li');
        $replace('sc', 'span', function (DOMElement $new, DOMElement $old) {
            $new->setAttribute('style', 'font-variant: small-caps');
        });
        $replace('email', 'a', function (DOMElement $new, DOMElement $old) {
            $new->setAttribute('href', $old->textContent);
        });
        $replace('ext-link', 'a', function (DOMElement $new, DOMElement $old) {
            $new->setAttribute('href', $old->getAttributeNS('http://www.w3.org/1999/xlink', 'href') ?: $old->getAttribute('href'));
        });

        return $node;
    }

    /**
     * Remove any xref tag from the given node
     */
    public function clearXref(DOMElement $node, bool $cloneNode = true): DOMElement
    {
        if ($cloneNode) {
            $node = $node->cloneNode(true);
        }
        /** @var DOMElement */
        foreach (iterator_to_array($node->getElementsByTagName('xref')) as $xref) {
            $xref->parentNode->removeChild($xref);
        }
        return $node;
    }
}
