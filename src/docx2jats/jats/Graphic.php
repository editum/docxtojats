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
use DomElement;

class Graphic extends Element
{
	public function __construct(DataObject $dataObject)
	{
		DomElement::__construct('graphic');
		$this->dataObject = $dataObject;
	}

	public function setContent()
	{
		/** @var FigureObject $dataObject */
		$dataObject = $this->getDataObject();
		$pathInfo = pathinfo($dataObject->getLink());

		// Mimetype
		$this->setAttribute('mimetype', 'image');
		switch ($pathInfo['extension']) {
			case 'jpg':
			case 'jpeg':
				$subtype = 'jpeg';
				break;
			case 'png':
				$subtype = 'png';
				break;
		}
		$this->setAttribute('mime-subtype', $subtype);
		// Link
		$this->setAttribute('xlink:href', $pathInfo['basename']);
	}
}
