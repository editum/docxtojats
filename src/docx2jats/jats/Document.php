<?php namespace docx2jats\jats;

/**
 * @file src/docx2jats/jats/Document.php
 *
 * Copyright (c) 2018-2019 Vitalii Bezsheiko
 * Distributed under the GNU GPL v3.
 *
 * @brief represent JATS XML Document; transfers the data from Document Object Model to PHP DOMDocument
 */

use docx2jats\DOCXArchive;
use docx2jats\jats\Par as JatsPar;
use docx2jats\objectModel\body\Par;
use docx2jats\jats\JList as JatsList;
use docx2jats\jats\Table as JatsTable;
use docx2jats\jats\Figure as JatsFigure;
use docx2jats\objectModel\body\Table;
use docx2jats\objectModel\DataObject;
use docx2jats\objectModel\Document as DOCXDocument;

class Document extends \DOMDocument {
	const JATS_LIST_TYPES = [
		Par::DOCX_LIST_TYPE_SIMPLE => 'simple',
		Par::DOCX_LIST_TYPE_UNORDERED => 'bullet',
		Par::DOCX_LIST_TYPE_ORDERED => 'order',
		Par::DOCX_LIST_TYPE_ALPHA_LOWER => 'alpha-lower',
		Par::DOCX_LIST_TYPE_ALPHA_UPPER => 'alpha-upper',
		Par::DOCX_LIST_TYPE_ROMAN_LOWER => 'lower-roman',
		Par::DOCX_LIST_TYPE_ROMAN_UPPER => 'upper-roman',
	];

	/* @var $docxArchive \docx2jats\DOCXArchive */
	private $docxArchive;

	/* @var $article \DOMElement */
	var $article;

	/* @var $front \DOMElement */
	var $front;

	/* @var $body \DOMElement */
	var $body;

	/* @var $back \DOMElement */
	var $back;

	/* @var $sections array of DOMElements */
	var $sections = array();

	/* @var $references array of DOMElements */
	var $references = array();

	/* @var $lists array of DOMElements; contains all article's lists, key -> unique list ID, corresponds to ID in numbering.xml */
	var $lists = array();
	private $listChunks = [];
	private $listLvlTypes = [];

	public function __construct(DOCXArchive $docxArchive) {
		parent::__construct('1.0', 'utf-8');
		$this->docxArchive = $docxArchive;
		$this->preserveWhiteSpace = false;
		$this->formatOutput = true;

		// Doctype
		$impl = new \DOMImplementation();
		$this->appendChild($impl->createDocumentType("article", "-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN", "https://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd"));

		$this->setBasicStructure();
		$this->extractContent();
		$this->cleanContent();
		$this->extractMetadata();
		$this->extractReferences();
	}

	public function getJatsFile(string $pathToFile) {
		$this->save($pathToFile);
	}

	private function setBasicStructure() {
		$this->article = $this->createElement('article');
		$this->article->setAttributeNS(
			"http://www.w3.org/2000/xmlns/",
			"xmlns:xlink",
			"http://www.w3.org/1999/xlink"
		);

		$this->appendChild($this->article);

		$this->front = $this->createElement('front');
		$this->article->appendChild($this->front);

		$this->body = $this->createElement('body');
		$this->article->appendChild($this->body);

		$this->back = $this->createElement('back');
		$this->article->appendChild($this->back);
	}

	private function extractContent() {
		$document = $this->docxArchive->getDocument();
		if (!empty($document->getContent())) {

			$latestSectionId = array();
			$latestSections = array();

			$listItem = null; // temporary container for previous list item
			$isPrevNodeList = false; // true when in list

			foreach ($document->getContent() as $key => $content) {
				$contentId = 'sec-' . implode('_', $content->getDimensionalSectionId());

				// Appending section, must correspond section nested level; TODO optimize with recursion
				if ($content->getDimensionalSectionId() !== $latestSectionId) {
					$sectionNode = $this->createElement("sec");
					$sectionNode->setAttribute('id', $contentId);
					$this->sections[$contentId] = $sectionNode;
					if (count($content->getDimensionalSectionId()) === 1) {
						$this->body->appendChild($sectionNode);
						$latestSections[0] = $sectionNode;
					} elseif (count($content->getDimensionalSectionId()) === 2) {
						$latestSections[0]->appendChild($sectionNode);
						$latestSections[1] = $sectionNode;
					} elseif (count($content->getDimensionalSectionId()) === 3) {
						$latestSections[1]->appendChild($sectionNode);
						$latestSections[2] = $sectionNode;
					} elseif (count($content->getDimensionalSectionId()) === 4) {
						$latestSections[2]->appendChild($sectionNode);
						$latestSections[3] = $sectionNode;
					} elseif (count($content->getDimensionalSectionId()) === 5) {
						$latestSections[3]->appendChild($sectionNode);
					}

					$latestSectionId = $content->getDimensionalSectionId();
				}

				// If there aren't any sections, append content to the body
				if (empty($this->sections)) {
					$sectionsOrBody = array($this->body);
				} else {
					$sectionsOrBody = $this->sections;
				}

				switch (get_class($content)) {
					case "docx2jats\objectModel\body\Par":
						/* @var $content Par */
						$jatsPar = new JatsPar($content);

						foreach ($sectionsOrBody as $section) {
							if ($contentId === $section->getAttribute('id') || $section->nodeName === "body") {
								if (!in_array(Par::DOCX_PAR_LIST, $content->getType())) {
									$section->appendChild($jatsPar);
									$jatsPar->setContent();
									$isPrevNodeList = false;
								} elseif (!in_array(Par::DOCX_PAR_HEADING, $content->getType())) {
									$nid = $content->getNumberingId();
									$lvl = $content->getNumberingLevel()+1;
									$iid = count($content->getNumberingItemProp()[Par::DOCX_LIST_ITEM_ID]);
									$id = sprintf("%s-lst-%d", $contentId, $nid);

									// New list
									if (! array_key_exists($id, $this->listChunks)) {
										$this->listChunks[$id] = [];
										$isPrevNodeList = false;
									}
									// New chunk
									if (! $isPrevNodeList) {
										$chunk = count($this->listChunks[$id]);
										$list = $this->createElement('list');
										$list->setAttribute('id', $id.'_'.$chunk);
										//$list->setAttribute("list-type", self::JATS_LIST_TYPES[$content->getNumberingType()]);
										$section->appendChild($list);
										$this->listChunks[$id][$chunk] = &$list;
										$this->lists[$id.'_'.$chunk] = &$list;
									// Chunk found
									} else {
										$chunk = count($this->listChunks[$id]) - 1;
										$list = &$this->listChunks[$id][$chunk];
									}
									// Update latest list types so they are available to other chunks where the first item is in a sublist
									$type = $this->listLvlTypes[$id][$lvl] = self::JATS_LIST_TYPES[$content->getNumberingType()];

									// TODO what is this comparison?
									if ($iid === $lvl) {
										// Search/Create sublists
										for ($i=1; $i < $lvl; $i++) {
											// Propagate list type useful in chunks when the first item is a subitem
											if (isset($this->listLvlTypes[$id][$i]))
												$list->setAttribute('list-type', $this->listLvlTypes[$id][$i]);
											// Get sublist from last node
											$k = &$list->lastChild;
											if ($k == null) {
												$k = $this->createElement('list-item');
												$list->appendChild($k);
											}
											$l = &$k->lastChild;
											// Create it otherwhise
											if ($l == null || $l->nodeName != 'list') {
												$l = $this->createElement('list');
												$k->appendChild($l);
											}
											$list = &$l;
										}
										// Append the new item to the list
										$listItem = $this->createElement('list-item');
										$listItem->appendChild($jatsPar);
										$jatsPar->setContent();
										$list->appendChild($listItem);
										// TODO find a way to do this only when needed
										// Update list type to ensure that it's correct due to chunks
										$list->setAttribute("list-type", $type);
									}
									$isPrevNodeList = true;
								}
							}
						}
						break;
					case "docx2jats\objectModel\body\Table":
						foreach ($sectionsOrBody as $section) {
							if ($contentId === $section->getAttribute('id') || $section->nodeName === "body") {
								$table = new JatsTable($content);
								$section->appendChild($table);
								$table->setContent();

							}
						}
						break;
					case "docx2jats\objectModel\body\Image":
						foreach ($sectionsOrBody as $section) {
							if ($contentId === $section->getAttribute('id') || $section->nodeName === "body") {
								$figure = new JatsFigure($content);
								$section->appendChild($figure);
								$figure->setContent();
							}
						}
						break;
				}
				$isPrevNodeList = (in_array(Par::DOCX_PAR_LIST, $content->getType()) && !in_array(Par::DOCX_PAR_HEADING, $content->getType()));
			}
		}
	}

	/*
	 * @brief MS Word output leaves empty nodes, normalize the final document
	 * elements with attribute and empty table cells should be left; empty table rows can be deleted as do not have semantic meaning
	 */

	private function cleanContent(): void {
		$xpath = new \DOMXPath($this);
		$nodesToRemove = $xpath->query("//body//*[not(normalize-space()) and not(.//@*) and not(self::td)]");
		foreach ($nodesToRemove as $nodeToRemove) {
			$nodeToRemove->parentNode->removeChild($nodeToRemove);
		}
	}

	private function extractMetadata() {
		//TODO find and extract OOXML metadata

		// Needed to make JATS XML document valid
		$journalMetaNode = $this->createElement("journal-meta");
		$this->front->appendChild($journalMetaNode);
		$journalIdNode = $this->createElement("journal-id");
		$journalMetaNode->appendChild($journalIdNode);
		$issnNode = $this->createElement("issn");
		$journalMetaNode->appendChild($issnNode);

		$articleMetaNode = $this->createElement("article-meta");
		$this->front->appendChild($articleMetaNode);
		$titleGroupNode = $this->createElement("title-group");
		$articleMetaNode->appendChild($titleGroupNode);
		$articleTitleNode = $this->createElement("article-title");
		$titleGroupNode->appendChild($articleTitleNode);
	}

	private function extractReferences() : void {
		$document = $this->docxArchive->getDocument();
		$references = $document->getReferences();
		if (empty($references)) return;

		$refList = $this->createElement('ref-list');
		$this->back->appendChild($refList);
		foreach ($references as $reference) {
			$referenceEl = new Reference();
			$refList->appendChild($referenceEl);
			$referenceEl->setContent($reference);
		}
	}
}
