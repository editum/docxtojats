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

// REVIEW separate footnotes from endnotes, former probably should be in the back section
class Footnote extends Element {

	use Container;

	const JATS_FOOTNOTE_ID_PREFIX = 'fn';

	// Labels assignated to their ids
	private static array $idLabels = [];
	// Next label avaliable
	private static array $nextLabel = [];

	public function __construct(ModelFootnote $dataObject) {
		if (! isset(self::$nextLabel[$dataObject::TYPE])) {
			self::$nextLabel[$dataObject::TYPE] = 1;
		}
		parent::__construct($dataObject);
	}

	public function setContent(string $prefix = null) {
		/** @var ModelFootnote */
		$mfn = $this->getDataObject();
		$id = $mfn::TYPE .$mfn->getId();
		$this->setAttribute('id', $id);
		$this->appendChild($this->ownerDocument->createElement('label', $this->getLabel($id)));
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

	/**
	 * Get the label associated to an id or creates the next one in numerical order.
	 * It has into account the type of object: footnote, endnote.
	 */
	public function getLabel(string $id): string {
		if (isset(self::$idLabels[$id])) {
			return self::$idLabels[$id];
		}
		/** @var ModelFootnote */
		$obj = $this->getDataObject();
		$label = self::$idLabels[$id] = self::$nextLabel[$obj::TYPE]++;
		return $label;
	}
}
