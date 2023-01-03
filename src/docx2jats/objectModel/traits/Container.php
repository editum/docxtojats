<?php namespace docx2jats\objectModel\traits;

use docx2jats\objectModel\body\Image;
use docx2jats\objectModel\body\Par;
use docx2jats\objectModel\body\Reference;
use docx2jats\objectModel\body\Table;
use docx2jats\objectModel\Document;
use DOMElement;
use DOMXPath;

trait Container
{
	private $elsHavefldCharRefs = [];
	private $elsAreTables = [];
	private $elsAreFigures = [];
	private $currentFigureId = 1;
	private $currentTableId = 1;

	/**
	 * @param $childNode
	 * @return bool
	 * @brief determines if an element is caption
	 */
	public function isCaption($childNode): bool
	{
		$elementStyle = $this->getXpath()->query("w:pPr/w:pStyle/@w:val", $childNode)[0];
		if (is_null($elementStyle)) 
            return false;
		elseif (Document::getBuiltinStyle(Document::DOCX_STYLES_PARAGRAPH, $elementStyle->nodeValue, Table::$caption))
			return true;
        else
		    return false;
	}

    public function isDrawing($childNode): bool
	{
		$element = $this->getXpath()->query("w:r//w:drawing", $childNode)[0];
		if ($element) 
            return true;
        else
		    return false;
	}

	abstract function getOwnerDocument(): ?Document;
	abstract function getXpath(): DOMXPath;

    public function addRefence(Reference $reference)
	{
        $this->getOwnerDocument()->addReference($reference);
    }

    private function setContent(DOMElement $node): array
    {
        $unUsedCaption = null;
        $content = [];

        /** @var DOMElement $childNode */
        foreach ($node->childNodes as $childNode) {
            switch ($childNode->nodeName) {
				case "w:p":
					/**
					 * TODO add support for other drawings type, e.g., c:chart
					 * Figures are contained inside paragraphs, particularly - in text runs;
					 * there may be several images each inside own text run.
					 * In addition, LibreOffice Writer's DOCX export includes 2 duplicates of drawings for compatibility reasons
					 */
					if ($this->isDrawing($childNode)) {
						$drawingEls = null;
						$textRuns = $this->getXpath()->query('w:r', $childNode);
						foreach ($textRuns as $textRun) {
							// Retrieve only first one (LibreOffice Writer duplicates with a fallback option
							$checkDrawingEl = $this->getXpath()->query('.//w:drawing[1]', $textRun)[0];
							if ($checkDrawingEl) $drawingEls[] = $checkDrawingEl;
						}
						if (empty($drawingEls)) break;

						foreach ($drawingEls as $drawingEl) {
							// check if contains image, charts aren't supported
							$this->getXpath()->registerNamespace("pic", "http://schemas.openxmlformats.org/drawingml/2006/picture");
							$imageNodes = $this->getXpath()->query(".//pic:pic", $drawingEl);
							if ($imageNodes->length === 0) break;

							$figure = new Image($drawingEl, $this->getOwnerDocument());
							$content[] = $figure;

							// Get coordinates for this figure
							$this->elsAreFigures[] = count($content) - 1;

							// Set unique ID
							$figure->setFigureId($this->currentFigureId++);

							// Set caption if exists
							if ($unUsedCaption) {
								$figure->setCaption($unUsedCaption);
								$unUsedCaption = null;
							}
						}

					} elseif ($this->isCaption($childNode)) {
						// Check if previous node is drawing or table
						if (!empty($content)) { // may be empty if caption is the first element
							$prevObject =& $content[array_key_last($content)];
						}

						if (isset($prevObject) && (get_class($prevObject) === 'docx2jats\objectModel\body\Table' || get_class($prevObject) === 'docx2jats\objectModel\body\Image')) {
							$prevObject->setCaption($childNode);
						} else {
							$unUsedCaption = $childNode;
						}
					} else {
						$par = new Par($childNode, $this->getOwnerDocument(), $this !== $this->getOwnerDocument() ? $this : null);
						if (in_array(Par::DOCX_PAR_REF, $par->getType())) {
							if (!empty(trim($par->toString()))) {
								$reference = new Reference($par->toString());
								$this->addReference($reference);
							}
						} else {
							$content[] = $par;
						}

						if ($par->hasBookmarks) {
							$this->elsHavefldCharRefs[] = count($content)-1;
						}
					}
					break;
				case "w:tbl":
					$table = new Table($childNode, $this->getOwnerDocument());
					$content[] = $table;
					$this->elsAreTables[] = count($content)-1;

					// Set unique ID
					$table->setTableId($this->currentTableId++);
					// Set caption if exists
					if ($unUsedCaption) {
						$table->setCaption($unUsedCaption);
						$unUsedCaption = null;
					}
					break;
            }
        }

        return $content;
    }
}
?>
