<?php namespace docx2jats\jats\traits;

use docx2jats\jats\Figure as JatsFigure;
use docx2jats\jats\Par as JatsPar;
use docx2jats\jats\Table as JatsTable;
use docx2jats\objectModel\body\Image;
use docx2jats\objectModel\body\Par;
use docx2jats\objectModel\body\Table;
use docx2jats\objectModel\DataObject;
use DOMElement;

// ooxml list types to jats types translation
const JATS_LIST_TYPES = [
    Par::DOCX_LIST_TYPE_SIMPLE => 'simple',
    Par::DOCX_LIST_TYPE_UNORDERED => 'bullet',
    Par::DOCX_LIST_TYPE_ORDERED => 'order',
    Par::DOCX_LIST_TYPE_ALPHA_LOWER => 'alpha-lower',
    Par::DOCX_LIST_TYPE_ALPHA_UPPER => 'alpha-upper',
    Par::DOCX_LIST_TYPE_ROMAN_LOWER => 'lower-roman',
    Par::DOCX_LIST_TYPE_ROMAN_UPPER => 'upper-roman',
];

/**
 * trait Container
 * Trait for elements which can act as container of other elements like a Ddocument or Table Cell.
 */
trait Container
{
    protected $debug = true;

    /** @var bool Indicates wheter to start or not a new list, use by split-lists (chunks) */
    private $startList = true;

    /** @var DOMElement[] It doesn't serve any purpose but keep track of all list */
    private $lists = [];

    /** @var array[] All lists with their chunks */
    private $listChunks = [];

    /** @var string[] Keep track of list types by level, useful in chunks */
    private $listLvlTypes = [];

    /**
     * Creates paragraphs, lists, images and tables and append them to the parent node.
     * @param DataObject $content With the content to be appended
     * @param DOMElement $parent If null, this will be the parent
     * @param string $pid Parent id, if null it will try to get it from parent's attribute id
     */
    public function appendContent(DataObject $content, DOMElement $parent = null, string $pid = null) {
        if ($this->debug) printf('['.__CLASS__.'::'.__FUNCTION__.'] ('.get_class($parent).') content type '.get_class($content));

        // Set parent and its id from attribute id if pid is not set
        if (! $parent) {
            $parent = $this;
        }
        if (! $pid) {
            $pid = $parent->getAttribute('id');
        }

        switch (get_class($content)) {
            // Paragraphs and lists
            case "docx2jats\objectModel\body\Par":
                /** @var Par $content */
                $par = new JatsPar($content);

                // Paragraphs
                if (!in_array(Par::DOCX_PAR_LIST, $content->getType())) {
                    if ($this->debug) printf(" paragraph\n");

                    $parent->appendChild($par);
                    $par->setContent();
                    $this->startList = true;
                }
                // Lists
                else if (!in_array(Par::DOCX_PAR_HEADING, $content->getType())) {
                    if ($this->debug) printf(" list\n");

                    // Set the document so it can create nodes
                    $doc = is_a($this, '\DOMDocument') ? $this : $parent->ownerDocument;

                    $nid = $content->getNumberingId();
                    $lvl = $content->getNumberingLevel()+1;
                    $iid = count($content->getNumberingItemProp()[Par::DOCX_LIST_ITEM_ID]);
                    $id = sprintf("%s-lst-%d", $pid, $nid);

                    // New list
                    if (! array_key_exists($id, $this->listChunks)) {
                        $this->listChunks[$id] = [];
                        $this->startList = true;
                    }
                    // New chunk
                    if ($this->startList) {
                        $chunk = count($this->listChunks[$id]);
                        $list = $doc->createElement('list');
                        $list->setAttribute('id', $id.'_'.$chunk);
                        //$list->setAttribute("list-type", self::JATS_LIST_TYPES[$content->getNumberingType()]);
                        $parent->appendChild($list);
                        $this->listChunks[$id][$chunk] = &$list;
                        $this->lists[$id.'_'.$chunk] = &$list;
                    // Chunk foundself::$JATS_LIST_TYPES
                    } else {
                        $chunk = count($this->listChunks[$id]) - 1;
                        $list = &$this->listChunks[$id][$chunk];
                    }
                    // Update latest list types so they are available to other chunks where the first item is in a sublist
                    $type = $this->listLvlTypes[$id][$lvl] = JATS_LIST_TYPES[$content->getNumberingType()];

                    // TODO what is this comparison?
                    if ($iid === $lvl) {
                        // Search/Create sublists
                        for ($i=1; $i < $lvl; $i++) {
                            // Propagate list type useful in chunks when the first item is a sublist
                            if (isset($this->listLvlTypes[$id][$i]))
                                $list->setAttribute('list-type', $this->listLvlTypes[$id][$i]);
                            // Get sublist from last node
                            $k = &$list->lastChild;
                            if ($k == null) {
                                $k = $doc->createElement('list-item');
                                $list->appendChild($k);
                            }
                            $l = &$k->lastChild;
                            // Create it otherwhise
                            if ($l == null || $l->nodeName != 'list') {
                                $l = $doc->createElement('list');
                                $k->appendChild($l);
                            }
                            $list = &$l;
                        }
                        // TODO find a way to do this only when needed
                        // Update list type to ensure that it's correct due to chunks
                        $list->setAttribute("list-type", $type);

                        // Append the new item to the list
                        $listItem = $doc->createElement('list-item');
                        $listItem->appendChild($par);
                        $list->appendChild($listItem);
                        $par->setContent();
                    }
                    $this->startList = false;
                } else {
                    if ($this->debug) printf(" anything else\n");
                }
                break;
            // Tables
            case "docx2jats\objectModel\body\Table":
                /** @var Table $content */
                if ($this->debug) printf(" table\n");

                $table = new JatsTable($content);
                $parent->appendChild($table);
                $table->setContent();
                break;
            // Figures
            case "docx2jats\objectModel\body\Image":
                /** @var Image $content */
                if ($this->debug) printf(" Image\n");

                $figure = new JatsFigure($content);
                $parent->appendChild($figure);
                $figure->setContent();
                break;
            default:
                if ($this->debug) printf(" not implemented yet\n");
                $this->startList = true;
                break;
        }
    }
}

?>