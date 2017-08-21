<?php

/**
 * PDFCreate Plugin - creates OCR'd PDFs from TIFFs
 *
 * @author Joe Corall <jcorall@kent.edu>
 *
 * @todo integrate with Google’s Cloud Vision API to allow alternative to tesseract req
 * @todo consider deleting individual OCR'd PDFs after aggregated PDF is created - maybe make it an admin UI setting?
 * @todo add link to PDF in Files
 * @todo possibly see if the TIFF has any natural language in the OCR before adding the OCR text to the metadata???
 * @todo allow admin to specify criteria on what TIFFs to create PDFs from based on a metadata search
 */

define('PDF_CREATE_OCR_DIR', FILES_DIR . DIRECTORY_SEPARATOR . 'ocr');
define('PDF_CREATE_PDF_DIR', FILES_DIR . DIRECTORY_SEPARATOR . 'pdfs' );

class PDFCreatePlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
        'after_delete_item',
        'after_delete_file',
        'after_save_item',
        'install',
    );

    public function hookInstall()
    {
        // create directories to store PDFs
        mkdir(PDF_CREATE_OCR_DIR);
        mkdir(PDF_CREATE_PDF_DIR);
    }

    public function hookAfterSaveItem($args)
    {
        $item = $args['record'];

        // after saving an item send a background job to generate any PDFs needed
        $jobDispatcher = Zend_Registry::get('job_dispatcher');
        $jobDispatcher->setQueueNameLongRunning('pdfcreate_ocr');
        try {
            $options = array(
                // don't want to rely on an item object getting passed into the separate job/thread
                // so just send the item ID and the job will load the item
                'item_id' => $item->id
            );

            $jobDispatcher->sendLongRunning('PDFCreate_OCR', $options);

        } catch (Exception $e) {
            throw $e;
        }
    }

    public function hookAfterDeleteFile($args)
    {
        $file = $args['record'];

        $ocr_file = self::get_ocr_path($file);

        if (file_exists($ocr_file)) {
            unlink($ocr_file);
            $pdf = PDF_CREATE_PDF_DIR . DIRECTORY_SEPARATOR . $file->item_id . '.pdf';
            if (file_exists($pdf)) {
                unlink($pdf);
            }
        }
    }

    public function hookAfterDeleteItem($args)
    {
        $item = $args['record'];

        // get the directory where the OCR'd PDFs for all the files for this item might be stored
        $ocr_dir = PDF_CREATE_OCR_DIR . DIRECTORY_SEPARATOR . $item->id;

        // if the directory exists delete all the files in the OCR directory
        if (file_exists($ocr_dir)) {
            array_map('unlink', glob("$ocr_dir/*.pdf"));
            array_map('unlink', glob("$ocr_dir/*.txt"));
            rmdir($ocr_dir);

            // also remove the aggregated PDF if it exists
            $pdf = PDF_CREATE_PDF_DIR . DIRECTORY_SEPARATOR . $item->id . '.pdf';
            if (file_exists($pdf)) {
                unlink($pdf);
            }
        }
    }

    public function get_ocr_path($file)
    {
        // OCR PDF for a TIFF file is stored in a directory named after the item ID of the file
        $ocr_dir = PDF_CREATE_OCR_DIR . DIRECTORY_SEPARATOR . $file->item_id;

        // the PDF filename is the original name of the file with a ".PDF" extension instead of ".TIFF"
        $original_file = explode('.', $file->original_filename);
        $ocr_file = $ocr_dir . DIRECTORY_SEPARATOR . array_shift($original_file) . '.pdf';

        return $ocr_file;
    }
}
