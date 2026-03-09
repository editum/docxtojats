<?php namespace docx2jats\jats;

/**
 * @file src/docx2jats/jats/Row.php
 *
 * Copyright (c) 2018-2020 Vitalii Bezsheiko
 * Distributed under the GNU GPL v3.
 *
 * @brief represent JATS XML paragraph; can't be nested. To be included into body, sections, lists and table cells.
 */

use docx2jats\objectModel\body\Field;
use docx2jats\objectModel\DataObject;
use docx2jats\jats\Text as JatsText;

class Par extends Element {
	const CLS_TYPE_UNKNOWN = 0;
	const CLS_TYPE_AMA_COMPATIBLE = 1;
	const CLS_TYPE_APA_COMPATIBLE = 2;
	const CLS_TYPE_IEEE = 3;

	// AMA, VANCUVER: '4-10,5' '(10,16–18,20)'
	const CLS_AMA_COMPATIBLE = '/^\(?\d+([-\x{2013}\x{2014}]\d+)?(,\d+([-\x{2013}\x{2014}]\d+)?)*\)?$/u';
	// APA (name1, 2015; name2, 2020; name3, nd)
	const CLS_APA_COMPATIBLE = '/^\([«]?[^\d\W]+[»]?.*(; [«]?[^\d\W]+[»]?.*)*\)$/u';
	// IEE: '[4], [7]-[10]'
	const CLS_IEEE = '/^\[\d+\]([-\x{2013}\x{2014}]\[\d+\])?(, \[\d+\]([-\x{2013}\x{2014}]\[\d+\])?)*$/u';

	public function __construct(DataObject $dataObject) {
		parent::__construct($dataObject);
	}

	public function setContent() {
		$prevTextRefs = [];
		foreach ($this->getDataObject()->getContent() as $content) {
			$class = get_class($content);
			if ($class === 'docx2jats\objectModel\body\Field') {
				// Write links to references from Zotero and Mendeley plugin for MS Word
				if ($content->getType() === Field::DOCX_FIELD_CSL) {
					// REVIEW if there is a plain citation in a group of citations this may be null and the indexes wrong
					$cit = $content->getPlainCit();
					if ($cit == null) {
						continue;
					}
					$this->createCLSRef($content->getRefIds(), $cit);
					//$this->createCLSRef($content->getRefIds(), $content->getPlainCit());
				}
				// Write links to table and figures
				elseif ($content->getType() === Field::DOCX_FIELD_BOOKMARK_REF) {
					$refEl = $this->ownerDocument->createElement('xref');
					$this->appendChild($refEl);
					foreach ($content->getContent() as $text) { /* @var $text \docx2jats\objectModel\body\Text */
						JatsText::extractText($text, $refEl);
					}
					if ($tableIdRef = $content->tableIdRef) {
						$refEl->setAttribute('ref-type', 'table');
						$refEl->setAttribute('rid', Table::JATS_TABLE_ID_PREFIX . $tableIdRef);
					} elseif ($figureIdRef = $content->figureIdRef) {
						$refEl->setAttribute('ref-type', 'fig');
						$refEl->setAttribute('rid', Figure::JATS_FIGURE_ID_PREFIX . $figureIdRef);
					}
				}
				$prevTextRefs = []; // restart track of refs from Mendeley LW plugin
			} elseif ($class === 'docx2jats\objectModel\body\Footnote') {
				$fn = new Footnote($content);
				$this->appendChild($fn);
				$fn->setContent();
			} elseif ($class === 'docx2jats\objectModel\body\Endnote') {
				$fn = new Footnote($content);
				$this->appendChild($fn);
				$fn->setContent();
			} else {
				// Write links to references from Mendeley plugin for LibreOffice Writer
				/* @var $content \docx2jats\objectModel\body\Text */
				if ($content->hasCSLRefs) {
					$this->createCLSRef($content->refIds, $content->getContent());
					// TODO todo esto de $prevTextRefs no le veo lógica, tampoco que se inserten de forma distinta a word, residual????
					$currentRefs = $content->refIds;
					$prevTextRefs = $currentRefs;
				}
				// Write other text
				else {
					JatsText::extractText($content, $this);
					$prevTextRefs = []; // restart track of refs from Mendeley LW plugin
				}
			}
		}
	}

	/**
	 * @param array $refIds
	 * @param string $plainCit
	 * @return void
	 * Identifies the type of citation used and creates the reference respecting the rules.
	 * The ranges will be transformed in lists.
	 * If the type is uknown it will use by default 1 2 3....
	 * // TODO: use the style detected: objectModel/Document::getcslStyle
	 */
	function createCLSRef(array $refIds, string $plainCit) {
		// Default fot uknown
		$type = self::CLS_TYPE_UNKNOWN;
		$separator = ' ';
		$format = '%d';
		$openGroup = false;
		$closeGroup = false;
		$arrCit = [];

		// APA and equivalents
		if (preg_match(self::CLS_APA_COMPATIBLE, $plainCit)) {
			$type = self::CLS_TYPE_APA_COMPATIBLE;
			$separator = '; ';
			if (count($refIds) > 1) {
				// There are not parenthesis if the citation is part of the narrative
				if (strpos($plainCit, '(') === 0) {
					$openGroup = '(';
					$closeGroup = ')';
				}
				// Get every citation so they can be referenced later
				$arrCit = explode($separator, trim($plainCit, '()'));
			} else {
				$arrCit = [ $plainCit ];
			}
		}
		// IEEE '[4], [7]-[10]'
		elseif (preg_match(self::CLS_IEEE, $plainCit)) {
			$type = self::CLS_TYPE_IEEE;
			$separator = ', ';
			$format = '[%d]';
		}
		// TODO AMA text is in superindex format
		// AMA '4-10,5', Vancuver '(10,16–18,20)'
		elseif (preg_match(self::CLS_AMA_COMPATIBLE, $plainCit)) {
			$type=self::CLS_TYPE_AMA_COMPATIBLE;
			$separator = ',';
			// Special case Vancuver
			if (strpos($plainCit, '(') === 0) {
				if (count($refIds) > 1) {
					$openGroup = '(';
					$closeGroup = ')';
					$format = '%d';
				} else {
					$format = '(%d)';
				}
			// AMA, (or Vancuver when it's part of the narrative?)
			} else {
				$format = '%d';
			}
		}

		// Format and write the references
		if ($openGroup) {
			$this->appendChild($this->ownerDocument->createTextNode($openGroup));
		}

		var_dump(compact('refIds', 'plainCit', 'arrCit'));


		$lastKey = array_key_last($refIds);
		$i = 0;
		foreach ($refIds as $key => $id) {
			// Format ref text depending on CSL type
			switch ($type) {
				case self::CLS_TYPE_APA_COMPATIBLE:
					echo $i.PHP_EOL;
					$text = $arrCit[$i++];
					break;
				case self::CLS_TYPE_AMA_COMPATIBLE:
				case self::CLS_TYPE_IEEE:
				default:
					$text = sprintf($format, $id);
					break;
			}

			// BUG: doesn't scape the text -> $refEl = $this->ownerDocument->createElement('xref', $text);
			$refEl = $this->ownerDocument->createElement('xref');
			$refEl->appendChild($this->ownerDocument->createTextNode($text));
			$refEl->setAttribute('ref-type', 'bibr');
			$refEl->setAttribute('rid', Reference::JATS_REF_ID_PREFIX . $id);
			$this->appendChild($refEl);
			// Insert separator between references
			if ($key !== $lastKey) {
				$this->appendChild($this->ownerDocument->createTextNode($separator));
			}
		}

		if ($closeGroup) {
			$this->appendChild($this->ownerDocument->createTextNode($closeGroup));
		}
	}
}
