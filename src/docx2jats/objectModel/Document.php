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
use Seboettg\CiteProc\StyleSheet;
use Seboettg\CiteProc\CiteProc;
use DOMXPath;

class Document {
	use Container;

	const SECT_NESTED_LEVEL_LIMIT = 5; // limit the number of possible levels for sections
	const CSL_DEFAULT_LOCALE = 'en-EN';

	// Represent styling for OOXMl structure elements
	const DOCX_STYLES_PARAGRAPH = "paragraph";
	const DOCX_STYLES_CHARACTER = "character";
	const DOCX_STYLES_NUMBERING = "numbering";
	const DOCX_STYLES_TABLE = "table";

	private $ooxmlDocument;

	// Metadata
	/** @var string */
	private $creator = '';
	/** @var string */
	private $lastModifiedBy = '';
	/** @var string */
	private $language = '';
	/** @var string */
	private $revision = '';
	/** @var string */
	private $title = '';
	/** @var string */
	private $subject = '';
	/** @var string */
	private $description = '';
	/** @var string */
	private $keywords = '';

	static $xpath;
	private $content;
	private static $minimalHeadingLevel;

	/** @var \DOMDocument document metadata like tittle, language, etc */
	private $metadata;
	static $metadataXpath;

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

	/**
	 * @var DataObject[] contains all footnotes from the document footnotes.xml already parsed
	 * as they were a w:body each.
	 */
	private $footnotesContent;

	/**
	 * @var DataObject[] contains all footnotes from the document footnotes.xml already parsed
	 * as they were a w:body each.
	 */
	private $endnotesContent;

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

	// CSL style and locale extracted from custom.xml
	/** @var ?string */
	private $cslStyle = null;
	/** @var ?string */
	private $cslLocale = null;
	/** @var \stdClass[] array of csl references used in post-process to set the bibliography depending on style and locale */
	private $cslReferences;


	public function __construct(\DOMDocument $ooxmlDocument, 
			?\DOMDocument $metadata,
			?\DOMDocument $partRelationships,
			?\DOMDocument $styles,
			?\DOMDocument $numbering,
			?\DOMDocument $footnotes,
			?\DOMDocument $endnotes,
			?\DOMDocument $docPropsCustom
	) {

		$this->metadata = $metadata;
		if ($this->metadata) {
			self::$metadataXpath = new \DOMXPath($this->metadata);
			$this->extractMetadata();
		}

		$this->relationships = $partRelationships;
		if ($this->relationships)
			self::$relationshipsXpath = new \DOMXPath($this->relationships);

		$this->styles = $styles;
		if ($this->styles)
			self::$stylesXpath = new \DOMXPath($this->styles);

		$this->numbering = $numbering;
		if ($this->numbering)
			self::$numberingXpath = new \DOMXPath($this->numbering);

		$this->docPropsCustom = $docPropsCustom;
		if ($this->docPropsCustom) {
			self::$docPropsCustomXpath = new \DOMXPath($this->docPropsCustom);
			$this->extractProperties(self::$docPropsCustomXpath);
		}

		$this->ooxmlDocument = $ooxmlDocument;
		self::$xpath = new \DOMXPath($this->ooxmlDocument);
		$this->findBookmarks();

		// Append footnotes to the document with format, as they were another '//w:body' each
		$this->footnotesContent = [];
		if ($footnotes) {
			$footnotesXpath = new \DOMXPath($footnotes);
			$footnotes = $footnotesXpath->query('//w:footnotes')->item(0);
			$this->extractNotes($footnotes, 'w:footnote', $this->footnotesContent);
		}

		// Append endnotes to the document with format, as they were another '//w:body' each
		$this->endnotesContent = [];
		if ($endnotes) {
			$endnotesXpath = new \DOMXPath($endnotes);
			$endnotes = $endnotesXpath->query('//w:endnotes')->item(0);
			$this->extractNotes($endnotes, 'w:endnote', $this->endnotesContent);
		}

		$content = $this->setContent(self::$xpath->query('//w:body')[0]);
		$this->content = $this->addSectionMarks($content);
		self::$minimalHeadingLevel = $this->minimalHeadingLevel();
		$this->setInternalRefs();

		/* Post-process citations with CiteProc to obtain the bibliography based on the style.
		CiteProc is not ment for this and autosorts the elements.. so we use referenceId to match the reference.
		// TODO: find a better way to do this */
		if ($this->getcslStyle()) {
			try {
				$style = StyleSheet::loadStyleSheet($this->getcslStyle());
				$citeProc = new CiteProc($style, $this->getcslLocale(), [
					'bibliography' => [ 
						'csl-entry' => function($cslItem, $renderedText) {
							return sprintf('%06d:%s', $cslItem->referenceId, $renderedText);
						},
					]
				]);
				$bibliography = $citeProc->render($this->cslReferences, 'bibliography');
				$bibliography = explode("\n",rtrim(strip_tags(html_entity_decode($bibliography))));
				sort($bibliography);
				foreach ($bibliography as $text) {
					if (preg_match('/0*(\d+):(.+)/', trim($text), $m, PREG_OFFSET_CAPTURE))
						$this->references[$m[1][0]]->setBibliography($m[2][0]);
				}
			} catch (\Throwable $th) {}
		}
	}

	/**
	 * Returns the csl style found in custom.xml.
	 * If the csl locale is nowhere to be found it will fallback to the documents language detected, en-EN otherwise.
	 * @return string
	 */
	public function getcslLocale(): string
	{
		if (! $this->cslLocale)
			if (! $this->cslLocale = $this->getLanguage())
				$this->cslLocale = self::CSL_DEFAULT_LOCALE;
		return $this->cslLocale;
	}

	/**
	 * Rturns the csl style found in custom.xml.
	 * @return string|null
	 */
	public function getcslStyle(): ?string
	{
		return $this->cslStyle;
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
	 * Returns from metadata: the document creator.
	 * @return string
	 */
	public function getCreator(): string {
		return $this->creator;
	}

	/**
	 * Returns from metadata: last user who modified the document.
	 * @return string
	 */
	public function getLastModifiedBy(): string {
		return $this->lastModifiedBy;
	}

	/**
	 * Returns from metadata: document's language
	 * @param bool $primary if true returns the primary-language subtag
	 * @return string
	 */
	public function getLanguage(bool $primary = true): string {
		return preg_replace('/-\w+$/', '', $this->language);
	}

	/**
	 * Returns from metadata: document's revision
	 * @return string
	 */
	public function getRevision(): string {
		return $this->revision;
	}

	/**
	 * Returns from metadata: document's title
	 * @return string
	 */
	public function getTitle(): string {
		return $this->title;
	}

	/**
	 * Returns from metadata: document's subject/subtitle
	 * @return string
	 */
	public function getSubject(): string {
		return $this->subject;
	}

	/**
	 * Returns from metadata: document's description/abstract
	 * @return string
	 */
	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * Returns from metadata: raw keywords
	 */
	public function getKeywords(): string {
		return $this->keywords;
	}

	/**
	 * @return array
	 */
	public function getContent(): array {
		return $this->content;
	}

	/**
	 * Returns the content of a footnote reference.
	 *
	 * @return ?\DomElement[]
	 */
	public function getFootnoteContent(string $id)
	{
		return $this->footnotesContent[$id] ?? null;
	}

	/**
	 * Returns the content of a endnote reference.
	 *
	 * @return ?\DomElement[]
	 */
	public function getEndnoteContent(string $id)
	{
		return $this->endnotesContent[$id] ?? null;
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
	 * Extract metadata from docProps/core.xml
	 */
	private function extractMetadata() {
		$xpath = self::$metadataXpath;
		$this->creator = $xpath->evaluate('string(/cp:coreProperties/dc:creator)');
		$this->lastModifiedBy = $xpath->evaluate('string(/cp:coreProperties/dc:lastModifiedBy)');
		$this->language = $xpath->evaluate('string(/cp:coreProperties/dc:language)');
		$this->revision = $xpath->evaluate('string(/cp:coreProperties/cp:revision)');
		$this->title = $xpath->evaluate('string(/cp:coreProperties/dc:title)');
		$this->subject = $xpath->evaluate('string(/cp:coreProperties/dc:subject)');
		$this->description = $xpath->evaluate('string(/cp:coreProperties/dc:description)');
		$this->keywords = $xpath->evaluate('string(/cp:coreProperties/cp:keywords)');
	}

	/**
	 * Extract properties from custom.xml like the citation styles and locations for zotero and mendeley.
	 */
	private function extractProperties() {
		// Set a namespace so we can perform queries
		$ns = $this->docPropsCustom->lookupnamespaceURI(NULL);
		$xpath = new DOMXPath($this->docPropsCustom);
		$xpath->registerNamespace('ns', $ns);

		// Try to get mendeley citation style
		if ($node = $xpath->query('ns:property[@name="Mendeley Citation Style_1"]')->item(0)) {
			$this->cslStyle = basename($node->nodeValue);
			// TODO missing locale for mendeley
		// Try to get zotero citation style, Zotero sets a stringify xml in the properties
		} else {
			$nodes = $xpath->query('ns:property[contains(@name, "ZOTERO_PREF")]');
			$xml = '';
			foreach ($nodes as $n) {
				$xml.= $n->textContent;
			}
			if ($xml) {
				$zoteroProperties = new \DOMDocument();
				if ($zoteroProperties->loadXML($xml, LIBXML_NOWARNING | LIBXML_NOERROR)) {
					$xpath = new DOMXPath($zoteroProperties);
					if ($zoteroPropertiesNode = $xpath->query('//data[@zotero-version]')->item(0)) {
						$this->cslStyle = basename($xpath->evaluate('string(//style[1]/@id)', $zoteroPropertiesNode));
						$this->cslLocale = $xpath->evaluate('string(//style[1]/@locale)', $zoteroPropertiesNode);
					}
				}
			}
		}
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

	/**
	 * Extract notes from another document, (endnotes.xml, footnotes.xml) and append them to the current document, so it can be parsed
	 * as it was another w:body.
	 *
	 * @param ?\DOMElement $notes the (end|foot)notes node
	 * @param string $querynotes the tag name of the note, ex. w:footnote|w:endnote
	 * @param array& $content wich will containt the already parsed notes using their id as key
	 */
	private function extractNotes(?\DOMElement $notes, string $querynotes, array &$content ): void
	{
		if (! $notes)
			return;
		// Import notes to the current document
		if (! $notes = $this->ooxmlDocument->importNode($notes, true))
			return;
		$this->ooxmlDocument->appendChild($notes);
		$kk = self::$xpath->query($querynotes, $notes);
		foreach (self::$xpath->query($querynotes, $notes) as $note) {
			$id = $note->getAttribute('w:id');
			$content[$id] = $this->setContent($note);
		}
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
		// Used by citeproc to set reference's bibliography in post-process
		if ($reference->hasStructure()) {
			$data = clone $reference->getCSL()->itemData;
			$data->referenceId = $this->refCount;
			$this->cslReferences[] = $data;
		}
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
