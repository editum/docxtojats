<?php namespace docx2jats\objectModel;

/**
 * @file src/docx2jats/objectModel/Document.php
 *
 * Copyright (c) 2018-2020 Vitalii Bezsheiko
 * Distributed under the GNU GPL v3.
 *
 * @brief representation of an article; extracts all main elements from DOCX document.xml
 */

use docx2jats\jats\Figure;
use docx2jats\objectModel\DataObject;
use docx2jats\objectModel\body\Par;
use docx2jats\objectModel\body\Table;
use docx2jats\objectModel\body\Image;
use docx2jats\objectModel\body\Reference;
use docx2jats\objectModel\Document as ObjectModelDocument;
use docx2jats\objectModel\traits\Container;
use DOMXPath;

class Document {
	use Container;

	const SECT_NESTED_LEVEL_LIMIT = 5; // limit the number of possible levels for sections

	// Represent styling for OOXMl structure elements
	const DOCX_STYLES_PARAGRAPH = "paragraph";
	const DOCX_STYLES_CHARACTER = "character";
	const DOCX_STYLES_NUMBERING = "numbering";
	const DOCX_STYLES_TABLE = "table";

	static $xpath;
	private $content;
	private static $minimalHeadingLevel;

	/* @var $relationships \DOMDocument contains relationships between document elements, e.g. the link and its target */
	private $relationships;
	static $relationshipsXpath;

	/* @var $styles \DOMDocument represents document styles, e.g., paragraphs or lists styling */
	private $styles;
	static $stylesXpath;

	/* @var $numbering \DOMDocument represents info about list/heading level and style */
	private $numbering;
	static $numberingXpath;

	/**
	 * @var $docPropsCustom \DOMDocument represents custom properties of the document,
	 * e.g., Mendeley plugin for LibreOffice Writer exports CSL in this file
	 */
	private $docPropsCustom;
	static $docPropsCustomXpath;

	private $references = array();
	private $refCount = 0;

	// Set unique IDs for tables and figure in order of appearance
	private $currentFigureId = 1;
	private $currentTableId = 1;

	/**
	 * @var $parsHaveBookmarks array
	 * @brief Key numbers of paragraphs that contain bookmarks inside the content
	 * is used to speed up a search
	 */
	private $elsHavefldCharRefs = array();
	private $elsAreTables = array();
	private $elsAreFigures = array();

	/**
	 * @var array bookmark id => name
	 */
	public $bookMarks = array();

	public function __construct(array $params) {
		if (array_key_exists("partRelationships", $params)) {
			$this->relationships = $params["partRelationships"];
			self::$relationshipsXpath = new \DOMXPath($this->relationships);
		}

		if (array_key_exists("styles", $params)) {
			$this->styles = $params["styles"];
			self::$stylesXpath = new \DOMXPath($this->styles);
		}

		if (array_key_exists("numbering", $params)) {
			$this->numbering = $params["numbering"];
			self::$numberingXpath = new \DOMXPath($this->numbering);
		}

		if (array_key_exists("docPropsCustom", $params)) {
			$this->docPropsCustom = $params["docPropsCustom"];
			self::$docPropsCustomXpath = new \DOMXPath($this->docPropsCustom);
		}

		self::$xpath = new \DOMXPath($params["ooxmlDocument"]);
		$this->findBookmarks();

		$content = $this->setContent(self::$xpath->query('//w:body')[0]);
		$this->content = $this->addSectionMarks($content);
		self::$minimalHeadingLevel = $this->minimalHeadingLevel();
		$this->setInternalRefs();
	}

	private function getXpath(): DOMXPath
	{
		return self::$xpath;
	}

	public function getOwnerDocument(): ?Document
	{
		return $this;
	}

	/**
	 * @return array
	 */
	public function getContent(): array {
		return $this->content;
	}

	private function minimalHeadingLevel(): int {
		$minimalNumber = 7;
		foreach ($this->content as $dataObject) {
			if (get_class($dataObject) === "docx2jats\objectModel\body\Par" && in_array(Par::DOCX_PAR_LIST, $dataObject->getType())) {
				$number = $dataObject->getHeadingLevel();
				if ($number && $number < $minimalNumber) {
					$minimalNumber = $number;
				}
			}
		}

		return $minimalNumber;
	}

	/**
	 * @return int
	 */
	public static function getMinimalHeadingLevel(): int {
		return self::$minimalHeadingLevel;
	}

	/**
	 * @param array $content
	 * @return array
	 * @brief set marks for the section, number in order and specific ID for nested sections
	 */
	private function addSectionMarks(array $content): array {

		$flatSectionId = 0; // simple section id
		$dimensions = array_fill(0, self::SECT_NESTED_LEVEL_LIMIT, 0); // contains dimensional section id
		foreach ($content as $key => $object) {
			if (get_class($object) === "docx2jats\objectModel\body\Par" && $object->getType() && $object->getHeadingLevel()) {
				$flatSectionId++;
				$dimensions = $this->extractSectionDimension($object, $dimensions);
			}

			$object->setDimensionalSectionId($dimensions);
			$object->setFlatSectionId($flatSectionId);
		}

		return $content;
	}

	/**
	 * @param $object Par
	 * @param array $dimensions
	 * @param int $n
	 * @return array
	 * @brief for internal use, defines dimensional section id for a given Par heading
	 */
	private function extractSectionDimension(Par $object, array $dimensions): array
	{
		$number = $object->getHeadingLevel() - 1;
		$dimensions[$number]++;
		while ($number < self::SECT_NESTED_LEVEL_LIMIT) {
			$number++;
			$dimensions[$number] = 0;
		}
		return $dimensions;
	}

	static function getRelationshipById(string $id): string {
		$element = self::$relationshipsXpath->query("//*[@Id='" .  $id ."']");
		$target = $element[0]->getAttribute("Target");
		return $target;
	}

	static function getElementStyling(string $constStyleType, string $id): ?string {
		/* @var $element \DOMElement */
		/* @var $name \DOMElement */
		if (self::$stylesXpath) {
			$element = self::$stylesXpath->query("/w:styles/w:style[@w:type='" . $constStyleType . "'][@w:styleId='" . $id . "']")[0];
			$name = self::$stylesXpath->query("w:name", $element)[0];
			return $name->getAttribute("w:val");
		} else {
			return null;
		}
	}

	static function getBuiltinStyle(string $constStyleType, string $id, array $builtinStyles): ?string {
		// Traverse the chain of styles to see if the named id style
		// inherits from one of the sought-for built-in styles and
		// return the one that matches.
		if (self::$stylesXpath) {
			do {
				$element = self::$stylesXpath->query("/w:styles/w:style[@w:type='" . $constStyleType . "'][@w:styleId='" . $id . "']")[0];

				$basedOn = self::$stylesXpath->query("w:basedOn", $element)[0];
				$id = $basedOn ? $basedOn->getAttribute("w:val") : null;

				$name = self::$stylesXpath->query("w:name", $element)[0];
				if (!$name) return null;
				$styleName = $name->getAttribute("w:val");

				if (in_array(strtolower($styleName), $builtinStyles)) return $styleName;
			} while($id);

			return null;
		} else {

			// Fall back on using the original id as if it were the name
			if (in_array($id, $builtinStyles)) return $id;
			else return null;
		}
	}

	/**
	 * @param string $id
	 * @param string $lvl
	 * @return string|null
	 * @brief Find w:num element by value of m:val attribute; retrieve w:abstractNumId element's m:val value;
	 * Find w:abstractNum by a value of w:abstractNumId; retrieve the type of the list by the value of w:numFmt element under
	 * w:lvl element with a correspondent value (see $lvl parameter)
	 */
	static function getNumberingTypeById(string $id, string $lvl): ?string {
		if (!self::$numberingXpath) return null; // the numbering styles are missing.

		$abstractNumIdEl = self::$numberingXpath->query("//w:num[@w:numId='" . $id . "']/w:abstractNumId");
		if ($abstractNumIdEl->count() == 0) return null;

		$abstractNumId = $abstractNumIdEl[0]->getAttribute("w:val");

		$element = self::$numberingXpath->query("//*[@w:abstractNumId='" . $abstractNumId . "']");
		if ($element->count() == 0) return null;

		$level = self::$numberingXpath->query("w:lvl[@w:ilvl='" . $lvl . "']", $element[0]);
		if ($level->count() == 0) return null;

		$type = self::$numberingXpath->query("w:numFmt/@w:val", $level[0]);
		if ($type->count() == 0) return null;

		return $type[0]->nodeValue;
	}

	public function addReference(Reference $reference) {
		$this->refCount++;
		$reference->setId($this->refCount);
		$this->references[$this->refCount] = $reference;
	}

	public function getReferences() : array {
		return $this->references;
	}

	public function getLastReference() : ?Reference {
		$lastId = array_key_last($this->references);
		return $this->references[$lastId];
	}

	/**
	 * @brief iterate through the content and establish internal links between element
	 * elsHaveBookmarks holds position in an array of each paragraph that includes a bookmark
	 * it's slightly faster than looping over the whole content
	 */
	private function setInternalRefs(): void {
		if (empty($this->elsHavefldCharRefs)) return;

		// Find and map tables' and figures' bookmarks
		$refTableMap = $this->getBookmarkCaptionMapping($this->elsAreTables);
		$refFigureMap = $this->getBookmarkCaptionMapping($this->elsAreFigures);

		// Find bookmark refs
		foreach ($this->elsHavefldCharRefs as $parKeyWithBookmark) {
			$par = $this->getContent()[$parKeyWithBookmark]; /* @var $par Par */
			foreach ($par->fldCharRefPos as $fieldKeyWithBookmark) {
				$field = $par->getContent()[$fieldKeyWithBookmark]; /* @var $field \docx2jats\objectModel\body\Field */

				// Set links to tables
				foreach ($refTableMap as $tableId => $tableRefs) {
					if (in_array($field->getFldCharRefId(), $tableRefs)) {
						$field->tableIdRef = $tableId;
					}
				}

				// Set links to Figures
				foreach ($refFigureMap as $figureId => $figureRefs) {
					if (in_array($field->getFldCharRefId(), $figureRefs)) {
						$field->figureIdRef = $figureId;
					}
				}
			}
		}
	}

	/**
	 * @return array
	 * @brief (or not so brief) Map OOXML bookmark refs inside tables and figures with correspondent table/figure IDs.
	 * In OOXML those bookmarks are stored inside captions
	 * This is used to set right link to these objects from the text
	 * Keep in mind that bookmarks also may be stored in an external file, e.g., Mendeley plugin for LibreOffice Writer
	 * stores links to references this way
	 */
	function getBookmarkCaptionMapping(array $keysInContent): array {
		$refMap = [];
		foreach ($keysInContent as $tableKey) {
			$table = $this->content[$tableKey]; /* @var $table Table|Image */
			if (empty($table->getBookmarkIds())) continue;
			$refMap[$table->getId()] = $table->getBookmarkIds();
		}

		return $refMap;
	}

	/**
	 * Find and retrieve id and name from all bookmarks in the main document part
	 */
	private function findBookmarks(): void {
		$bookmarkEls = self::$xpath->query('//w:bookmarkStart');
		foreach ($bookmarkEls as $bookmarkEl) {
			$this->bookMarks[$bookmarkEl->getAttribute('w:id')] = $bookmarkEl->getAttribute('w:name');
		}
	}

	public function docPropsCustom() {
		return $this->docPropsCustom;
	}
}
