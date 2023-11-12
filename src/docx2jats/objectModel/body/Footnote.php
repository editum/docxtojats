<?php namespace docx2jats\objectModel\body;

/**
 * @file src/docx2jats/objectModel/body/Footnote.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @brief represents a footnote with format
 */

use docx2jats\objectModel\DataObject;
use docx2jats\objectModel\Document;
use DOMElement;

class Footnote extends DataObject {
	const TYPE = 'fn';

	private $id;
	private $content;

	/**
	 * @param \DOMElement $content
	 * @param Document $ownerDocument
	 * @param string $id
	 * @param \DOMElement[] $content
	 */
	public function __construct(\DOMElement $domElement, Document $ownerDocument, string $id, array $content) {
		parent::__construct($domElement, $ownerDocument);
		$this->id = $id;
		$this->content = $content;
	}

	public function getId()
	{
		return $this->id;
	}

	public function getContent()
	{
		return $this->content;
	}
}
