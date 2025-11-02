<?php

namespace docx2jats\jats;

/**
 * @file src/docx2jats/jats/Text.php
 *
 * Copyright (c) 2018-2019 Vitalii Bezsheiko
 * Distributed under the GNU GPL v3.
 *
 * @brief represent document's text and its formatting
 */

use docx2jats\objectModel\DataObject;
use docx2jats\objectModel\body\Text as ObjectText;

class Text
{
	public static function createExtLink(\DOMDocument $doc, string $href, ?string $text = null): \DOMElement
	{
		$linkType = 'uri'; // default

		// Use de href as text
		if ($text) {
			$text = $href;
		}

		// DOI detection
		if (preg_match('/^(https?:\/\/(?:dx\.)?doi\.org\/)?(10\.\d{4,9}\/\S+)$/i', $href, $m)) {
			$linkType = 'doi';
			$doi = $m[2];
			$href = $m[1] ? $m[0] : 'https://doi.org/' . $doi;
			$text = $doi;
		}
		// PMC detection
		elseif (preg_match('/https?:\/\/www\.ncbi\.nlm\.nih\.gov\/pmc\/articles\/PMC(\d{7,9})\/?/i', $href, $m)) {
			$linkType = 'pmc';
			$text = 'PMC' . $m[1];
		}
		// PMID detection
		elseif (preg_match('/(https?:\/\/pubmed\.ncbi\.nlm\.nih.gov\/)?(\d{5,9})\/?/i', $href, $m)) {
			$linkType = 'pmid';
			$pmid = $m[2];
			$href = $m[1] ? $m[0] : 'https://pubmed.ncbi.nlm.nih.gov/' . $pmid;
			$text = $pmid;
		}
		// GenBank detection
		elseif (preg_match('/https?:\/\/(?:www\.)?ncbi\.nlm\.nih\.gov\/nuccore\/([A-Z0-9_.-]+)/i', $href, $m)) {
			$linkType = 'genbank';
			$text = $m[1];
		}
		// Clinical trial detection
		elseif (preg_match('/^(?:https?:\/\/(?:www\.)?clinicaltrials\.gov\/(?:ct2\/)?show\/)?(NCT\d{8,9})$/i', $href, $m)) {
			$linkType = 'clinical-trial';
			$trialId = $m[1];
			$href = 'https://clinicaltrials.gov/show/' . $trialId;
			$text = $trialId;
		}
		// E-mail detection
		elseif (preg_match('/^mailto:([^@]+@[^@]+\.[^@]+)$/i', $href, $m) || filter_var($href, FILTER_VALIDATE_EMAIL)) {
			$linkType = 'email';
			$email = $m[1] ?? $href;
			$href = (stripos($href, 'mailto:') === 0) ? $href : 'mailto:' . $email;
			$text = $email;
		}
		// FTP detection
		elseif (preg_match('/^ftp:\/\/\S+/i', $href)) {
			$linkType = 'ftp';
			// keep text as-is unless redundant
			$text = ($text === $href) ? basename($href) : $text;
		}

		// Force $linkType for SciELO SPS flavor: Only 'uri' and 'clinical-trial' are accepted
		if ($linkType !== 'clinical-trial') {
			$linkType = 'uri';
		}

		$extLink = $doc->createElement('ext-link');
		$extLink->setAttribute('ext-link-type', $linkType);
		$extLink->setAttribute('xlink:href', $href);
		$extLink->appendChild($doc->createTextNode($text));

		return $extLink;
	}

	public static function extractText(ObjectText $jatsText, \DOMElement $domElement): void
	{
		// Get DOMDocument
		$domDocument = $domElement->ownerDocument;
		// Dealing with simple text (without any properties)
		$nodeTypes = $jatsText->getType();
		if (empty($nodeTypes)) {
			$textNode = $domDocument->createTextNode($jatsText->getContent());
			$domElement->appendChild($textNode);
			unset($nodeTypes);
		}
		// Renaming text properties into standard HTML node element
		$typeArray = array();
		if (isset($nodeTypes)) {
			foreach ($nodeTypes as $nodeType) {
				switch ($nodeType) {
					case ObjectText::DOCX_TEXT_ITALIC:
						$typeArray[] = "italic";
						break;
					case ObjectText::DOCX_TEXT_BOLD:
						$typeArray[] = "bold";
						break;
					case ObjectText::DOCX_TEXT_SUPERSCRIPT:
						$typeArray[] = "sup";
						break;
					case ObjectText::DOCX_TEXT_SUBSCRIPT:
						$typeArray[] = "sub";
						break;
					case ObjectText::DOCX_TEXT_EXTLINK:
						$typeArray[] = "ext-link";
						break;
				}
			}
		}
		// Dealing with text that has only one property, e.g. italic, bold, link
		if (count($typeArray) === 1) {
			foreach ($typeArray as $typeKey => $type) {
				if ($type === 'ext-link') {
					$nodeElement = self::createExtLink($domDocument, $jatsText->getLink(), $jatsText->getContent());
					$domElement->appendChild($nodeElement);
				} elseif (!is_array($type)) {
					$nodeElement = $domDocument->createElement($type);
					$nodeElement->nodeValue = htmlspecialchars($jatsText->getContent());
					$domElement->appendChild($nodeElement);
				} else {
					foreach ($type as $insideKey => $insideType) {
						$nodeElement = $domDocument->createElement($insideKey);
						$nodeElement->nodeValue = htmlspecialchars(trim($jatsText->getContent()));
						$domElement->appendChild($nodeElement);
					}
				}
			}
			// Dealing with complex cases -> text with several properties
		} else {
			/* @var $prevElement array of DOMElements */
			$prevElements = array();
			foreach ($typeArray as $key => $type) {
				if ($type === 'ext-link') {
					$nodeElement = self::createExtLink($domDocument, $jatsText->getLink(), $jatsText->getContent());
				}
				elseif (!is_array($type)) {
					$nodeElement = $domDocument->createElement($type);
				}

				array_push($prevElements, $nodeElement);

				if ($key === 0) {
					$domElement->appendChild($prevElements[0]);
				} elseif (($key === (count($typeArray) - 1))) {

					if ($type !== 'ext-link') {
						$nodeElement->nodeValue = htmlspecialchars($jatsText->getContent());
					}

					foreach ($prevElements as $prevKey => $prevElement) {
						if ($prevKey !== (count($prevElements) - 1)) {
							$prevElement->appendChild(next($prevElements));
						}
					}
				}
			}
		}
	}
}
