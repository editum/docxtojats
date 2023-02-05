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
use docx2jats\jats\traits\Container;
use DOMDocument;

class Document extends DOMDocument {
	use Container;

	const DOCUMENT_PUBLICID = "-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.1 20151215//EN";
	const DOCUMENT_SYSTEMID = "https://jats.nlm.nih.gov/publishing/1.1/JATS-journalpublishing1.dtd";
	const ARTICLE_ATTRIBUTES = [
		'dtd-version' => '1.1',
		'specific-use' => 'sps-1.9',
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

	public function __construct(DOCXArchive $docxArchive) {
		parent::__construct('1.0', 'utf-8');
		$this->docxArchive = $docxArchive;
		$this->preserveWhiteSpace = false;
		$this->formatOutput = true;

		// Doctype
		$impl = new \DOMImplementation();
		$this->appendChild($impl->createDocumentType("article", self::DOCUMENT_PUBLICID, self::DOCUMENT_SYSTEMID));

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
		// Set language if any
		if ($lang = $this->docxArchive->getDocument()->getLanguage())
			$this->article->setAttribute('xml:lang', $lang);
		$this->article->setAttributeNS(
			"http://www.w3.org/2000/xmlns/",
			"xmlns:xlink",
			"http://www.w3.org/1999/xlink"
		);
		foreach (self::ARTICLE_ATTRIBUTES as $key => $value)
			$this->article->setAttribute($key, $value);

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
				$contentId = 'sec' . implode('_', $content->getDimensionalSectionId());

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

				// Append all content to each section
				foreach ($sectionsOrBody as $section) {
					if ($contentId === $section->getAttribute('id') || $section->nodeName === "body") {
						$this->appendContent($content, $section);
					}
				}
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
		$document = $this->docxArchive->getDocument();

		// In order to be a valid JATS xml the tags must be in the required order and some are obligatory
		$journalMetaNode = $this->createElement('journal-meta');
		$this->front->appendChild($journalMetaNode);
		$journalIdNode = $this->createElement('journal-id');
		$journalMetaNode->appendChild($journalIdNode);
		$issnNode = $this->createElement('issn');
		$journalMetaNode->appendChild($issnNode);

		// Add metadata
		$articleMetaNode = $this->createElement('article-meta');
		$this->front->appendChild($articleMetaNode);
		//$articleMetaNode->appendChild($this->createElement('article-id'));

		// Add version if any
		if ($version = $document->getRevision())
			$articleMetaNode->appendChild($this->createElement('article-version', $version));

		// Add title
		$titleGroupNode = $this->createElement('title-group');
		$articleMetaNode->appendChild($titleGroupNode);
		$titleGroupNode->appendChild($this->createElement('article-title', $document->getTitle()));

		// Add subtitle if any
		if ($subtitle = $document->getSubject())
			$titleGroupNode->appendChild($this->createElement('subtitle', $subtitle));

		$articleMetaNode->appendChild($this->createElement('permissions'));

		// Add abstract if any
		if ($description = $document->getDescription()) {
			$abstractNode = $this->createElement('abstract');
			$abstractNode->appendChild($this->createElement('p', $description));
			$articleMetaNode->appendChild($abstractNode);
		}
		// Add keyowrds if any
		if ($keywords = $document->getKeywords()) {
			$kwdGroup = $this->createElement('kwd-group');
			$kwdGroup->setAttribute('kwd-group-type', 'author');
			$kwdGroup->appendChild($this->createElement('unstructured-kwd-group', $keywords));
			$articleMetaNode->appendChild($kwdGroup);
		}

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
