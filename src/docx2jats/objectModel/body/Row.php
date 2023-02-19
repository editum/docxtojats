<?php namespace docx2jats\objectModel\body;

/**
 * @file src/docx2jats/objectModel/body/Row.php
 *
 * Copyright (c) 2018-2019 Vitalii Bezsheiko
 * Distributed under the GNU GPL v3.
 *
 * @brief represents table row
 */

use docx2jats\objectModel\DataObject;
use docx2jats\objectModel\body\Cell;
use docx2jats\objectModel\Document;

class Row extends DataObject {

	private $properties = array();
	private $cells = array();

	public function __construct(\DOMElement $domElement, Document $ownerDocument, Table $parent) {
		parent::__construct($domElement, $ownerDocument, $parent);
		$this->properties = $this->setProperties('w:trPr/child::node()');
		$this->cells = $this->setContent('w:tc');
	}

	private function setContent(string $xpathExpression) {
		$content = array();
		$contentNodes = $this->getXpath()->query($xpathExpression, $this->getDomElement());
		if ($contentNodes->count() > 0) {
			$cellNumber = 1;
			foreach ($contentNodes as $contentNode) {
				// Omit merged nodes
				$colspansMerged = $this->getXpath()->query('w:tcPr/w:vMerge', $contentNode);
				if ($colspansMerged->count() > 0 && (! $colspansMerged[0]->hasAttribute('w:val') || $colspansMerged[0]->getAttribute('w:val') == 'continue')) {
					$cellNumber++;
				} else {
					$cell = new Cell($contentNode, $cellNumber, $this->getOwnerDocument(), $this);
					$content[] = $cell;
					$cellNumber += $cell->getColspan();
				}
			}
		}

		return $content;
	}

	public function getContent() {
		return $this->cells;
	}
}
