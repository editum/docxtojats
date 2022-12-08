<?php namespace docx2jats\jats;

/**
 * @file src/docx2jats/jats/Cell.php
 *
 * Copyright (c) 2018-2019 Vitalii Bezsheiko
 * Distributed under the GNU GPL v3.
 *
 * @brief represents JATS XML table's cell
 */

use docx2jats\objectModel\DataObject;
use docx2jats\jats\traits\Container;
use docx2jats\objectModel\body\Cell as BodyCell;

class Cell extends Element {
	use Container;

	public function __construct(DataObject $dataObject) {
		parent::__construct($dataObject);
	}

	public function setContent() {
		/** @var BodyCell $dataObject */
		$dataObject = $this->getDataObject();
		$colspan = $dataObject->getColspan(true);
		$rowspan = $dataObject->getRowspan();
		if ($colspan > 1) {
			$this->setAttribute('colspan', $colspan);
		}

		if ($rowspan > 1) {
			$this->setAttribute('rowspan', $rowspan);
		}

		@$tid = $this->parentNode->parentNode->parentNode->getAttribute('id');
		foreach ($dataObject->getContent() as $content) {
			$this->appendContent($content, $this, $tid);
		}
	}
}
