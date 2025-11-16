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

	private const P_ELEMENTS_NOT_ALLOWED_IN_TD = [
		'address',
		'boxed-text',
		'fig',
		'fig-group',
		'supplementary-material',
		'table-wrap',
		'table-wrap-group',
		'award-id',
		'funding-source',
		'open-access',
		'ack',
		'disp-quote',
		'speech',
		'statement',
		'verse-group',
	];

	public function __construct(DataObject $dataObject) {
		parent::__construct($dataObject);
	}

	public function setContent() {
		/** @var BodyCell $dataObject */
		$dataObject = $this->getDataObject();
		$this->setAttribute('align', $dataObject->getAlign());
		$colspan = $dataObject->getColspan(true);
		if ($colspan > 1)
			$this->setAttribute('colspan', $colspan);
		$rowspan = $dataObject->getRowspan();
		if ($rowspan > 1)
			$this->setAttribute('rowspan', $rowspan);

		// $this->tr->tbody->table->tablewrap->getAttribute('id')
		//@$tid = $this->parentNode->parentNode->parentNode->parentNode->getAttribute('id');
		$tid = $this->getAttribute('id');
		foreach ($dataObject->getContent() as $content) {
			$this->appendContent($content, $this, $tid);
		}

		// If there is one paragraph or the content is ment to be inline, remove the paragraph
		if ($this->childNodes->count() == 1 
			&& $this->childNodes[0] instanceof \DOMElement
			&& $this->childNodes[0]->tagName === 'p'
			&& !$this->hasForbiddenElements($this->childNodes[0])
		){
			$p = $this->childNodes[0];
			foreach ($p->childNodes as $child) {
				$this->appendChild($child);
			}
			$this->removeChild($p);
		}
	}

	/**
	 * Check if a <p> contains any element not allowed as direct child of a <td>.
	 *
	 * @param \DOMElement $p a paragraph
	 * @return bool true when there is an element not allowed directly in a <td>
	 */
	function hasForbiddenElements(\DOMElement $p): bool
	{
		$forbidden = self::P_ELEMENTS_NOT_ALLOWED_IN_TD;

		foreach ($p->getElementsByTagName('*') as $child) {
			$name = $child->localName;
			if (in_array($name, $forbidden, true)) {
				return true;
			}
	    }
		return false;
	}
}
