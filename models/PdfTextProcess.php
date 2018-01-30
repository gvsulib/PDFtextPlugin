<?php
/**
 * PDF Text
 * 
 * @copyright Copyright 2007-2012 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 */

/**
 * @package Omeka\Plugins\PdfText
 */
class PdfTextProcess extends Omeka_Job_AbstractJob
{
    /**
     * Process all PDF files in Omeka.
     */
    public function perform()

    {
        
        $pdfTextLocalPlugin = new PdfTextLocalPlugin;
        $fileTable = $this->_db->getTable('File');

        $itemTable = $this->_db->getTable('Item');

        //first make sure we delete all existing text capture metadata, at both the item and file levels
        //because of the haphazard way the ingest is attaching text capture metadata, I can't be sure where the text capture
        //is positioned for any given file without explictily checking,
        //so I just attempt to delete the metadata for every file at both item and file level.
        
        //get all PDF files
        $selectFiles = $this->_db->select()
        ->from($this->_db->File)
        ->where('mime_type IN (?)', $pdfTextPlugin->getPdfMimeTypes());

        //now cycle through them, wiping text capture wherever it is
        $pageNumber = 1;
        while ($files = $fileTable->fetchObjects($selectFiles->limitPage($pageNumber, 50))) {
            foreach ($files as $file) {
                $textElement = $file->getElement(
                    PdfTextPlugin::ELEMENT_SET_NAME,
                    PdfTextPlugin::ELEMENT_NAME
                );
                $file->deleteElementTextsByElementId(array($textElement->id));
                $selectItem = $this->_db->select()
                    ->from($this->_db->Item)
                    ->where("id = ?", $file->item_id);
                $Item = $itemTable->fetchObject($selectItem);
                $textElement = $Item->getElement(
                    PdfTextPlugin::ELEMENT_SET_NAME,
                    PdfTextPlugin::ELEMENT_NAME
                );
                $Item->deleteElementTextsByElementId(array($textElement->id));
                $file->save();
                $Item->save();
                release_object($file);
                release_object($Item);

            }
            $pageNumber++;
        }


        //get list of items that have multiple PDFs attached by counting item_ids

        $select_multiples = $this->_db->select()
            ->from($this->_db->File, 'item_id')
            ->where('mime_type IN (?)', $pdfTextPlugin->getPdfMimeTypes())
            ->group('item_id')
            ->having('COUNT(item_id) > 1');

        $stmt = $select_multiples->query();

        $multiples = $stmt->fetchAll();

        //clean up the resulting array a bit so I can pass it to another query

        $cleanupArray = array();
        foreach ($multiples as $entry) {
            $cleanupArray[] = $entry["item_id"];

        }

        $multiples = $cleanupArray;
        
        //now get all the files whose itemIds have been identified in the first list

        $selectMultipleFiles = $this->_db->select()
        ->from($this->_db->File)
        ->where('item_id IN (?)', $multiples);
        
        //attach text capture metadata to the file level for items that have multiple pdf documents

        $pageNumber = 1;
        while ($files = $fileTable->fetchObjects($selectMultipleFiles->limitPage($pageNumber, 50))) {
            foreach ($files as $file) {

                // Delete any existing PDF text element texts from the file.
                $textElement = $file->getElement(
                    PdfTextPlugin::ELEMENT_SET_NAME,
                    PdfTextPlugin::ELEMENT_NAME
                );
                

                // Extract the PDF text and add it to the file.
                $file->addTextForElement(
                    $textElement,
                    $pdfTextPlugin->pdfToText(FILES_DIR . '/original/' . $file->filename)
                );
                $file->save();

                // Prevent memory leaks.
                release_object($file);
            }
            $pageNumber++;
        }
        
        
        //now get items that have only one PDF file-we are going to attach that text capture to the item level

        $select_singles = $this->_db->select()
            ->from($this->_db->File, 'item_id')
            ->where('mime_type IN (?)', $pdfTextPlugin->getPdfMimeTypes())
            ->group('item_id')
            ->having('COUNT(item_id) = 1');

        $stmt = $select_singles->query();

        $singles = $stmt->fetchAll();

        //clean up the resulting array a bit so I can iterate through it easily

        $cleanupArray = array();
        foreach ($singles as $entry) {
            $cleanupArray[] = $entry["item_id"];
        
        }
        $singles = $cleanupArray;

        //now iterate through the list, grabbing the items files and finding the one that's a PDF, 
        //generate text capture for that file, and attach it at the item level.
        foreach ($singles as $itemID) {

            $selectItemRecord = $this->_db->select()
                ->from($this->_db->Item)
                ->where('id = (?)', $itemID);

            $Item = $itemTable->fetchObject($selectItemRecord);

            $textElement = $Item->getElement(
                PdfTextPlugin::ELEMENT_SET_NAME,
                PdfTextPlugin::ELEMENT_NAME
            );
            
            

            $files = $Item->getFiles();

            $fileTypes = $pdfTextPlugin->getPdfMimeTypes();

            foreach($files as $file) {
                if (in_array($file->mime_type, $fileTypes)) {
                    $Item->addTextForElement(
                        $textElement,
                        $pdfTextPlugin->pdfToText(FILES_DIR . '/original/' . $file->filename)
                    );
                    

                }
                release_object($file);
            }
            $Item->save();
            release_object($Item);
        }


       
        
    }
}
