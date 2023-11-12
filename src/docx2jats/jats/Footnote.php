<?php namespace docx2jats\jats;

/**
 * @file src/docx2jats/jats/Footnote.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @brief represents JATS XML Footnote
 */

use docx2jats\jats\traits\Container;
use docx2jats\objectModel\body\Footnote as ModelFootnote;
use docx2jats\objectModel\body\Par as ModelPar;

class Footnote extends Element {

	use Container;

	const JATS_FOOTNOTE_ID_PREFIX = 'fn';

	public function __construct(ModelFootnote $dataObject) {
		parent::__construct($dataObject);
	}

	public function setContent(string $prefix = null) {
		/** @var ModelFootnote */
		$mfn = $this->getDataObject();
		$this->setAttribute('id', $mfn::TYPE .$mfn->getId());
		$this->appendChild($this->ownerDocument->createElement('label', $mfn->getId()));
		// Append only paragraphs since jats doesn't accept anything else
		foreach ($mfn->getContent() as $dataObject) {
			if ($dataObject instanceof ModelPar) {
				if (in_array(ModelPar::DOCX_PAR_REGULAR, $dataObject->getType()))
					$this->appendContent($dataObject);
				else
					$this->appendChild($this->ownerDocument->createElement('p', $dataObject->toString()));
			}
		}
	}
}
