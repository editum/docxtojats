<?php namespace docx2jats\jats;

/**
 * @file src/docx2jats/jats/Reference.php
 *
 * Copyright (c) 2018-2019 Vitalii Bezsheiko
 * Distributed under the GNU GPL v3.
 *
 * @brief represent JATS XML reference
 */

use docx2jats\objectModel\body\Reference as ObjReference;

class Reference extends \DOMElement {

	public const JATS_REF_ID_PREFIX = 'bib';

	/** @var $refTypeCSLMap string[]  compatible with Texture */
	public static $refTypeCSLMap = [
		'article-journal' => 'journal',
		'book' => 'book',
		'chapter' => 'chapter',
		'paper-conference' => 'confproc',
		'dataset' => 'data',
		'article-magazine' => 'periodical',
		'article-newspaper' => 'periodical',
		'manuscript' => 'preprint',
		'report' => 'report',
		'patent' => 'patent',
		'webpage' => 'website',
		'post-weblog' => 'website',
		'post' => 'website',
		'thesis' => 'thesis',
	];

	public function __construct() {
		parent::__construct('ref');
	}

	public function setContent(ObjReference $reference) {
		$this->setAttribute('id', self::JATS_REF_ID_PREFIX . $reference->getId());
		if (!$reference->hasStructure()) {
			$mixedCitationEl = $this->ownerDocument->createElement('mixed-citation');
			$textContent = $this->ownerDocument->createTextNode($reference->getRawReference());
			$mixedCitationEl->appendChild($textContent);
			$this->appendChild($mixedCitationEl);
		}

		else {
			$this->setStructure($reference);
		}
	}

	private function setStructure(ObjReference $reference) {
		if ($cslref = $reference->getCSL()) {
			$this->setMixedCitationFromCSL($cslref);
			$this->setStructureFromCSL($cslref);
		}
	}

	private function setMixedCitationFromCSL(\stdClass $csl) {
		$data = $csl->itemData;
		$text = null;
		$contentArr = [];

		// Get the authors
		$authors = [];
		foreach ($data->author ?? [] as $reg) {
			$author = [];
			if ($family = $reg->family ?? null);
				$author[] = $family;
			if ($given = $reg->given ?? null) {
				// Get only the acronyms
				$given = preg_replace('/\b(\w)|./', '$1', $given);
				$given = preg_replace('/(.)/', '$1. ', $given);
				$given = rtrim($given, ' ');
				$author[] = $given;
			}
			if (! empty($author))
				$authors[] = implode(', ', $author);
		}
		if (! empty($authors)) {
			$last = count($authors) - 1;
			$authors[$last] = rtrim($authors[$last], '.');
			$contentArr[] = implode(', ', $authors);
		}

		// Get other info
		$infoArr = [];

		// Container type
		if ($doctype = $data->{'container-title'} ?? null)
			$infoArr[] = $doctype;
		// Get the title
		if ($title = $data->title ?? null)
			$contentArr[] = $title;
		// Get year
		if ($issued = $data->issued ?? null) {
			if ($year = $issued->{'date-parts'}[0][0] ?? null)
				$infoArr[] = $year;
			elseif ($rawDate = $issued->raw ?? null)
				if ($date = strtotime($rawDate))
					if ($year = date('Y', $date))
						$infoArr[] = $year;
		}
		// Get volume
		if ($volume = $data->volume ?? null)
			$infoArr[] = "vol. $volume";
		// Get issue number
		if ($issue = $data->issue ?? null)
			$infoArr[] = "nº $issue";
		// Get issue page
		if ($page = $data->page ?? null )
			$infoArr[] = "p. $page";
		elseif ($page = $data->{'page-first'} ?? null)
			$infoArr[] = "p. $page";

		if (! empty($infoArr))
			$contentArr[] = implode(', ', $infoArr);

		if (! empty($contentArr)) {
			$text = implode('. ', $contentArr).'.';
			$cslPubType = $this->getStdClassPropertyValue($data, 'type');
			$jatsPubType = self::$refTypeCSLMap[$cslPubType] ?? current(self::$refTypeCSLMap);
			$mixedCitationEl = $this->createAndAppendElement($this, 'mixed-citation', $text, ['publication-type' => $jatsPubType]);
		}
	}

	private function setStructureFromCSL(\stdClass $csl) {
		$data = $csl->{'itemData'};
		$cslPubType = $this->getStdClassPropertyValue($data, 'type');
		if (array_key_exists($cslPubType, self::$refTypeCSLMap)) {
			$jatsPubType = self::$refTypeCSLMap[$cslPubType];
		} else {
			$jatsPubType = current(self::$refTypeCSLMap);
		}
		$elementCitationEl = $this->createAndAppendElement($this, 'element-citation', null, ['publication-type' => $jatsPubType]);

		// Authors and editors
		$authors = $this->getStdClassPropertyValue($data, 'author');
		if ($authors) {
			$this->extractCSLNames($elementCitationEl, $authors, 'author');
		}

		$containerAuthors = $this->getStdClassPropertyValue($data, 'container-author');
		if ($containerAuthors) {
			$this->extractCSLNames($elementCitationEl, $containerAuthors, 'editor');
		}

		$editor = $this->getStdClassPropertyValue($data, 'editor');
		if ($editor && !$containerAuthors) {
			$this->extractCSLNames($elementCitationEl, $editor, 'editor');
		}

		// Title
		$title = $this->getStdClassPropertyValue($data, 'title');
		if ($title) {
			if ($jatsPubType === 'chapter')  {
				$this->createAndAppendElement($elementCitationEl, 'chapter-title', $title);
			} elseif ($jatsPubType === 'book') {
				$this->createAndAppendElement($elementCitationEl, 'source', $title);
			} else {
				$this->createAndAppendElement($elementCitationEl, 'article-title', $title);
			}
		}

		// Source
		$containerTitle = $this->getStdClassPropertyValue($data, 'container-title');
		if ($containerTitle && $jatsPubType !== 'book') {
			$sourceEl = $this->createAndAppendElement($elementCitationEl, 'source', $containerTitle);
		}

		$publisher = $this->getStdClassPropertyValue($data, 'publisher');
		if ($publisher) {
			$publisherEl = $this->createAndAppendElement($elementCitationEl, 'publisher-name', $publisher);
		}

		$publisherPlace = $this->getStdClassPropertyValue($data, 'publisher-place');
		if ($publisherPlace) {
			$publisherLocEl = $this->createAndAppendElement($elementCitationEl, 'publisher-loc', $publisherPlace);
		}

		$volume = $this->getStdClassPropertyValue($data, 'volume');
		if ($volume) {
			$volumeEl = $this->createAndAppendElement($elementCitationEl, 'volume', $volume);
		}

		$issue = $this->getStdClassPropertyValue($data, 'issue');
		if ($issue) {
			$issueEl = $this->createAndAppendElement($elementCitationEl, 'issue', $issue);
		}

		$event = $this->getStdClassPropertyValue($data, 'event');
		if ($event) {
			$confTitleEl = $this->createAndAppendElement($elementCitationEl, 'conf-name', $event);
		}

		$event = $this->getStdClassPropertyValue($data, 'event-place');
		if ($event && $jatsPubType === 'conference') { // Zotero adds event-place to books and chapters
			$confLocEl = $this->createAndAppendElement($elementCitationEl, 'conf-loc', $event);
		}

		// pages
		$page = $this->getStdClassPropertyValue($data, 'page');
		if ($page) {
			$pageRangeEl = $this->createAndAppendElement($elementCitationEl, 'page-range', $page);
		}

		$pageFirst = $this->getStdClassPropertyValue($data, 'page-first');
		if ($pageFirst) {
			$pageFirstEl = $this->createAndAppendElement($elementCitationEl, 'fpage', $pageFirst);
		}

		// Identificators
		$doi = $this->getStdClassPropertyValue($data, 'DOI');
		if ($doi) {
			$doiEl = $this->createAndAppendElement($elementCitationEl, 'pub-id', $doi, ['pub-id-type' => 'doi']);
		}

		$pmid = $this->getStdClassPropertyValue($data, 'PMID');
		if ($pmid) {
			$pmidEl = $this->createAndAppendElement($elementCitationEl, 'pub-id', $doi, ['pub-id-type' => 'pmid']);
		}

		$url = $this->getStdClassPropertyValue($data, 'URL');
		if ($url) {
			$urlEl = $this->createAndAppendElement($elementCitationEl, 'ext-link', $url);
		}

		$issn = $this->getStdClassPropertyValue($data, 'ISSN');
		if ($issn) {
			$issnEl = $this->createAndAppendElement($elementCitationEl, 'issn', $issn);
		}

		// Date
		$issued = $this->getStdClassPropertyValue($data, 'issued');
		if ($issued) {
			$dateParts = $this->getStdClassPropertyValue($issued, 'date-parts');
			if ($dateParts) {
				if (array_key_exists(0, $dateParts[0])) {
					$yearEl = $this->createAndAppendElement($elementCitationEl, 'year', $dateParts[0][0]);
				}

				if (array_key_exists(1, $dateParts[0])) {
					$monthEl = $this->createAndAppendElement($elementCitationEl, 'month', $dateParts[0][1]);
				}

				if (array_key_exists(2, $dateParts[0])) {
					$dayEl = $this->createAndAppendElement($elementCitationEl, 'day', $dateParts[0][2]);
				}
			} else {
				$rawDate = $this->getStdClassPropertyValue($issued, 'raw');
				if ($rawDate) {
					if ($formattedDate = strtotime($rawDate)) {
						if ($year = date('Y', $formattedDate)) {
							$yearEl = $this->createAndAppendElement($elementCitationEl, 'year', $year);
						}

						if ($month = date('m', $formattedDate)) {
							$monthEl = $this->createAndAppendElement($elementCitationEl, 'month', $month);
						}

						if ($day = date('d', $formattedDate)) {
							$dayEl = $this->createAndAppendElement($elementCitationEl, 'day', $day);
						}
					}
				}
			}
		}


	}

	private function createAndAppendElement(\DOMElement $parentEl, string $elName, string $text = null, array $attrNameValue = null) : \DOMElement {
		$ownerDocument = $this->ownerDocument;
		$el = $ownerDocument->createElement($elName);
		$parentEl->appendChild($el);
		if ($text) {
			$el->appendChild($ownerDocument->createTextNode($text));
		}

		if (!empty($attrNameValue)) {
			foreach ($attrNameValue as $name => $value) {
				$el->setAttribute($name, $value);
			}
		}

		return $el;
	}

	/**
	 * @return mixed
	 */
	private function getStdClassPropertyValue($class, string $property) {
		if (property_exists($class, $property)) {
			return $class->{$property};
		}

		return null;
	}

	/**
	 * @param \DOMElement $elementCitationEl
	 * @param mixed $stdProperty
	 * @param string $personGroupType
	 * @brief extracts and adds to JATS XML authors and editors
	 */
	private function extractCSLNames(\DOMElement $elementCitationEl, $stdProperty, string $personGroupType) {
		$personGroupEl = $this->createAndAppendElement($elementCitationEl, 'person-group', null, ['person-group-type' => $personGroupType]);
		foreach ($stdProperty as $author) {
			$nameEl = $this->createAndAppendElement($personGroupEl, 'name');
			$family = $this->getStdClassPropertyValue($author, 'family');
			if ($family) {
				$surnameEl = $this->createAndAppendElement($nameEl, 'surname', $family);
			}
			$given = $this->getStdClassPropertyValue($author, 'given');
			if ($given) {
				$givenEl = $this->createAndAppendElement($nameEl, 'given-names', $given);
			}
		}
	}
}
