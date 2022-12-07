<?php namespace docx2jats\objectModel\body;

/**
 * @file src/docx2jats/objectModel/body/Table.php
 *
 * Copyright (c) 2018-2019 Vitalii Bezsheiko
 * Distributed under the GNU GPL v3.
 *
 * @brief contains table data
 */

use docx2jats\objectModel\Document;

// TODO create a common parent class for Image and Table
class Table extends InfoBlock {

	public static $caption = array("caption");

	protected $tableId = 0;
	protected $properties = array();
	protected $rows = array();
	protected $dummyCells = array();

	public function __construct(\DOMElement $domElement, Document $ownerDocument) {
		parent::__construct($domElement, $ownerDocument);
		$this->properties = $this->setProperties('w:tblPr/child::node()');

		// Find the position of dummy columns that will be used to prune colspan (cells w:w=1)
		$gridcells = $this->getXpath()->query('w:tblGrid/child::node()', $this->getDomElement());
		for ($i=0; $i < $gridcells->count() ; $i++) { 
			$width = $gridcells->item($i)->getAttribute('w:w');
			if ($width <= 1)
				$this->dummyCells[] = $i + 1;
		}

		$this->rows = $this->setContent('w:tr');
	}

	private function setContent(string $xpathExpression) {
		$content = array();

		$contentNodes = $this->getXpath()->query($xpathExpression, $this->getDomElement());
		if ($contentNodes->count() > 0) {
			foreach ($contentNodes as $contentNode) {
				$row = new Row($contentNode, $this->getOwnerDocument(), $this);
				$content[] = $row;
			}
		}

		return $content;
	}

	public function getContent() {
		return $this->rows;
	}

	/**
	 * @param int $currentTableId
	 */
	public function setTableId(int $currentTableId): void {
		$this->tableId = $currentTableId;
	}

	/**
	 * @return int
	 */
	public function getId(): int {
		return $this->tableId;
	}

	/**
	 * @return array position of dummy cells (cells with width 1)
	 */
	public function getDummyCells(): array {
		return $this->dummyCells;
	}

}
