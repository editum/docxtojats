<?php namespace docx2jats\jats;

/**
* @file src/docx2jats/jats/Figure.php
*
* Copyright (c) 2018-2020 Vitalii Bezsheiko
* Distributed under the GNU GPL v3.
*
* @brief represent JATS XML image
*/

use docx2jats\objectModel\DataObject;
use docx2jats\objectModel\body\Image as FigureObject;
use docx2jats\jats\Graphic as JatsGraphic;

class Figure extends Element {
	const JATS_FIGURE_ID_PREFIX = 'fig';

	/* @var $dataObject FigureObject */
	var $figureObject;

	public function __construct(DataObject $dataObject) {
		parent::__construct($dataObject);

		$this->figureObject = $dataObject;
	}

	function setContent() {
		$dataObject = $this->getDataObject(); /* @var $dataObject \docx2jats\objectModel\body\Image */

		if ($dataObject->getId()) {
			$this->setAttribute('id', self::JATS_FIGURE_ID_PREFIX . $dataObject->getId());
		}

		if ($dataObject->getLabel()) {
			$this->appendChild($this->ownerDocument->createElement('label', $dataObject->getLabel()));
		}

		if ($dataObject->getTitle()) {
			$captionNode = $this->ownerDocument->createElement('caption');
			$this->appendChild($captionNode);
			$title = $this->ownerDocument->createElement('title', $dataObject->getTitle());
			// append citation if exists
			if ($dataObject->hasReferences()) {
				$refIds = $dataObject->getRefIds();
				$lastKey = array_key_last($refIds->getRefIds());
				foreach ($refIds as $key => $id) {
					$refEl = $this->ownerDocument->createElement('xref', $id);
					$refEl->setAttribute('ref-type', 'bibr');
					$refEl->setAttribute('rid', Reference::JATS_REF_ID_PREFIX . $id);
					if ($key !== $lastKey) {
						$empty = $this->ownerDocument->createTextNode(' ');
						$title->appendChild($empty);
					}
					$title->appendChild($refEl);
				}
			}
			// TODO bug??
			$captionNode->appendChild();
		}

		$figureNode = new JatsGraphic($this->getDataObject());
		$this->appendChild($figureNode);
		$figureNode->setContent();
	}
}
