<?php namespace docx2jats\jats;

/**
 * @file src/docx2jats/jats/Row.php
 *
 * Copyright (c) 2018-2019 Vitalii Bezsheiko
 * Distributed under the GNU GPL v3.
 *
 * @brief represent JATS XML tables' row
 */

use docx2jats\objectModel\DataObject;
use docx2jats\jats\Cell as JatsCell;

class Row extends Element {
	public function __construct(DataObject $dataObject) {
		parent::__construct($dataObject);
	}

	public function setContent() {
		// Set an id for the cell container to be used as prefix for the elements created there
		// After all cells have been set, the id can be removed
		$i = 1;
		foreach ($this->getDataObject()->getContent() as $content) {
			$cell = new JatsCell($content);
			$this->appendChild($cell);
			$cell->setAttribute('id', $this->getAttribute('id').'c'.$i++);
			$cell->setContent();
			$cell->removeAttribute('id');
		}
	}
}
